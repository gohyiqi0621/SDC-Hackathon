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

    /**
     * POST ?page=key&action=save_all - store every integration field at once.
     *
     * The Key page is one form with one Save button, so this is what the button
     * hits. Same empty-vs-clear rule as saveSetting(): a blank field on a key
     * that already holds a value is "no change", never a wipe. Clearing stays an
     * explicit per-key action.
     *
     * Values arrive as settings[<key>], and an unknown key is simply ignored -
     * the loop is driven by Setting::SCHEMA, not by the request, so a crafted
     * field name cannot reach a policy row or an arbitrary setting.
     */
    public function saveAll(): void
    {
        $admin    = require_admin();
        $settings = new Setting();

        $input = $_POST['settings'] ?? [];
        if (!is_array($input)) {
            $input = [];
        }

        $saved  = 0;
        $errors = [];

        foreach (Setting::SCHEMA as $key => $meta) {
            if (($meta['group'] ?? 'keys') !== 'keys' || !array_key_exists($key, $input)) {
                continue;
            }

            $value = trim((string) $input[$key]);

            // Blank + already stored = leave it alone (secrets render empty).
            if ($value === '') {
                continue;
            }

            // A plain value echoed back unchanged is not a change.
            if (!$meta['secret'] && $settings->plain($key) === $value) {
                continue;
            }

            $error = $settings->put($key, $value, (int) $admin['id']);
            if ($error !== '') {
                $errors['settings[' . $key . ']'] = $error;
                continue;
            }
            $saved++;
        }

        if ($errors !== []) {
            $message = implode(' ', array_unique($errors));
            if (wants_json()) {
                json_response(false, ['errors' => $errors], $message, 422);
            }
            flash('error', $message);
            redirect(url('key'));
        }

        $message = $saved === 0
            ? 'No changes to save.'
            : $saved . ($saved === 1 ? ' setting saved.' : ' settings saved.');

        // api.js navigates as soon as it sees data.redirect, which would kill the
        // toast before it renders. Stash a flash too, so the reloaded page still
        // confirms what happened.
        if (wants_json()) {
            flash('success', $message);
        }

        respond_ok($message, url('key'));
    }

    /**
     * POST ?page=key&action=test_mail - send one real email through Brevo.
     *
     * Brevo's failure modes are all invisible from the browser (wrong key, an
     * unverified sender, an IP allow-list on the account), and they all look
     * like "the reset email never arrived". This button turns that into a
     * readable answer, and mails the configured sender address itself so it
     * also proves the sender is the one that works.
     */
    public function testMail(): void
    {
        require_admin();

        $fail = function (string $message, int $code = 422): void {
            if (wants_json()) {
                json_response(false, [], $message, $code);
            }
            flash('error', $message);
            redirect(url('key'));
        };

        if (!brevo_configured()) {
            $fail('Add the API key and a sender address first.');
        }

        $html = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;'
            . 'max-width:480px;margin:0 auto;padding:28px;background:#fff;border-radius:16px;">'
            . '<h1 style="margin:0 0 12px;font-size:20px;color:#14181c;">Brevo is connected</h1>'
            . '<p style="margin:0;font-size:15px;line-height:1.6;color:#4a5259;">'
            . 'If you can read this, the Key page credentials work and password-reset codes can be delivered.'
            . '</p></div>';

        $res = brevo_send(
            BREVO_SENDER_EMAIL,
            BREVO_SENDER_NAME !== '' ? BREVO_SENDER_NAME : APP_NAME,
            'Brevo connection test - ' . APP_NAME,
            $html,
            'If you can read this, the Key page credentials work and password-reset codes can be delivered.'
        );

        if (!$res['ok']) {
            // Brevo's own words are safe to paraphrase but never to echo: they
            // can name the account or the rejected sender.
            $fail(match ($res['error']) {
                'unauthorized' => 'Brevo rejected the API key. Check the key, and check whether the account '
                    . 'restricts API keys to an IP allow-list.',
                'transport'    => 'Could not reach Brevo. Check this server\'s outbound HTTPS connection.',
                default        => 'Brevo rejected the message (' . (string) $res['error'] . '). '
                    . 'See storage/logs/mail.log for its reason.',
            });
        }

        $message = 'Test email sent to ' . BREVO_SENDER_EMAIL . '. Check that inbox (and the spam folder).';

        // No redirect: api.js would navigate away and kill the toast that
        // carries the result, which is the whole point of this button.
        if (wants_json()) {
            json_response(true, [], $message);
        }
        flash('success', $message);
        redirect(url('key'));
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
