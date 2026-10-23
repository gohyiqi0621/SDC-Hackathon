<?php
/**
 * SystemController.php - "which settings can this server see?" (signed-in users only).
 *
 *   GET system/settings
 *   GET system/logs          live flow log (Telegram / staff chat -> Flowise -> tools)
 *   GET system/logs_feed     the same as JSON, polled by the page
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
        'Flowise / AI'  => ['FLOWISE_API_URL', 'FLOWISE_CHATFLOW_ID', 'FLOWISE_STAFF_CHATFLOW_ID', 'FLOWISE_CHATFLOW_ID_ANTHROPIC', 'FLOWISE_STAFF_CHATFLOW_ID_ANTHROPIC', 'FLOWISE_API_KEY', 'RUNPOD_API_KEY', 'RUNPOD_ENDPOINT_ID', 'ANTHROPIC_API_KEY', 'AGNES_API_KEY'],
        'Google'        => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI', 'GMAIL_CLIENT_ID', 'GMAIL_CLIENT_SECRET', 'GMAIL_REDIRECT_URI'],
        'Email (Brevo)' => ['BREVO_API_KEY', 'BREVO_SENDER_EMAIL'],
    ];

    /** Optional: an empty value is fine (a default or a derived value is used). */
    private const OPTIONAL = ['PUBLIC_APP_URL', 'APP_TIMEZONE', 'DB_PORT', 'DB_SSLMODE', 'FLOWISE_STAFF_CHATFLOW_ID', 'FLOWISE_CHATFLOW_ID_ANTHROPIC', 'FLOWISE_STAFF_CHATFLOW_ID_ANTHROPIC', 'RUNPOD_API_KEY', 'RUNPOD_ENDPOINT_ID', 'ANTHROPIC_API_KEY', 'AGNES_API_KEY', 'GOOGLE_REDIRECT_URI',
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

    /* ------------------------------------------------------------ flow log -- */

    /** GET system/logs - the flow log, updating live. */
    public function logs(): void
    {
        $key = $this->requireLogViewer();
        $days = [];
        foreach (glob(STORAGE_ROOT . '/logs/telegram-flow-*.log') ?: [] as $file) {
            if (preg_match('/telegram-flow-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) {
                $days[] = $m[1];
            }
        }
        rsort($days);
        view('system/logs', [
            'title'   => 'Flow log',
            'days'    => $days !== [] ? $days : [date('Y-m-d')],
            'feedUrl' => url('system', ['action' => 'logs_feed'] + ($key !== null ? ['key' => $key] : [])),
            'bare'    => $key !== null && current_user() === null,
        ]);
    }

    /**
     * GET system/logs_feed?day=YYYY-MM-DD&after=<line no>&limit=300  (JSON)
     * Lines after "after" (or the last "limit" lines). Secrets are already
     * masked when the log is written (FlowLog).
     */
    public function logsFeed(): void
    {
        $this->requireLogViewer();
        $day   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['day'] ?? '')) ? (string) $_GET['day'] : date('Y-m-d');
        $after = max(0, (int) ($_GET['after'] ?? 0));
        $limit = max(10, min(1000, (int) ($_GET['limit'] ?? 300)));
        $file  = STORAGE_ROOT . '/logs/telegram-flow-' . $day . '.log';

        $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        $total = count($lines);
        $start = $after > 0 ? min($after, $total) : max(0, $total - $limit);

        $rows = [];
        for ($i = $start; $i < $total; $i++) {
            $row = json_decode($lines[$i], true);
            if (!is_array($row)) {
                continue;
            }
            $rows[] = ['n' => $i + 1] + $this->summarise($row);
        }

        json_response(true, ['day' => $day, 'total' => $total, 'rows' => $rows, 'exists' => is_file($file)]);
    }

    /** One log line -> time, request, step, short readable details (+ the full JSON for "more"). */
    private function summarise(array $row): array
    {
        $time = substr((string) ($row['time'] ?? ''), 11, 8);
        $req  = (string) ($row['req'] ?? '');
        $step = (string) ($row['step'] ?? '');
        unset($row['time'], $row['req'], $row['step']);

        // Flowise request/response: pick out what matters.
        if ($step === 'flowise.request' && isset($row['payload']['overrideConfig'])) {
            $oc = $row['payload']['overrideConfig'];
            $row = [
                'chatflow'  => basename((string) ($row['url'] ?? '')),
                'from'      => $row['chatflow_var'] ?? null,
                'question'  => $row['payload']['question'] ?? '',
                'session'   => $oc['sessionId'] ?? '',
                'model'     => $oc['modelName'] ?? '',
                'functions' => $oc['vars']['functions'] ?? null,
                'channel'   => $oc['vars']['channel'] ?? null,
            ];
        } elseif ($step === 'flowise.response' && isset($row['body'])) {
            $body  = json_decode((string) $row['body'], true);
            $tools = is_array($body) ? (array) ($body['usedTools'] ?? []) : [];
            $row = [
                'status'     => $row['status'] ?? null,
                'ms'         => $row['ms'] ?? null,
                'used_tools' => $tools === [] ? 'NONE (Flowise did not call any tool)'
                    : implode('; ', array_map(static fn ($t): string => ($t['tool'] ?? '?') . ' ' . json_encode($t['toolInput'] ?? [], JSON_UNESCAPED_UNICODE), $tools)),
                'text'       => is_array($body) ? (string) ($body['text'] ?? $body['message'] ?? '') : (string) $row['body'],
            ];
        }

        $details = [];
        foreach ($row as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $text = is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value)
                : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $details[] = [$key, mb_strimwidth(str_replace(["\r", "\n"], ' ', $text), 0, 400, '…')];
        }

        $level = str_contains($step, 'fallback') || str_contains($step, 'error') || str_contains($step, 'rejected')
            || str_contains($step, 'refused') || str_contains($step, 'failed') || str_contains($step, 'unreachable') ? 'bad'
            : (str_starts_with($step, 'tool.') ? 'tool' : (str_starts_with($step, 'flowise.') ? 'flowise' : 'info'));
        if ($step === 'flowise.response' && str_starts_with((string) ($row['used_tools'] ?? ''), 'NONE')) {
            $level = 'warn';
        }

        return ['time' => $time, 'req' => $req, 'step' => $step, 'level' => $level, 'details' => $details];
    }

    /**
     * The log holds every business's chats. Two ways in:
     *   - ?key=<LOG_VIEW_KEY> (a long random Replit Secret) - no login needed;
     *   - signed in as an email in LOG_VIEWER_EMAILS (comma-separated),
     *     or, when that is not set, as the first account.
     *
     * @return string|null the key when the page was opened with a valid key
     */
    private function requireLogViewer(): ?string
    {
        $secret = trim((string) env('LOG_VIEW_KEY', ''));
        $given  = (string) ($_GET['key'] ?? '');
        if ($given !== '') {
            if (strlen($secret) >= 16 && hash_equals($secret, $given)) {
                return $given;
            }
            sleep(1);   // slow down guessing
            abort(403, 'Wrong or missing log key (LOG_VIEW_KEY, at least 16 characters).');
        }

        $user    = require_login();
        $allowed = array_filter(array_map('strtolower', array_map('trim', explode(',', (string) env('LOG_VIEWER_EMAILS', '')))));
        if ($allowed !== []) {
            $ok = in_array(strtolower((string) $user['email']), $allowed, true);
        } else {
            $first = (int) \Src\Support\Database::connection()->query('SELECT MIN(id) FROM users')->fetchColumn();
            $ok    = (int) $user['id'] === $first;
        }
        if (!$ok) {
            abort(403, 'Only the owner account can read the flow log (set LOG_VIEWER_EMAILS to allow others).');
        }
        return null;
    }
}
