<?php
/**
 * AiSettingsController.php - JSON endpoints behind AI Settings & Integration.
 *
 * Every action:
 *   - takes the user from the session (require_login()) - a user id in the
 *     request is never read, so nobody can touch another user's settings;
 *   - answers with the app's envelope {success, data, message};
 *   - validates on the server (the browser checks are only for convenience);
 *   - never returns a bot token or OAuth token, encrypted or not.
 *
 * Routes (src/routes.php), all behind AuthMiddleware; POSTs are CSRF-checked
 * by the Router:
 *
 *   GET  ai_settings/company_profile            POST ai_settings/company_profile_save
 *   GET  ai_settings/assistant                  POST ai_settings/assistant_save
 *   GET  ai_settings/telegram                   POST ai_settings/telegram_test | telegram_connect | telegram_disconnect
 *   GET  ai_settings/google_calendar            GET  ai_settings/google_calendar_oauth_start
 *   GET  ai_settings/google_calendar_oauth_callback   (browser redirect from Google)
 *   GET  ai_settings/google_calendar_calendars  POST ai_settings/google_calendar_save | google_calendar_test | google_calendar_disconnect
 */
declare(strict_types=1);

namespace Src\Controllers;

use DateTimeZone;
use PDOException;
use RuntimeException;
use Src\Models\AiAssistantSetting;
use Src\Models\CompanyProfile;
use Src\Models\GoogleCalendarIntegration;
use Src\Models\TelegramIntegration;
use Src\Services\GoogleAuthRevoked;
use Src\Services\GoogleCalendarService;
use Src\Services\TelegramService;
use Src\Support\Crypto;
use Throwable;

final class AiSettingsController
{
    private const LOGO_DIR       = 'uploads/company';
    private const LOGO_MAX_BYTES = 2 * 1024 * 1024;
    private const LOGO_TYPES     = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const PERSONALITIES  = ['friendly', 'professional', 'warm', 'persuasive', 'custom'];
    private const DURATIONS      = [15, 30, 45, 60, 90];
    private const MAX_INSTRUCTIONS = 20000;

    private const TG_USERNAME = '/^[A-Za-z][A-Za-z0-9_]{1,29}bot$/i';
    private const TG_CHAT_ID  = '/^-?\d{5,20}$/';
    private const TG_TOKEN    = '/^\d{5,12}:[A-Za-z0-9_-]{30,64}$/';

    private const OAUTH_SESSION_KEY = 'gcal_oauth';
    private const OAUTH_MAX_AGE     = 600; // seconds the Google sign-in may take

    /* ===================================================== company profile === */

    /** GET ai_settings/company_profile */
    public function companyProfile(): void
    {
        $this->run(function (int $userId): void {
            json_response(true, ['company_profile' => $this->presentCompany((new CompanyProfile())->find($userId))]);
        });
    }

    /** POST ai_settings/company_profile_save (multipart) */
    public function companyProfileSave(): void
    {
        $this->run(function (int $userId): void {
            $input  = $this->strings($_POST, ['company_name', 'business_category', 'company_description', 'company_background']);
            $errors = [];

            if ($input['company_name'] === '' ) {
                $errors['company_name'] = 'Company name is required.';
            } elseif (mb_strlen($input['company_name']) < 2 || mb_strlen($input['company_name']) > 150) {
                $errors['company_name'] = 'Company name must be 2 to 150 characters.';
            }

            if (!in_array($input['business_category'], ChatbotController::BUSINESS_CATEGORIES, true)) {
                $errors['business_category'] = $input['business_category'] === '' ? 'Business category is required.' : 'Choose a category from the list.';
            }

            if ($input['company_description'] === '') {
                $errors['company_description'] = 'Company description is required.';
            } elseif (mb_strlen($input['company_description']) < 20) {
                $errors['company_description'] = 'Company description needs at least 20 characters.';
            } elseif (mb_strlen($input['company_description']) > 2000) {
                $errors['company_description'] = 'Company description must be 2000 characters or fewer.';
            }

            if (mb_strlen($input['company_background']) > 3000) {
                $errors['company_background'] = 'Company background must be 3000 characters or fewer.';
            }

            $file = $_FILES['company_logo'] ?? null;
            $logoError = $this->logoError($file);
            if ($logoError !== null) {
                $errors['company_logo'] = $logoError;
            }

            if ($errors !== []) {
                $this->invalid($errors);
            }

            $model    = new CompanyProfile();
            $existing = $model->find($userId);
            $oldLogo  = $existing['company_logo_url'] ?? null;
            $newLogo  = $this->hasUpload($file) ? $this->storeLogo($userId, $file) : null;

            $logo = $oldLogo;
            if (!empty($_POST['remove_logo'])) {
                $logo = null;
            }
            if ($newLogo !== null) {
                $logo = $newLogo;
            }

            try {
                $row = $model->save($userId, [
                    'company_name'        => $input['company_name'],
                    'business_category'   => $input['business_category'],
                    'company_description' => $input['company_description'],
                    'company_background'  => $input['company_background'] !== '' ? $input['company_background'] : null,
                    'company_logo_url'    => $logo,
                ]);
            } catch (Throwable $e) {
                $this->deleteLogo($userId, $newLogo);
                throw $e;
            }

            // The old file goes only once the database no longer points at it.
            if ($oldLogo !== null && $oldLogo !== $logo) {
                $this->deleteLogo($userId, $oldLogo);
            }

            json_response(true, ['company_profile' => $this->presentCompany($row)], 'Company information saved.');
        });
    }

    /* ========================================================= assistant === */

    /** GET ai_settings/assistant */
    public function assistant(): void
    {
        $this->run(function (int $userId): void {
            json_response(true, ['ai_settings' => $this->presentAssistant((new AiAssistantSetting())->find($userId))]);
        });
    }

    /** POST ai_settings/assistant_save */
    public function assistantSave(): void
    {
        $this->run(function (int $userId): void {
            $input = $this->strings($_POST, ['assistant_name', 'personality', 'tone_description']);
            $instructions = str_replace("\r\n", "\n", (string) ($_POST['system_instructions'] ?? ''));
            $errors = [];

            if ($input['assistant_name'] === '') {
                $errors['assistant_name'] = 'Assistant name is required.';
            } elseif (mb_strlen($input['assistant_name']) > 60) {
                $errors['assistant_name'] = 'Assistant name must be 60 characters or fewer.';
            }

            if (!in_array($input['personality'], self::PERSONALITIES, true)) {
                $errors['personality'] = 'Choose one of the personalities.';
            }

            if ($input['personality'] === 'custom' && mb_strlen($input['tone_description']) < 10) {
                $errors['tone_description'] = 'Describe the custom tone in at least 10 characters.';
            } elseif (mb_strlen($input['tone_description']) > 500) {
                $errors['tone_description'] = 'Tone description must be 500 characters or fewer.';
            }

            if (mb_strlen($instructions) > self::MAX_INSTRUCTIONS) {
                $errors['system_instructions'] = 'System instructions must be ' . number_format(self::MAX_INSTRUCTIONS) . ' characters or fewer.';
            }

            if ($errors !== []) {
                $this->invalid($errors);
            }

            $row = (new AiAssistantSetting())->save($userId, [
                'assistant_name'      => $input['assistant_name'],
                'personality'         => $input['personality'],
                'tone_description'    => $input['tone_description'] !== '' ? $input['tone_description'] : null,
                'system_instructions' => trim($instructions) !== '' ? $instructions : null,
                'is_enabled'          => $this->bool($_POST['is_enabled'] ?? '0'),
            ]);

            json_response(true, ['ai_settings' => $this->presentAssistant($row)], 'AI assistant settings saved.');
        });
    }

    /* ========================================================== telegram === */

    /** GET ai_settings/telegram */
    public function telegram(): void
    {
        $this->run(function (int $userId): void {
            json_response(true, ['telegram' => $this->presentTelegram((new TelegramIntegration())->find($userId))]);
        });
    }

    /** POST ai_settings/telegram_test - checks only, saves nothing (except marking a broken stored token). */
    public function telegramTest(): void
    {
        $this->run(function (int $userId): void {
            $model = new TelegramIntegration();
            $row   = $model->find($userId);
            [$input, $token, $usingStored] = $this->telegramInput($row);

            $result = (new TelegramService())->verify($token, $input['bot_username'], $input['staff_telegram_chat_id']);

            // The saved token stopped working: show that on the status badge.
            if (!$result['ok'] && $usingStored && ($row['connection_status'] ?? '') === 'connected') {
                $row = $model->save($userId, ['connection_status' => 'error']);
            }

            json_response(true, [
                'ok'           => $result['ok'],
                'message'      => $result['message'],
                'bot_username' => $result['bot_username'] ?? null,
                'telegram'     => $this->presentTelegram($row),
            ]);
        });
    }

    /** POST ai_settings/telegram_connect */
    public function telegramConnect(): void
    {
        $this->run(function (int $userId): void {
            $model = new TelegramIntegration();
            $row   = $model->find($userId);
            [$input, $token] = $this->telegramInput($row);

            $telegram = new TelegramService();
            $result   = $telegram->verify($token, $input['bot_username'], $input['staff_telegram_chat_id']);

            if (!$result['ok']) {
                json_response(false, [
                    'errors'   => [$result['field'] ?? 'bot_token' => $result['message']],
                    'telegram' => $this->presentTelegram($row),
                ], $result['message'], 422);
            }

            $row = $model->save($userId, [
                'bot_username'           => $result['bot_username'],
                'staff_telegram_chat_id' => $input['staff_telegram_chat_id'] !== '' ? $input['staff_telegram_chat_id'] : null,
                'bot_token_encrypted'    => Crypto::encrypt($token),
                'connection_status'      => 'connected',
                'is_enabled'             => true,
            ]);

            if ($input['staff_telegram_chat_id'] !== '') {
                $telegram->sendMessage($token, $input['staff_telegram_chat_id'],
                    '✅ ' . APP_NAME . ': @' . $result['bot_username'] . ' is now connected. Staff alerts will arrive in this chat.');
            }

            json_response(true, ['telegram' => $this->presentTelegram($row)], 'Telegram connected.');
        });
    }

    /** POST ai_settings/telegram_disconnect - deletes the stored token. */
    public function telegramDisconnect(): void
    {
        $this->run(function (int $userId): void {
            $model = new TelegramIntegration();
            $row   = $model->find($userId);

            if ($row !== null) {
                $row = $model->save($userId, [
                    'bot_token_encrypted' => null,
                    'connection_status'   => 'disconnected',
                    'is_enabled'          => false,
                ]);
            }

            json_response(true, ['telegram' => $this->presentTelegram($row)], 'Telegram disconnected.');
        });
    }

    /* =================================================== google calendar === */

    /** GET ai_settings/google_calendar */
    public function googleCalendar(): void
    {
        $this->run(function (int $userId): void {
            json_response(true, ['google_calendar' => $this->presentCalendar((new GoogleCalendarIntegration())->find($userId))]);
        });
    }

    /** GET ai_settings/google_calendar_oauth_start -> {auth_url} */
    public function googleCalendarOauthStart(): void
    {
        $this->run(function (int $userId): void {
            $google = new GoogleCalendarService();

            if (!$google->isConfigured()) {
                json_response(false, ['redirect_uri' => $google->redirectUri()],
                    'Google sign-in is not set up on this server yet. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to .env, '
                    . 'and add ' . $google->redirectUri() . ' as an authorized redirect URI in Google Cloud Console.', 503);
            }
            Crypto::encrypt('check'); // fail now, not after the user has signed in, if the key is missing

            $state    = bin2hex(random_bytes(16));
            $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

            $_SESSION[self::OAUTH_SESSION_KEY] = [
                'state'    => $state,
                'verifier' => $verifier,
                'user_id'  => $userId,
                'at'       => time(),
            ];

            json_response(true, ['auth_url' => $google->authUrl($state, $verifier)]);
        });
    }

    /** GET ai_settings/google_calendar_oauth_callback (Google redirects the browser here) */
    public function googleCalendarOauthCallback(): void
    {
        $user   = require_login();
        $userId = (int) $user['id'];
        $saved  = $_SESSION[self::OAUTH_SESSION_KEY] ?? null;
        unset($_SESSION[self::OAUTH_SESSION_KEY]); // single use

        $back = static function (string $outcome, string $message = ''): never {
            $params = ['action' => 'settings', 'gcal' => $outcome];
            if ($message !== '') {
                $params['gcal_message'] = $message;
            }
            redirect(url('customer', $params) . '#calendar');
        };

        $state = (string) ($_GET['state'] ?? '');
        if (!is_array($saved) || $state === '' || !hash_equals((string) $saved['state'], $state)
            || (int) $saved['user_id'] !== $userId || time() - (int) $saved['at'] > self::OAUTH_MAX_AGE) {
            $back('error', 'The Google sign-in link expired or did not match. Please try again.');
        }

        if (isset($_GET['error'])) {
            $back('error', $_GET['error'] === 'access_denied'
                ? 'Google sign-in was cancelled, so nothing was connected.'
                : 'Google returned an error (' . substr((string) $_GET['error'], 0, 60) . ').');
        }

        $code = (string) ($_GET['code'] ?? '');
        if ($code === '') {
            $back('error', 'Google did not return an authorization code.');
        }

        try {
            $google = new GoogleCalendarService();
            $tokens = $google->exchangeCode($code, (string) $saved['verifier']);
            $email  = $google->accountEmail($tokens['access_token']);

            $model    = new GoogleCalendarIntegration();
            $existing = $model->find($userId);

            $refresh = $tokens['refresh_token'] ?? null;
            if ($refresh === null && !empty($existing['refresh_token_encrypted'])
                && strcasecmp((string) ($existing['google_account_email'] ?? ''), $email) === 0) {
                $refresh = Crypto::decrypt((string) $existing['refresh_token_encrypted']);
            }
            if ($refresh === null) {
                $back('error', 'Google did not grant offline access. Remove this app at myaccount.google.com/permissions and connect again.');
            }

            $sameAccount = strcasecmp((string) ($existing['google_account_email'] ?? ''), $email) === 0;

            $model->save($userId, [
                'google_account_email'    => $email,
                'access_token_encrypted'  => $this->encryptAccess($tokens['access_token'], $tokens['expires_in']),
                'refresh_token_encrypted' => Crypto::encrypt($refresh),
                'connection_status'       => 'connected',
                'is_enabled'              => true,
                // A different account cannot use the old account's calendar.
                'calendar_id'             => $sameAccount && !empty($existing['calendar_id']) ? $existing['calendar_id'] : 'primary',
            ]);
        } catch (Throwable $e) {
            error_log('[gcal] oauth callback: ' . $e->getMessage());
            $back('error', $e instanceof PDOException
                ? 'Signed in with Google, but the connection could not be saved to the database.'
                : 'Could not finish connecting Google Calendar: ' . $e->getMessage());
        }

        $back('connected');
    }

    /** GET ai_settings/google_calendar_calendars */
    public function googleCalendarCalendars(): void
    {
        $this->run(function (int $userId): void {
            [$model, $row, $google] = $this->connectedCalendar($userId);
            $calendars = $this->withAccess($userId, $model, $row, static fn (string $token): array => $google->calendars($token));

            json_response(true, ['calendars' => $calendars]);
        });
    }

    /** POST ai_settings/google_calendar_save */
    public function googleCalendarSave(): void
    {
        $this->run(function (int $userId): void {
            $model  = new GoogleCalendarIntegration();
            $row    = $model->find($userId);
            [$input, $errors] = $this->calendarInput($row);

            $connected = ($row['connection_status'] ?? '') === 'connected';

            // A newly chosen calendar must be one this Google account can write to.
            if ($errors === [] && $connected && $input['calendar_id'] !== ($row['calendar_id'] ?? null)) {
                $google = new GoogleCalendarService();
                $ids = array_column($this->withAccess($userId, $model, $row, static fn (string $t): array => $google->calendars($t)), 'id');
                if (!in_array($input['calendar_id'], $ids, true)) {
                    $errors['calendar_id'] = 'This calendar is not available in the connected Google account.';
                }
            }

            if ($errors !== []) {
                $this->invalid($errors);
            }

            $values = [
                'default_appointment_duration_minutes' => $input['duration'],
                'timezone'                             => $input['timezone'],
            ];
            if ($connected) {
                $values['calendar_id'] = $input['calendar_id'];
            }

            $row = $model->save($userId, $values);

            json_response(true, ['google_calendar' => $this->presentCalendar($row)], 'Google Calendar settings saved.');
        });
    }

    /** POST ai_settings/google_calendar_test -> {available, message, slots} */
    public function googleCalendarTest(): void
    {
        $this->run(function (int $userId): void {
            [$model, $row, $google] = $this->connectedCalendar($userId);
            [$input, $errors] = $this->calendarInput($row);

            if ($errors !== []) {
                $this->invalid($errors);
            }

            $slots = $this->withAccess($userId, $model, $row,
                static fn (string $token): array => $google->freeSlots($token, $input['calendar_id'], $input['timezone'], $input['duration']));

            json_response(true, [
                'available' => $slots !== [],
                'slots'     => $slots,
                'message'   => $slots !== []
                    ? 'Google Calendar is reachable. Next free ' . $input['duration'] . '-minute slots (9am–6pm):'
                    : 'Google Calendar is reachable, but there is no free ' . $input['duration'] . '-minute slot between 9am and 6pm in the next 7 days.',
            ]);
        });
    }

    /** POST ai_settings/google_calendar_disconnect - removes tokens; appointment records are untouched. */
    public function googleCalendarDisconnect(): void
    {
        $this->run(function (int $userId): void {
            $model = new GoogleCalendarIntegration();
            $row   = $model->find($userId);

            if ($row !== null) {
                if (!empty($row['refresh_token_encrypted'])) {
                    try {
                        (new GoogleCalendarService())->revoke(Crypto::decrypt((string) $row['refresh_token_encrypted']));
                    } catch (RuntimeException) {
                        // Undecryptable token: nothing to revoke, delete it anyway.
                    }
                }

                $row = $model->save($userId, [
                    'google_account_email'    => null,
                    'calendar_id'             => null,
                    'access_token_encrypted'  => null,
                    'refresh_token_encrypted' => null,
                    'connection_status'       => 'disconnected',
                    'is_enabled'              => false,
                ]);
            }

            json_response(true, ['google_calendar' => $this->presentCalendar($row)], 'Google Calendar disconnected.');
        });
    }

    /* ============================================================ helpers === */

    /**
     * Runs an action for the signed-in user and turns failures into JSON the
     * page can show. Database problems get a message that says what to do.
     */
    private function run(callable $action): void
    {
        $userId = (int) require_login()['id'];

        try {
            $action($userId);
        } catch (PDOException $e) {
            error_log('[ai_settings] ' . $e->getMessage());
            $state = (string) $e->getCode();
            $message = match ($state) {
                '42P01' => 'The AI settings tables do not exist yet. Run: C:\\xampp\\php\\php.exe bin\\migrate.php',
                '42501' => 'The database user has no permission on the AI settings tables. Make the DB_USER in .env the owner of these tables.',
                default => 'A database error stopped this request.' . (APP_DEBUG ? ' ' . $e->getMessage() : ''),
            };
            json_response(false, null, $message, 500);
        } catch (RuntimeException $e) {
            // Our own messages (missing key, Google/Telegram unreachable): safe to show.
            error_log('[ai_settings] ' . $e->getMessage());
            json_response(false, null, $e->getMessage(), 502);
        }
    }

    /** @param array<string, string> $errors */
    private function invalid(array $errors): never
    {
        json_response(false, ['errors' => $errors], 'Please fix the highlighted fields.', 422);
    }

    /** @return array<string, string> trimmed strings for the given keys */
    private function strings(array $source, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $source[$key] ?? '';
            $out[$key] = is_string($value) ? trim(str_replace("\r\n", "\n", $value)) : '';
        }
        return $out;
    }

    private function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 't'], true);
    }

    private function iso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : date(DATE_ATOM, $ts);
    }

    /* ---- presenters: the only shapes that ever leave the server ---------- */

    private function presentCompany(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'id'                  => (int) $row['id'],
            'company_name'        => (string) $row['company_name'],
            'company_logo_url'    => !empty($row['company_logo_url']) ? asset((string) $row['company_logo_url']) : null,
            'company_background'  => (string) ($row['company_background'] ?? ''),
            'company_description' => (string) ($row['company_description'] ?? ''),
            'business_category'   => (string) ($row['business_category'] ?? ''),
            'created_at'          => $this->iso($row['created_at'] ?? null),
            'updated_at'          => $this->iso($row['updated_at'] ?? null),
        ];
    }

    private function presentAssistant(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'assistant_name'      => (string) $row['assistant_name'],
            'personality'         => (string) $row['personality'],
            'tone_description'    => (string) ($row['tone_description'] ?? ''),
            'system_instructions' => (string) ($row['system_instructions'] ?? ''),
            'is_enabled'          => $this->bool($row['is_enabled']),
            'updated_at'          => $this->iso($row['updated_at'] ?? null),
        ];
    }

    private function presentTelegram(?array $row): array
    {
        return [
            'bot_username'           => $row['bot_username'] ?? '',
            'staff_telegram_chat_id' => $row['staff_telegram_chat_id'] ?? '',
            'connection_status'      => $row['connection_status'] ?? 'disconnected',
            'is_enabled'             => $this->bool($row['is_enabled'] ?? false),
            'has_bot_token'          => !empty($row['bot_token_encrypted']),
            'updated_at'             => $this->iso($row['updated_at'] ?? null),
        ];
    }

    private function presentCalendar(?array $row): array
    {
        $google = new GoogleCalendarService();

        return [
            'google_account_email'                 => $row['google_account_email'] ?? null,
            'calendar_id'                          => $row['calendar_id'] ?? null,
            'connection_status'                    => $row['connection_status'] ?? 'disconnected',
            'default_appointment_duration_minutes' => (int) ($row['default_appointment_duration_minutes'] ?? 30),
            'timezone'                             => $row['timezone'] ?? 'Asia/Kuala_Lumpur',
            'is_enabled'                           => $this->bool($row['is_enabled'] ?? false),
            'updated_at'                           => $this->iso($row['updated_at'] ?? null),
            'oauth_configured'                     => $google->isConfigured(),
            'last_error'                           => ($row['connection_status'] ?? '') === 'error'
                ? 'Google stopped accepting the saved access. Connect the account again.' : null,
        ];
    }

    /* ---- company logo ---------------------------------------------------- */

    private function hasUpload(?array $file): bool
    {
        return $file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    private function logoError(?array $file): ?string
    {
        if (!$this->hasUpload($file)) {
            return null;
        }
        if (in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) ($file['size'] ?? 0) > self::LOGO_MAX_BYTES) {
            return 'The logo must be 2 MB or smaller.';
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return 'The logo could not be uploaded. Please try again.';
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!isset(self::LOGO_TYPES[$mime]) || @getimagesize((string) $file['tmp_name']) === false) {
            return 'Upload a JPG, PNG or WebP image.';
        }

        return null;
    }

    /** @return string path inside public/, e.g. uploads/company/7/ab12.png */
    private function storeLogo(int $userId, array $file): string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        $dir  = self::LOGO_DIR . '/' . $userId;
        $abs  = PUBLIC_ROOT . '/' . $dir;

        if (!is_dir($abs) && !mkdir($abs, 0755, true) && !is_dir($abs)) {
            throw new RuntimeException('Could not create the logo folder on the server.');
        }

        $relative = $dir . '/' . bin2hex(random_bytes(16)) . '.' . self::LOGO_TYPES[$mime];
        if (!move_uploaded_file((string) $file['tmp_name'], PUBLIC_ROOT . '/' . $relative)) {
            throw new RuntimeException('Could not save the logo on the server.');
        }

        return $relative;
    }

    /** Only ever deletes a file inside this user's own logo folder. */
    private function deleteLogo(int $userId, ?string $relative): void
    {
        $relative = (string) $relative;
        if ($relative === '' || !str_starts_with($relative, self::LOGO_DIR . '/' . $userId . '/') || str_contains($relative, '..')) {
            return;
        }
        $path = PUBLIC_ROOT . '/' . $relative;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /* ---- telegram -------------------------------------------------------- */

    /**
     * Validates the form; uses the stored token when none was typed.
     *
     * @return array{0: array{bot_username: string, staff_telegram_chat_id: string}, 1: string, 2: bool}
     */
    private function telegramInput(?array $row): array
    {
        $input = $this->strings($_POST, ['bot_username', 'staff_telegram_chat_id', 'bot_token']);
        $input['bot_username'] = ltrim($input['bot_username'], '@');
        $errors = [];

        if ($input['bot_username'] === '') {
            $errors['bot_username'] = 'Bot username is required.';
        } elseif (!preg_match(self::TG_USERNAME, $input['bot_username'])) {
            $errors['bot_username'] = 'Bot usernames are 5–32 letters, numbers or underscores and end in "bot".';
        }

        // Optional: when empty, no staff chat is checked or messaged.
        if ($input['staff_telegram_chat_id'] !== '' && !preg_match(self::TG_CHAT_ID, $input['staff_telegram_chat_id'])) {
            $errors['staff_telegram_chat_id'] = 'Chat IDs are numbers only (groups start with a minus sign).';
        }

        $token = $input['bot_token'];
        $usingStored = false;

        if ($token === '') {
            if (!empty($row['bot_token_encrypted'])) {
                $token = Crypto::decrypt((string) $row['bot_token_encrypted']);
                $usingStored = true;
            } else {
                $errors['bot_token'] = 'Bot token is required.';
            }
        } elseif (!preg_match(self::TG_TOKEN, $token)) {
            $errors['bot_token'] = 'That doesn\'t look like a bot token. It looks like 123456789:AA… (from @BotFather).';
        }

        if ($errors !== []) {
            $this->invalid($errors);
        }

        unset($input['bot_token']);

        return [$input, $token, $usingStored];
    }

    /* ---- google calendar ------------------------------------------------- */

    /** @return array{0: array{calendar_id: string, duration: int, timezone: string}, 1: array<string, string>} */
    private function calendarInput(?array $row): array
    {
        $input  = $this->strings($_POST, ['calendar_id', 'default_appointment_duration_minutes', 'timezone']);
        $errors = [];

        $duration = (int) $input['default_appointment_duration_minutes'];
        if (!in_array($duration, self::DURATIONS, true)) {
            $errors['default_appointment_duration_minutes'] = 'Choose one of the listed durations.';
        }

        if (!in_array($input['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Choose a timezone from the list.';
        }

        $calendarId = $input['calendar_id'] !== '' ? $input['calendar_id'] : (string) ($row['calendar_id'] ?? '');
        if (($row['connection_status'] ?? '') === 'connected') {
            if ($calendarId === '') {
                $errors['calendar_id'] = 'Choose the calendar for new appointments.';
            } elseif (mb_strlen($calendarId) > 255) {
                $errors['calendar_id'] = 'That calendar ID is too long.';
            }
        }

        return [['calendar_id' => $calendarId, 'duration' => $duration, 'timezone' => $input['timezone']], $errors];
    }

    /** @return array{0: GoogleCalendarIntegration, 1: array, 2: GoogleCalendarService} */
    private function connectedCalendar(int $userId): array
    {
        $model = new GoogleCalendarIntegration();
        $row   = $model->find($userId);

        if ($row === null || ($row['connection_status'] ?? '') !== 'connected' || empty($row['refresh_token_encrypted'])) {
            json_response(false, ['google_calendar' => $this->presentCalendar($row)], 'Connect a Google account first.', 409);
        }

        return [$model, $row, new GoogleCalendarService()];
    }

    private function encryptAccess(string $token, int $expiresIn): string
    {
        return Crypto::encrypt((string) json_encode(['token' => $token, 'expires_at' => time() + $expiresIn - 60]));
    }

    /**
     * Calls Google with a valid access token, refreshing it when it has
     * expired. If Google has revoked access, the row is marked "error" and the
     * page is told to reconnect.
     */
    private function withAccess(int $userId, GoogleCalendarIntegration $model, array $row, callable $call): mixed
    {
        $google = new GoogleCalendarService();

        try {
            $access = json_decode(Crypto::decrypt((string) ($row['access_token_encrypted'] ?? '')), true);
        } catch (RuntimeException) {
            $access = null;
        }

        try {
            if (!is_array($access) || (int) ($access['expires_at'] ?? 0) <= time()) {
                $fresh  = $google->refresh(Crypto::decrypt((string) $row['refresh_token_encrypted']));
                $model->save($userId, ['access_token_encrypted' => $this->encryptAccess($fresh['access_token'], $fresh['expires_in'])]);
                $access = ['token' => $fresh['access_token']];
            }

            return $call((string) $access['token']);
        } catch (GoogleAuthRevoked $e) {
            $row = $model->save($userId, ['connection_status' => 'error', 'access_token_encrypted' => null]);
            json_response(false, ['google_calendar' => $this->presentCalendar($row)], $e->getMessage(), 409);
        }
    }
}
