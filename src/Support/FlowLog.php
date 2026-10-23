<?php
/**
 * FlowLog.php - step-by-step log of the assistant message flow.
 *
 *   Telegram -> webhook -> system (config, system message) -> Flowise -> Telegram
 *
 * One JSON line per step in storage/logs/telegram-flow-YYYY-MM-DD.log, e.g.
 *
 *   {"time":"2026-10-21T14:05:01+08:00","req":"a1b2c3","step":"flowise.response",
 *    "channel":"telegram","status":200,"ms":2311,"body":"{\"text\":\"Hi!...\"}"}
 *
 * All lines from one webhook call share the same "req" id, so one message can
 * be followed from start to end. Read it with any text editor, or:
 *     C:\xampp\php\php.exe bin\flow-log.php          (last 40 lines, readable)
 *
 * Secrets never reach the file: bot tokens, API keys, tool tokens and
 * Authorization headers are masked by clean() before writing. Customer text
 * is kept (that is the point of the log) but cut to a sane length.
 * storage/ is blocked from the web by the root .htaccess.
 */
declare(strict_types=1);

namespace Src\Support;

use Throwable;

final class FlowLog
{
    private const MAX_STRING = 12000; // room for the whole system message
    private const SECRET_KEYS = ['token', 'bot_token', 'api_key', 'apikey', 'provider_api_key', 'tool_token',
                                 'authorization', 'secret', 'webhook_secret', 'password', 'key', 'openaiapikey', 'anthropicapikey'];

    private static ?string $requestId = null;

    /** Short id shared by every line written during this PHP request. */
    public static function requestId(): string
    {
        return self::$requestId ??= bin2hex(random_bytes(4));
    }

    /** Write one step. Never throws: logging must not stop a reply. */
    public static function write(string $step, array $data = []): void
    {
        try {
            $line = ['time' => date('c'), 'req' => self::requestId(), 'step' => $step] + self::clean($data);
            $json = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            $dir = APP_ROOT . '/storage/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents($dir . '/telegram-flow-' . date('Y-m-d') . '.log', $json . PHP_EOL, FILE_APPEND | LOCK_EX);

            // Hosted (Replit, Railway): also one short line in the server log, so it shows in the
            // host's Logs tab. FLOW_LOG_STDERR=true/false overrides the automatic choice.
            $mirror = env('FLOW_LOG_STDERR', '');
            $mirror = $mirror === '' ? (getenv('REPL_ID') !== false || getenv('RAILWAY_ENVIRONMENT') !== false)
                                     : filter_var($mirror, FILTER_VALIDATE_BOOL);
            if ($mirror) {
                error_log('[flow] ' . mb_strimwidth($json, 0, 2000, '…'));
            }
        } catch (Throwable $e) {
            error_log('[flowlog] ' . $e->getMessage());
        }
    }

    /** Path of today's log file (for messages and the CLI viewer). */
    public static function todayFile(): string
    {
        return APP_ROOT . '/storage/logs/telegram-flow-' . date('Y-m-d') . '.log';
    }

    /** Mask secrets in a Telegram API URL: https://api.telegram.org/bot123:ABC/sendMessage -> bot123:***. */
    public static function maskUrl(string $url): string
    {
        return (string) preg_replace('#/bot(\d+):[A-Za-z0-9_-]+#', '/bot$1:***', $url);
    }

    private static function clean(mixed $value, string $key = ''): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::clean($v, strtolower((string) $k));
            }
            return $out;
        }

        if ($key !== '' && in_array($key, self::SECRET_KEYS, true) && $value !== null && $value !== '') {
            return '***';
        }

        if (is_string($value)) {
            // Bot tokens and Bearer values that ended up inside free text.
            $value = (string) preg_replace('/\b\d{5,12}:[A-Za-z0-9_-]{30,64}\b/', '***bot-token***', $value);
            $value = (string) preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/=-]{8,}/i', '$1***', $value);
            $value = (string) preg_replace('/\b(sk-(?:ant-)?[A-Za-z0-9_-]{6})[A-Za-z0-9_-]+/', '$1***', $value);
            if (mb_strlen($value) > self::MAX_STRING) {
                $value = mb_substr($value, 0, self::MAX_STRING) . '…(cut)';
            }
        }

        return $value;
    }
}
