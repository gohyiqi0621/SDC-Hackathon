"""
SDC App - dummy AI service.

Flask app that the PHP side calls over HTTP. It is deliberately generic and
stateless: PHP owns the database, this service only does the "thinking".

Run it (from the project root):
    cd ai-service
    pip install -r requirements.txt
    python app.py

It listens on http://127.0.0.1:5000 and is only ever called by PHP, so CORS is
deliberately NOT enabled.

Swap the dummy logic in `predict()` for the real model when the problem
statement is revealed - the request/response contract should not change.
"""

import re
import time

from flask import Flask, jsonify, request

app = Flask(__name__)

# --- Dummy model configuration --------------------------------------------
# A tiny keyword table so the placeholder returns something that looks like a
# real classification instead of random noise. Replace all of this.
CATEGORIES = {
    "sustainability": ["recycle", "emission", "carbon", "climate", "energy", "waste", "green"],
    "water":          ["water", "river", "ocean", "rain", "sanitation", "drought"],
    "education":      ["school", "student", "learn", "teach", "literacy", "course"],
    "health":         ["health", "disease", "patient", "clinic", "nutrition", "vaccine"],
    "poverty":        ["poverty", "income", "hunger", "food", "employment", "wage"],
}

MAX_TEXT_CHARS = 20000


def classify(text: str) -> dict:
    """
    >>> REAL MODEL GOES HERE <<<

    Everything below is placeholder logic: lowercase the text, count how many
    keywords from each category appear, and return the best match. It exists so
    the whole PHP -> Flask -> PHP round trip can be built and tested before the
    real model is ready.
    """
    lowered = text.lower()
    words = re.findall(r"[a-z]+", lowered)
    word_count = len(words)

    scores = {
        name: sum(lowered.count(keyword) for keyword in keywords)
        for name, keywords in CATEGORIES.items()
    }

    best, hits = max(scores.items(), key=lambda pair: pair[1])

    if hits == 0:
        # Nothing matched: say so rather than inventing a confident answer.
        return {
            "result": (
                f"No known category matched. The text has {word_count} words and "
                f"{len(text)} characters."
            ),
            "confidence": 0.0,
            "category": "unknown",
        }

    # Confidence is a stand-in: the winning category's share of all hits,
    # scaled down a little so it never claims to be certain.
    total_hits = sum(scores.values())
    confidence = round(0.9 * (hits / total_hits), 2)

    return {
        "result": (
            f"Looks like '{best}'. Matched {hits} of {total_hits} keyword hits "
            f"across {word_count} words."
        ),
        "confidence": confidence,
        "category": best,
    }


@app.get("/health")
def health():
    """Used by the AI panel to show whether the service is reachable."""
    return jsonify({"status": "ok", "service": "sdc-ai", "version": "0.1"})


@app.post("/predict")
def predict():
    """
    Request:  { "text": "..." }
    Response: { "result": "...", "confidence": 0.0, "category": "..." }

    Validation is repeated here even though PHP validates too: this service can
    be called directly with curl while developing.
    """
    payload = request.get_json(silent=True) or {}
    text = payload.get("text")

    if not isinstance(text, str) or not text.strip():
        return jsonify({"error": "Field 'text' is required and must be a non-empty string."}), 400

    if len(text) > MAX_TEXT_CHARS:
        return jsonify({"error": f"Field 'text' must be under {MAX_TEXT_CHARS} characters."}), 400

    started = time.perf_counter()
    outcome = classify(text.strip())
    outcome["duration_ms"] = int((time.perf_counter() - started) * 1000)

    return jsonify(outcome)


def chat_reply(text: str) -> str:
    """
    >>> REAL MODEL GOES HERE <<<

    Placeholder chat reply. It reuses `classify()` so the reply reflects the
    detected category, which makes the whole chat feel alive without a model.
    Swap this for a real completion call when the model is ready.
    """
    outcome = classify(text)
    category = outcome["category"]

    if category == "unknown":
        return (
            "I'm a prototype assistant, so I can't answer that in depth yet. "
            f"You said: \"{text[:160]}\". Once a real model is connected here, "
            "this is exactly where its reply will appear."
        )

    return (
        f"That reads like a {category} topic. Here's a prototype reply: "
        f"{outcome['result']} (Connect a real model in ai-service/app.py to get a "
        "genuine answer.)"
    )


@app.post("/chat")
def chat():
    """
    Request:  { "text": "..." }
    Response: { "reply": "...", "duration_ms": 0 }
    """
    payload = request.get_json(silent=True) or {}
    text = payload.get("text")

    if not isinstance(text, str) or not text.strip():
        return jsonify({"error": "Field 'text' is required and must be a non-empty string."}), 400

    if len(text) > MAX_TEXT_CHARS:
        return jsonify({"error": f"Field 'text' must be under {MAX_TEXT_CHARS} characters."}), 400

    started = time.perf_counter()
    reply = chat_reply(text.strip())

    return jsonify({
        "reply": reply,
        "duration_ms": int((time.perf_counter() - started) * 1000),
    })


@app.errorhandler(404)
def not_found(_error):
    return jsonify({"error": "Unknown endpoint."}), 404


@app.errorhandler(405)
def not_allowed(_error):
    return jsonify({"error": "Method not allowed for this endpoint."}), 405


@app.errorhandler(500)
def server_error(_error):
    return jsonify({"error": "The AI service hit an internal error."}), 500


if __name__ == "__main__":
    # 127.0.0.1 only - the service is never exposed to the network.
    app.run(host="127.0.0.1", port=5000, debug=False)
