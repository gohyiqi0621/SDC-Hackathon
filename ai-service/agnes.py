"""
agnes.py - the Agnes AI client.

Agnes exposes an OpenAI-compatible gateway, so the standard `openai` SDK works
with nothing but a `base_url` change. This module is the ONLY place that knows
about Agnes: swapping provider later means editing this file alone.

Docs / model catalogue: https://github.com/AgnesAI-Labs/AgnesAI-Models
    Base URL  https://apihub.agnes-ai.com/v1
    Endpoint  POST /chat/completions
    Auth      Authorization: Bearer <key>

The API key is deliberately NOT read from the environment here. PHP owns the
database, decrypts the stored key, and passes it in the request body, so this
service stays stateless and holds no credentials between calls.

Self-check (no network, no key needed):
    python agnes.py
"""

from __future__ import annotations

import os

from openai import OpenAI

BASE_URL = "https://apihub.agnes-ai.com/v1"

# Verified against the published model catalogue: agnes-2.5-flash is the current
# text / reasoning model (agnes-2.0-flash is the previous one). Override with
# AGNES_MODEL only if the account exposes a different identifier.
MODEL = os.environ.get("AGNES_MODEL", "agnes-2.5-flash")

TIMEOUT_SECONDS = 60.0
MAX_OUTPUT_TOKENS = 1024


class AgnesError(Exception):
    """A failure whose message is safe to show a user. Never carries the key."""

    def __init__(self, message: str, status: int = 502) -> None:
        super().__init__(message)
        self.message = message
        self.status = status


def _error_from(exc: Exception) -> AgnesError:
    """
    Map an SDK exception onto a safe message + HTTP status.

    The upstream message is never forwarded: it can echo the request, and the
    request carries the API key. Only the status code is used.
    """
    code = getattr(exc, "status_code", None)
    name = type(exc).__name__.lower()

    if code == 401 or code == 403:
        return AgnesError("Agnes AI rejected the API key. Please contact your administrator.", 502)
    if code == 429:
        return AgnesError("Agnes AI is rate limiting requests. Please try again shortly.", 429)
    if code == 400:
        return AgnesError("Agnes AI could not process that message. Try rephrasing it.", 400)
    if "timeout" in name:
        return AgnesError("Agnes AI did not respond in time. Please try again.", 504)
    if "connection" in name:
        return AgnesError("Could not reach Agnes AI. Please try again.", 502)
    if code:
        return AgnesError(f"Agnes AI returned an error (HTTP {code}). Please try again.", 502)
    return AgnesError("The Agnes AI request failed. Please try again.", 502)


def complete(api_key: str, messages: list[dict]) -> str:
    """
    Send a conversation and return the assistant's reply text.

    Raises AgnesError (never a bare exception, never a fabricated reply) so the
    caller can turn it into a JSON error and the UI can say what actually failed.
    """
    if not api_key:
        raise AgnesError(
            "Agnes AI is not configured yet. Please contact your administrator.", 503
        )

    client = OpenAI(api_key=api_key, base_url=BASE_URL, timeout=TIMEOUT_SECONDS)

    try:
        response = client.chat.completions.create(
            model=MODEL,
            messages=messages,
            max_tokens=MAX_OUTPUT_TOKENS,
        )
    except Exception as exc:  # noqa: BLE001 - mapped to a safe AgnesError below
        raise _error_from(exc) from exc

    choices = getattr(response, "choices", None) or []
    if not choices:
        raise AgnesError("Agnes AI returned an empty response. Please try again.", 502)

    content = getattr(choices[0].message, "content", None)
    if not isinstance(content, str) or not content.strip():
        raise AgnesError("Agnes AI returned an empty response. Please try again.", 502)

    return content.strip()


def _self_check() -> None:
    """Assert-based check of the offline logic. Run: python agnes.py"""
    class _Fake(Exception):
        def __init__(self, status_code):
            super().__init__("upstream detail that must never reach a user")
            self.status_code = status_code

    assert _error_from(_Fake(401)).status == 502
    assert "rejected the API key" in _error_from(_Fake(401)).message
    assert _error_from(_Fake(429)).status == 429
    assert _error_from(_Fake(400)).status == 400
    assert _error_from(_Fake(500)).status == 502

    # A missing key must be reported as unconfigured, not attempted.
    try:
        complete("", [{"role": "user", "content": "hi"}])
        raise AssertionError("empty key should raise")
    except AgnesError as exc:
        assert exc.status == 503, exc.status
        assert "not configured" in exc.message

    # The upstream message must never be forwarded verbatim.
    for code in (401, 429, 400, 500):
        assert "upstream detail" not in _error_from(_Fake(code)).message

    assert MODEL and "/" not in MODEL
    assert BASE_URL.endswith("/v1")
    print("agnes.py self-check: all assertions passed")


if __name__ == "__main__":
    _self_check()
