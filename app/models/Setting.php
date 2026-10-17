<?php
/**
 * Setting.php - admin-managed config values (API keys and public ids).
 *
 * A secret is sealed with AES-256-GCM (same helpers as ApiKey) and never read
 * back out; only a mask is ever queried. A non-secret (OneSignal App ID) is
 * stored in the clear because the browser needs it.
 *
 * setting() in helpers.php reads through this; .env stays the fallback so the
 * app still boots before an admin has saved anything.
 */
declare(strict_types=1);

class Setting
{
    /**
     * The keys the panels know about. Add a row here, get a field for free.
     *
     * `group` keeps the two panels apart: 'keys' feeds the Key page (credentials),
     * 'policy' feeds the Password rules card on Admin. all() filters on it, so a
     * new policy row never shows up as an API-key field.
     */
    public const SCHEMA = [
        'onesignal_app_id'  => ['group' => 'keys', 'label' => 'OneSignal App ID',        'secret' => false, 'hint' => 'Public. Safe to send to the browser.'],
        'onesignal_api_key' => ['group' => 'keys', 'label' => 'OneSignal REST API Key',  'secret' => true,  'hint' => 'Secret. Never leaves the server.'],
        'google_client_id'  => ['group' => 'keys', 'label' => 'Google Client ID',        'secret' => false, 'hint' => 'Public OAuth client id.'],
        'google_secret'     => ['group' => 'keys', 'label' => 'Google Client Secret',    'secret' => true,  'hint' => 'Secret. Used only in the token exchange.'],
        'agnes_api_key'     => ['group' => 'keys', 'label' => 'Agnes AI API Key',        'secret' => true,  'hint' => 'Secret. Used only for AI Chat replies.'],

        // Password rules. Plain values: no secret, and they are meant to be read
        // back into the form. See password_policy() in helpers.php.
        'password_min_length'      => ['group' => 'policy', 'label' => 'Minimum length',        'secret' => false, 'hint' => 'Between 6 and 72 characters.'],
        'password_require_upper'   => ['group' => 'policy', 'label' => 'Require a capital letter', 'secret' => false, 'hint' => 'A-Z'],
        'password_require_number'  => ['group' => 'policy', 'label' => 'Require a number',       'secret' => false, 'hint' => '0-9'],
        'password_require_special' => ['group' => 'policy', 'label' => 'Require a symbol',       'secret' => false, 'hint' => 'Anything that is not a letter or number.'],
    ];

    /** Rows the admin can see: label, mask/dates. Never a secret's plaintext. */
    public function all(string $group = 'keys'): array
    {
        $rows = [];
        foreach (db()->query('SELECT s.key, s.value_plain, s.value_encrypted, s.last4, s.is_secret, s.updated_at, u.name AS updater
                                FROM settings s LEFT JOIN users u ON u.id = s.updated_by') as $r) {
            $rows[$r['key']] = $r;
        }

        $out = [];
        foreach (self::SCHEMA as $key => $meta) {
            if (($meta['group'] ?? 'keys') !== $group) {
                continue;
            }
            $row = $rows[$key] ?? null;
            $out[] = [
                'key'    => $key,
                'label'  => $meta['label'],
                'secret' => $meta['secret'],
                'hint'   => $meta['hint'],
                'set'    => $row !== null && ($row['value_encrypted'] !== null || $row['value_plain'] !== null),
                'mask'   => $meta['secret'] && $row !== null && $row['last4'] !== null
                    ? mask_secret((string) $row['last4'])
                    : ($row['value_plain'] ?? ''),
                'updated_at' => $row['updated_at'] ?? null,
                'updater'    => $row['updater'] ?? null,
            ];
        }
        return $out;
    }

    /** Is there a stored value for this key (secret or plain)? */
    public function isSet(string $key): bool
    {
        $stmt = db()->prepare(
            'SELECT value_plain, value_encrypted FROM settings WHERE `key` = ? LIMIT 1'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row === false) {
            return false;
        }
        return ($row['value_encrypted'] ?? null) !== null
            || (($row['value_plain'] ?? null) ?? '') !== '';
    }

    /** Non-secret value, for handing to the browser (e.g. the App ID). */
    public function plain(string $key): ?string
    {
        $stmt = db()->prepare('SELECT value_plain FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Decrypt a stored secret. Returns null when unset or undecryptable. */
    public function secret(string $key): ?string
    {
        $stmt = db()->prepare('SELECT value_encrypted FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $sealed = $stmt->fetchColumn();
        return is_string($sealed) && $sealed !== '' ? decrypt_secret($sealed) : null;
    }

    /**
     * Save (or clear) one key. A secret is sealed here; a plain value is stored
     * as-is.
     *
     * Empty-value contract: '' deletes the row. Callers that want "leave the
     * saved value alone when the field is left blank" must check isSet() first -
     * KeyController::saveSetting() does exactly that, so only an explicit
     * name="clear" reaches this method with ''.
     *
     * @return string '' on success, otherwise an error message.
     */
    public function put(string $key, string $value, ?int $by): string
    {
        if (!isset(self::SCHEMA[$key])) {
            return 'Unknown setting.';
        }

        if ($value === '') {
            $stmt = db()->prepare('DELETE FROM settings WHERE `key` = ?');
            $stmt->execute([$key]);
            return '';
        }

        $isSecret = self::SCHEMA[$key]['secret'];

        if ($isSecret) {
            if (!crypto_available()) {
                return 'Server encryption is not configured (APP_KEY missing).';
            }
            $sealed = encrypt_secret($value);
            if ($sealed === null) {
                return 'The value could not be encrypted.';
            }
            $stmt = db()->prepare(
                'INSERT INTO settings (`key`, value_encrypted, value_plain, is_secret, last4, updated_by)
                 VALUES (?, ?, NULL, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE value_encrypted = VALUES(value_encrypted),
                     value_plain = NULL, is_secret = 1, last4 = VALUES(last4), updated_by = VALUES(updated_by)'
            );
            $stmt->execute([$key, $sealed, substr($value, -4), $by]);
            return '';
        }

        $stmt = db()->prepare(
            'INSERT INTO settings (`key`, value_encrypted, value_plain, is_secret, last4, updated_by)
             VALUES (?, NULL, ?, 0, NULL, ?)
             ON DUPLICATE KEY UPDATE value_plain = VALUES(value_plain),
                 value_encrypted = NULL, is_secret = 0, last4 = NULL, updated_by = VALUES(updated_by)'
        );
        $stmt->execute([$key, $value, $by]);
        return '';
    }
}
