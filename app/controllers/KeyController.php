<?php
/**
 * KeyController.php - the admin "Key" page.
 *
 * Admins store an API key here. The secret is encrypted at rest, shown only as
 * a mask, never editable, and revoke (delete) is the only action. Admins cannot
 * run the AI - this page replaces the chat for them.
 */
declare(strict_types=1);

class KeyController
{
    /** GET ?page=key */
    public function index(): void
    {
        $admin = require_admin();

        view('key/index', [
            'title'     => 'Key',
            'user'      => $admin,
            'keys'      => (new ApiKey())->all(),
            'settings'  => (new Setting())->all(),
            'encrypted' => crypto_available(),
            'stats'     => [
                'keys'      => (new ApiKey())->count(),
                'settings'  => count(array_filter((new Setting())->all(), fn ($s) => $s['set'])),
                'messages'  => (new ChatMessage())->countAll(),
                'admins'    => (new User())->countByRole('admin'),
            ],
            'sessions'  => (new ChatSession())->allSessions(20),
        ]);
    }

    /**
     * POST ?page=key&action=save_setting - store one config value.
     *
     * One endpoint for every key in Setting::SCHEMA, so the panel adapts to a
     * new key by adding a row there - no new route or method per key.
     *
     * Empty-vs-clear: an empty value is treated as "no change" for a key that
     * already has a value, because a secret renders as an empty password input
     * (only the mask shows as the placeholder). Wiping a saved credential must
     * be explicit, via name="clear". That is what stops a well-meaning "Save"
     * on an untouched field from destroying a working integration.
     */
    public function saveSetting(): void
    {
        $admin = require_admin();
        $key   = (string) ($_POST['key'] ?? '');
        $value = trim((string) ($_POST['value'] ?? ''));
        $clear = isset($_POST['clear']);

        if (!isset(Setting::SCHEMA[$key])) {
            if (wants_json()) {
                json_response(false, [], 'Unknown setting.', 404);
            }
            flash('error', 'Unknown setting.');
            redirect(url('key'));
        }

        $label    = Setting::SCHEMA[$key]['label'];
        $settings = new Setting();

        // Explicit clear: drop the row and say so.
        if ($clear) {
            $error = $settings->put($key, '', (int) $admin['id']);
            if ($error !== '') {
                if (wants_json()) {
                    json_response(false, ['errors' => ['value' => $error]], $error, 422);
                }
                flash('error', $error);
                redirect(url('key'));
            }
            respond_ok($label . ' cleared.', url('key'));
        }

        // Nothing typed and something is already saved: leave it alone.
        if ($value === '' && $settings->isSet($key)) {
            respond_ok($label . ' unchanged.', url('key'));
        }

        $error = $settings->put($key, $value, (int) $admin['id']);
        if ($error !== '') {
            if (wants_json()) {
                json_response(false, ['errors' => ['value' => $error]], $error, 422);
            }
            flash('error', $error);
            redirect(url('key'));
        }

        // An empty value with nothing saved is a no-op, not a "clear".
        $verb = $value === '' ? 'has no value to save.' : 'saved.';
        respond_ok($label . ' ' . $verb, url('key'));
    }

    /** POST ?page=key&action=store - add a key (label + secret). */
    public function store(): void
    {
        $admin  = require_admin();
        $label  = trim((string) ($_POST['label'] ?? ''));
        $secret = trim((string) ($_POST['secret'] ?? ''));
        $errors = [];

        if ($label === '' || mb_strlen($label) > 100) {
            $errors['label'] = 'Give the key a label under 100 characters.';
        }
        if (mb_strlen($secret) < 8) {
            $errors['secret'] = 'That does not look like a key (at least 8 characters).';
        }
        if (!crypto_available()) {
            $errors['secret'] = 'Server encryption is not configured (APP_KEY missing).';
        }

        if ($errors !== []) {
            if (wants_json()) {
                json_response(false, ['errors' => $errors], 'Please fix the highlighted fields.', 422);
            }
            flash('error', implode(' ', $errors));
            redirect(url('key'));
        }

        [$id, $error] = (new ApiKey())->create($label, $secret, (int) $admin['id']);
        if ($id === 0) {
            if (wants_json()) {
                json_response(false, ['errors' => ['secret' => $error]], $error, 422);
            }
            flash('error', $error);
            redirect(url('key'));
        }

        respond_ok('Key "' . $label . '" added. The secret is stored encrypted.', url('key'));
    }

    /** POST ?page=key&action=revoke - delete a key. The only way to replace it. */
    public function revoke(): void
    {
        require_admin();
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0 || !(new ApiKey())->delete($id)) {
            if (wants_json()) {
                json_response(false, [], 'That key no longer exists.', 404);
            }
            flash('error', 'That key no longer exists.');
            redirect(url('key'));
        }

        respond_ok('Key revoked.', url('key'));
    }

    /** GET ?page=key&action=chat&id=N - read-only view of one user's thread. */
    public function chat(): void
    {
        require_admin();
        $id = (int) ($_GET['id'] ?? 0);

        $session = $id > 0 ? (new ChatSession())->findAny($id) : null;
        if ($session === null) {
            abort(404, 'No chat session with that id.');
        }

        view('key/chat', [
            'title'    => 'Chat review',
            'user'     => require_admin(),
            'session'  => $session,
            'messages' => (new ChatMessage())->forSession($id),
        ]);
    }
}
