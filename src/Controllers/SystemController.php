<?php
/**
 * SystemController.php - "which settings can this server see?" (signed-in users only).
 *
 *   GET system/settings
 *
 * For hosting checks (Replit, Railway): lists each setting the app uses and
 * where it came from - a real environment variable / Replit Secret, the .env
 * file, or missing. Values are never shown.
 */
declare(strict_types=1);

namespace Src\Controllers;

final class SystemController
{
    /** Settings the app reads, grouped for the page. */
    private const KEYS = [
        'App'           => ['APP_URL', 'PUBLIC_APP_URL', 'APP_ENV', 'APP_DEBUG', 'APP_TIMEZONE', 'APP_ENCRYPTION_KEY'],
        'Database'      => ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_SSLMODE'],
        'Flowise / AI'  => ['FLOWISE_API_URL', 'FLOWISE_CHATFLOW_ID', 'FLOWISE_API_KEY', 'AGNES_API_KEY'],
        'Google'        => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI', 'GMAIL_CLIENT_ID', 'GMAIL_CLIENT_SECRET', 'GMAIL_REDIRECT_URI'],
        'Email (Brevo)' => ['BREVO_API_KEY', 'BREVO_SENDER_EMAIL'],
    ];

    /** Optional: an empty value is fine (a default or a derived value is used). */
    private const OPTIONAL = ['PUBLIC_APP_URL', 'APP_TIMEZONE', 'DB_SSLMODE', 'AGNES_API_KEY', 'GOOGLE_REDIRECT_URI',
                              'GMAIL_REDIRECT_URI', 'BREVO_API_KEY', 'BREVO_SENDER_EMAIL', 'APP_ENV', 'APP_DEBUG'];

    /** GET system/settings */
    public function settings(): void
    {
        require_login();

        $envFile = APP_ROOT . '/.env';
        $fileKeys = [];
        if (is_file($envFile)) {
            foreach ((array) file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (preg_match('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', (string) $line, $m)) {
                    $fileKeys[$m[1]] = trim($m[2], " \t\"'") !== '';
                }
            }
        }

        $groups = [];
        foreach (self::KEYS as $group => $keys) {
            foreach ($keys as $key) {
                $real   = getenv($key);
                $inEnv  = ($real !== false && $real !== '') || (isset($_SERVER[$key]) && $_SERVER[$key] !== '');
                $value  = (string) ($_ENV[$key] ?? '');
                $groups[$group][] = [
                    'key'      => $key,
                    'state'    => $value === '' ? 'missing' : ($inEnv ? 'secret' : 'file'),
                    'optional' => in_array($key, self::OPTIONAL, true),
                    'in_file'  => $fileKeys[$key] ?? null,   // null: not in .env, false: empty line in .env
                ];
            }
        }

        $all = getenv();
        view('system/settings', [
            'groups'      => $groups,
            'envFile'     => is_file($envFile),
            'sapi'        => PHP_SAPI,
            'envCount'    => is_array($all) ? count($all) : 0,
            'appUrl'      => APP_URL,
            'loader'      => 'Replit Secrets first, then .env (22 Oct 2026)',
        ]);
    }
}
