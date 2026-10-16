"""
SDC App - AI service.

Flask app that the PHP side calls over HTTP. It is deliberately generic and
stateless: PHP owns the database, this service only does the "thinking".

`/chat` is backed by Agnes AI (see agnes.py). `/predict` still runs the local
keyword classifier - it is a separate feature and is untouched.

Run it (from the project root):
    cd ai-service
    pip install -r requirements.txt
    python app.py

It listens on http://127.0.0.1:5000 and is only ever called by PHP, so CORS is
deliberately NOT enabled.
"""

import re
import time

from flask import Flask, jsonify, request

import agnes

app = Flask(__name__)

# How many prior turns are replayed to the model, so a long thread cannot
# overflow the context window.
HISTORY_LIMIT = 20

SYSTEM_PROMPT = (
    "You are the SDC assistant, a helpful and concise assistant inside a small "
    "web app. Answer in plain language. Use short paragraphs, and use Markdown "
    "only for lists, bold text and code. If you do not know something, say so."
)

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
    Local keyword classifier behind POST /predict.

    This is NOT the chat model - /chat calls Agnes AI. This stays as the
    offline demo path for /predict and needs no API key.
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


def _history_messages(raw) -> list[dict]:
    """
    Keep only well-formed prior turns. Anything else is dropped rather than
    forwarded, so a malformed payload cannot poison the model request.
    """
    if not isinstance(raw, list):
        return []

    turns = []
    for item in raw:
        if not isinstance(item, dict):
            continue
        role = item.get("role")
        content = item.get("content")
        if role in ("user", "assistant") and isinstance(content, str) and content.strip():
            turns.append({"role": role, "content": content[:MAX_TEXT_CHARS]})
    return turns[-HISTORY_LIMIT:]


@app.post("/chat")
def chat():
    """
    Request:  { "text": "...", "api_key": "...", "history": [{"role","content"}, ...] }
    Response: { "reply": "...", "model": "...", "duration_ms": 0 }

    `api_key` is handed over by PHP, which read and decrypted it from the
    database. It is used for this call only and never stored here.
    """
    payload = request.get_json(silent=True) or {}
    text = payload.get("text")
    api_key = payload.get("api_key")
    api_key = api_key if isinstance(api_key, str) else ""

    if not isinstance(text, str) or not text.strip():
        return jsonify({"error": "Field 'text' is required and must be a non-empty string."}), 400

    if len(text) > MAX_TEXT_CHARS:
        return jsonify({"error": f"Field 'text' must be under {MAX_TEXT_CHARS} characters."}), 400

    messages = [{"role": "system", "content": SYSTEM_PROMPT}]
    messages.extend(_history_messages(payload.get("history")))
    messages.append({"role": "user", "content": text.strip()})

    started = time.perf_counter()
    try:
        reply = agnes.complete(api_key, messages)
    except agnes.AgnesError as exc:
        # Never fabricate a reply and never fall back to another provider: the
        # real reason goes back so the UI can show it honestly.
        return jsonify({"error": exc.message}), exc.status

    return jsonify({
        "reply": reply,
        "model": agnes.MODEL,
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
