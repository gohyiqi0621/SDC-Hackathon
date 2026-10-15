<?php
/**
 * AiController.php - the user-facing AI chat.
 *
 * PHP never does the "thinking": it stores the message, hands it to the Python
 * service, stores the reply, and returns the standard JSON envelope. Admins have
 * no run-AI function - they get the Key page instead (see KeyController).
 */
declare(strict_types=1);

class AiController
{
    /** Must match MAX_TEXT_CHARS in ai-service/app.py. */
    private const MAX_TEXT = 20000;

    /** GET ?page=ai - the chat screen with the current thread. */
    public function index(): void
    {
        $user = require_login();

        // Admins do not run AI; send them to the Key page.
        if (is_admin()) {
            redirect(url('key'));
        }

        $sessions = new ChatSession();
        $session  = $sessions->currentForUser((int) $user['id']);

        view('ai/panel', [
            'title'    => 'AI Chat',
            'user'     => $user,
            'online'   => $this->serviceOnline(),
            'session'  => $session,
            'messages' => (new ChatMessage())->forSession((int) $session['id']),
            'maxText'  => self::MAX_TEXT,
        ]);
    }

    /**
     * POST ?page=ai&action=send - append a user message and get a reply.
     * Called by api.js (fetch). Expects multipart form data.
     */
    public function send(): void
    {
        $user = require_login();
        if (is_admin()) {
            json_response(false, [], 'Administrators cannot run the AI.', 403);
        }

        $body      = trim((string) ($_POST['text'] ?? ''));
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $imageName = trim((string) ($_POST['image_name'] ?? ''));

        $sessions = new ChatSession();
        $session  = $sessions->findForUser($sessionId, (int) $user['id']);
        if ($session === null) {
            // Fall back to the current thread rather than failing the send.
            $session = $sessions->currentForUser((int) $user['id']);
        }

        $errors = [];
        if ($body === '' && $imageName === '') {
            $errors['text'] = 'Type a message, or attach a photo.';
        }
        if (mb_strlen($body) > self::MAX_TEXT) {
            $errors['text'] = 'Keep the message under ' . number_format(self::MAX_TEXT) . ' characters.';
        }
        if ($errors !== []) {
            json_response(false, ['errors' => $errors], 'Please fix the highlighted fields.', 422);
        }

        $messages = new ChatMessage();

        // The stored body carries a note when a photo was attached (prototype:
        // the image itself is previewed in the browser but not persisted).
        $storedBody = $body !== '' ? $body : 'Sent a photo.';
        $messages->create((int) $session['id'], 'user', $storedBody, $imageName !== '' ? $imageName : null);

        // First user message becomes the thread title, so the list is readable.
        if ($session['title'] === 'New chat' && $body !== '') {
            $sessions->rename((int) $session['id'], mb_strimwidth($body, 0, 60, '…'));
        }

        $result = callAiService('chat', ['text' => $body !== '' ? $body : 'photo']);
        $reply  = $result['ok']
            ? (string) ($result['data']['reply'] ?? '')
            : 'Sorry - the AI service is unavailable right now. Please try again.';

        $messageId = $messages->create((int) $session['id'], 'assistant', $reply);
        $sessions->touch((int) $session['id']);

        json_response(true, [
            'session_id' => (int) $session['id'],
            'message_id' => $messageId,
            'reply'      => $reply,
            'ok'         => $result['ok'],
        ], $result['ok'] ? 'Replied.' : 'The service was offline; a placeholder was saved.');
    }

    /** GET ?page=ai&action=health - lets the chat re-check without a reload. */
    public function health(): void
    {
        require_login();
        $online = $this->serviceOnline();

        json_response($online, ['online' => $online], $online ? 'AI service is online.' : 'AI service is offline.');
    }

    /* ---------------------------------------------------------- private ---- */

    private function serviceOnline(): bool
    {
        return callAiService('health', [], 'GET')['ok'];
    }
}
