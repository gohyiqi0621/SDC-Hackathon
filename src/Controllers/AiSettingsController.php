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
 *   GET  ai_settings/assistant                  POST ai_settings/assistant_save | generate_sops
 *   GET  ai_settings/staff_assistant            POST ai_settings/staff_assistant_save | staff_generate_sops
 *        (the same form for the staff assistant, stored in staff_ai_assistant_settings)
 *   GET  ai_settings/telegram                   POST ai_settings/telegram_connect | telegram_disconnect | telegram_forget
 *                                               POST ai_settings/telegram_staff_link | telegram_staff_unlink
 *   GET  ai_settings/google_calendar            GET  ai_settings/google_calendar_oauth_start
 *   GET  ai_settings/google_calendar_oauth_callback   (browser redirect from Google)
 *   GET  ai_settings/google_calendar_calendars  POST ai_settings/google_calendar_save | google_calendar_disconnect
 */
declare(strict_types=1);

namespace Src\Controllers;

use DateTimeZone;
use PDOException;
use RuntimeException;
use Src\Models\AiAssistantSetting;
use Src\Models\CompanyProfile;
use Src\Models\GoogleCalendarIntegration;
use Src\Models\StaffAiAssistantSetting;
use Src\Models\UserSettingsRow;
use Src\Models\TelegramIntegration;
use Src\Services\GoogleAuthRevoked;
use Src\Services\GoogleCalendarService;
use Src\Services\SopGeneratorService;
use Src\Services\TelegramService;
use Src\Services\TelegramWebhookService;
use Src\Support\Crypto;
use Throwable;

final class AiSettingsController
{
    private const LOGO_DIR       = 'uploads/company';
    private const LOGO_MAX_BYTES = 2 * 1024 * 1024;
    private const LOGO_TYPES     = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const PERSONALITIES  = ['friendly', 'professional', 'warm', 'persuasive', 'custom'];

    /**
     * Models the assistant can run on: id => [label, provider]. The id is what
     * the provider's API expects. Add a row here to offer another model.
     */
    public const AI_MODELS = [
        'claude-haiku-5-5'    => ['Claude Haiku 5.5',    'Anthropic'],
        'agnes-2.5-flash'     => ['Agnes 2.5 Flash',     'Agnes'],
        'gpt-6-luna'          => ['GPT-6 Luna',          'OpenAI'],
        'deepseek-v4.1-flash' => ['DeepSeek V4.1 Flash', 'DeepSeek'],
    ];
    public const DEFAULT_AI_MODEL    = 'claude-haiku-5-5';

    /** Reply language choices (ai_assistant_settings.reply_language, migration 010). */
    public const REPLY_LANGUAGES = [
        'auto' => 'Same language as the customer (recommended)',
        'en'   => 'Always English',
        'ms'   => 'Always Bahasa Melayu',
        'zh'   => 'Always Chinese (简体中文)',
        'ta'   => 'Always Tamil',
    ];
    public const DEFAULT_TEMPERATURE = 0.7;

    /**
     * Models offered before, mapped to the nearest model still on the list, so
     * a row saved earlier keeps working with the same provider and key.
     */
    private const LEGACY_MODELS = [
        'claude-sonnet-5-5' => 'claude-haiku-5-5',
        'claude-opus-5-5'   => 'claude-haiku-5-5',
        'deepseek-v4-flash' => 'deepseek-v4.1-flash',
        'deepseek-v4-pro'   => 'deepseek-v4.1-flash',
    ];

    /** What each provider's secret keys start with (a quick sanity check, not a real test). Agnes keys have no fixed prefix. */
    private const KEY_PREFIXES = ['Anthropic' => 'sk-ant-', 'OpenAI' => 'sk-', 'DeepSeek' => 'sk-'];

    /** A saved model id as one of AI_MODELS (old ids are mapped, unknown ones fall back to the default). */
    public static function resolveModel(?string $model): string
    {
        $model = (string) $model;
        if (isset(self::AI_MODELS[$model])) {
            return $model;
        }
        return self::LEGACY_MODELS[$model] ?? self::DEFAULT_AI_MODEL;
    }
    private const DURATIONS      = [15, 30, 45, 60, 90, 120, 180];
    private const MAX_INSTRUCTIONS = 20000;

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
            $input  = $this->strings($_POST, ['company_name', 'business_category', 'company_background', 'payment_instructions']);
            $errors = [];

            if ($input['company_name'] === '' ) {
                $errors['company_name'] = 'Company name is required.';
            } elseif (mb_strlen($input['company_name']) < 2 || mb_strlen($input['company_name']) > 150) {
                $errors['company_name'] = 'Company name must be 2 to 150 characters.';
            }

            if (!in_array($input['business_category'], ChatbotController::BUSINESS_CATEGORIES, true)) {
                $errors['business_category'] = $input['business_category'] === '' ? 'Business category is required.' : 'Choose a category from the list.';
            }

            if (mb_strlen($input['company_background']) > 3000) {
                $errors['company_background'] = 'Company background must be 3000 characters or fewer.';
            }

            if (mb_strlen($input['payment_instructions']) > 1000) {
                $errors['payment_instructions'] = 'Payment instructions must be 1000 characters or fewer.';
            }

            $file = $_FILES['company_logo'] ?? null;
            $logoError = $this->logoError($file);
            if ($logoError !== null) {
                $errors['company_logo'] = $logoError;
            }

            $qrFile  = $_FILES['payment_qr'] ?? null;
            $qrError = $this->logoError($qrFile);
            if ($qrError !== null) {
                $errors['payment_qr'] = str_replace('The logo', 'The QR image', $qrError);
            }

            if ($errors !== []) {
                $this->invalid($errors);
            }

            $model    = new CompanyProfile();
            $existing = $model->find($userId);
            $oldLogo  = $existing['company_logo_url'] ?? null;
            $newLogo  = $this->hasUpload($file) ? $this->storeLogo($userId, $file) : null;
            $oldQr    = $existing['payment_qr_url'] ?? null;
            $newQr    = $this->hasUpload($qrFile) ? $this->storeLogo($userId, $qrFile) : null;
            $qr       = !empty($_POST['remove_payment_qr']) ? null : $oldQr;
            if ($newQr !== null) {
                $qr = $newQr;
            }

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
                    'company_background'  => $input['company_background'] !== '' ? $input['company_background'] : null,
                    'payment_instructions' => $input['payment_instructions'] !== '' ? $input['payment_instructions'] : null,
                    'payment_qr_url'      => $qr,
                    'company_logo_url'    => $logo,
                ]);
            } catch (Throwable $e) {
                $this->deleteLogo($userId, $newLogo);
                $this->deleteLogo($userId, $newQr);
                throw $e;
            }

            // The old file goes only once the database no longer points at it.
            if ($oldLogo !== null && $oldLogo !== $logo) {
                $this->deleteLogo($userId, $oldLogo);
            }
            if ($oldQr !== null && $oldQr !== $qr) {
                $this->deleteLogo($userId, $oldQr);
            }

            json_response(true, ['company_profile' => $this->presentCompany($row)], 'Company information saved.');
        });
    }

    /* ========================================================= assistant === */

    /** GET ai_settings/assistant */
    public function assistant(): void
    {
        $this->assistantGetFor(new AiAssistantSetting());
    }

    /** GET ai_settings/staff_assistant - the staff assistant (staff_ai_assistant_settings). */
    public function staffAssistant(): void
    {
        $this->assistantGetFor(new StaffAiAssistantSetting());
    }

    private function assistantGetFor(UserSettingsRow $model): void
    {
        $this->run(function (int $userId) use ($model): void {
            json_response(true, ['ai_settings' => $this->presentAssistant($model->find($userId))]);
        });
    }

    /** POST ai_settings/assistant_save */
    public function assistantSave(): void
    {
        $this->assistantSaveFor(new AiAssistantSetting());
    }

    /** POST ai_settings/staff_assistant_save */
    public function staffAssistantSave(): void
    {
        $this->assistantSaveFor(new StaffAiAssistantSetting());
    }

    private function assistantSaveFor(UserSettingsRow $model): void
    {
        $this->run(function (int $userId) use ($model): void {
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

            $existing = $model->find($userId);

            $aiModel = trim((string) ($_POST['ai_model'] ?? ''));
            if (!isset(self::AI_MODELS[$aiModel])) {
                $errors['ai_model'] = 'Choose an AI model from the list.';
            }

            $temperatureRaw = trim((string) ($_POST['temperature'] ?? ''));
            if (!is_numeric($temperatureRaw) || (float) $temperatureRaw < 0 || (float) $temperatureRaw > 1) {
                $errors['temperature'] = 'Temperature must be a number from 0 to 1.';
            }

            // Secret key: optional when one is already saved for the same provider.
            $apiKey   = trim((string) ($_POST['api_key'] ?? ''));
            $provider = self::AI_MODELS[$aiModel][1] ?? null;
            $oldModel = !empty($existing['ai_model']) ? self::resolveModel((string) $existing['ai_model']) : '';
            $oldProvider = self::AI_MODELS[$oldModel][1] ?? null;

            if ($apiKey !== '') {
                if (strlen($apiKey) < 20 || strlen($apiKey) > 300 || preg_match('/\s/', $apiKey)) {
                    $errors['api_key'] = 'That does not look like a secret key. Copy it again from the provider.';
                } elseif ($provider !== null && isset(self::KEY_PREFIXES[$provider]) && !str_starts_with($apiKey, self::KEY_PREFIXES[$provider])) {
                    $errors['api_key'] = $provider . ' keys start with "' . self::KEY_PREFIXES[$provider] . '". Check you copied the key for ' . $provider . '.';
                }
            } elseif (!empty($existing['api_key_encrypted']) && $provider !== null && $oldProvider !== null && $provider !== $oldProvider) {
                $errors['api_key'] = 'You switched to a ' . $provider . ' model. Enter the ' . $provider . ' secret key.';
            }

            $replyLanguage = trim((string) ($_POST['reply_language'] ?? 'auto'));
            if (!isset(self::REPLY_LANGUAGES[$replyLanguage])) {
                $errors['reply_language'] = 'Choose a reply language from the list.';
            }

            if (mb_strlen($instructions) > self::MAX_INSTRUCTIONS) {
                $errors['system_instructions'] = 'System instructions must be ' . number_format(self::MAX_INSTRUCTIONS) . ' characters or fewer.';
            }

            if ($errors !== []) {
                $this->invalid($errors);
            }

            $values = [
                'assistant_name'      => $input['assistant_name'],
                'personality'         => $input['personality'],
                'tone_description'    => $input['tone_description'] !== '' ? $input['tone_description'] : null,
                'system_instructions' => trim($instructions) !== '' ? $instructions : null,
                'is_enabled'          => $this->bool($_POST['is_enabled'] ?? '0'),
                'ai_model'            => $aiModel,
                'temperature'         => number_format((float) $temperatureRaw, 2, '.', ''),
                'reply_language'      => $replyLanguage,
            ];
            if ($apiKey !== '') {
                $values['api_key_encrypted'] = Crypto::encrypt($apiKey);   // otherwise the saved key is kept
            }

            $row = $model->save($userId, $values);

            json_response(true, ['ai_settings' => $this->presentAssistant($row)], 'AI assistant settings saved.');
        });
    }

    /**
     * POST ai_settings/generate_sops
     *
     * Agnes AI reads the saved company information (and the active products)
     * and writes three SOPs for this business, five short steps each. One
     * goes into each SOP box (customer service / booking-or-order process /
     * sales); the titles are chosen for the business. Nothing is saved here:
     * the page fills the boxes and the owner reviews them, then saves.
     */
    public function generateSops(): void
    {
        $this->generateSopsFor(new AiAssistantSetting());
    }

    /** POST ai_settings/staff_generate_sops - same, using the staff assistant's settings. */
    public function staffGenerateSops(): void
    {
        $this->generateSopsFor(new StaffAiAssistantSetting());
    }

    private function generateSopsFor(UserSettingsRow $model): void
    {
        $this->run(function (int $userId) use ($model): void {
            // A short pause between runs, so a double click does not pay twice.
            $last = (int) ($_SESSION['sop_generated_at'] ?? 0);
            if ($last > time() - 10) {
                json_response(false, null, 'Please wait a few seconds before generating again.', 429);
            }

            $company = (new CompanyProfile())->find($userId);
            if ($company === null || trim((string) ($company['company_name'] ?? '')) === '') {
                json_response(false, null, 'Save your Company Information first (name, category and background). The instructions are written from it.', 422);
            }
            if (trim((string) ($company['company_background'] ?? '')) === '' && trim((string) ($company['company_description'] ?? '')) === '') {
                json_response(false, null, 'Add a company background or description in Company Information first, so the instructions fit your business.', 422);
            }

            $_SESSION['sop_generated_at'] = time();

            $assistant = $model->find($userId);
            $result = (new SopGeneratorService())->generate($company, SopGeneratorService::productsFor($userId), $assistant);

            json_response(true, $result, 'Instructions generated. Review them, then save.');
        });
    }

    /* ========================================================== telegram === */

    /**
     * GET ai_settings/telegram
     *
     * The bot saved for this account (if any), whether it is connected, the
     * staff chat link, Telegram's own view of the webhook (last error,
     * pending messages), and name suggestions for creating a new bot.
     */
    public function telegram(): void
    {
        $this->run(function (int $userId): void {
            $row = (new TelegramIntegration())->find($userId);

            json_response(true, [
                'telegram'    => $this->presentTelegram($row),
                'webhook'     => $this->webhookStatus($row),
                'suggestions' => $this->botSuggestions($userId),
            ]);
        });
    }

    /**
     * POST ai_settings/telegram_connect
     *
     *   use_saved=1               reconnect the bot already saved for this account
     *   bot_token=123:AA...       or connect a new bot (created in @BotFather)
     *   bot_name=Aisyah           optional: the chatbot name customers see
     *
     * The username comes from Telegram (getMe), so it is never typed. Then the
     * name is set (setMyName) and the webhook is registered, so customer
     * messages reach this app.
     */
    public function telegramConnect(): void
    {
        $this->run(function (int $userId): void {
            $model    = new TelegramIntegration();
            $row      = $model->find($userId);
            $useSaved = $this->bool($_POST['use_saved'] ?? '0');
            $token    = trim((string) ($_POST['bot_token'] ?? ''));
            $botName  = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['bot_name'] ?? '')));
            $errors   = [];

            if ($useSaved) {
                if (empty($row['bot_token_encrypted'])) {
                    json_response(false, ['errors' => ['bot_token' => 'No saved chatbot on this account. Create a new one.']], 'No saved chatbot on this account.', 422);
                }
                $token = Crypto::decrypt((string) $row['bot_token_encrypted']);
            } elseif ($token === '') {
                $errors['bot_token'] = 'Paste the bot token that @BotFather sent you.';
            } elseif (!preg_match(self::TG_TOKEN, $token)) {
                $errors['bot_token'] = 'That doesn\'t look like a bot token. It looks like 123456789:AA… (from @BotFather).';
            }

            if ($botName !== '' && (mb_strlen($botName) > 64)) {
                $errors['bot_name'] = 'The chatbot name must be 64 characters or fewer.';
            }
            if ($errors !== []) {
                $this->invalid($errors);
            }

            $telegram = new TelegramService();
            $result   = $telegram->verify($token, null, null);
            if (!$result['ok']) {
                json_response(false, [
                    'errors'   => [$result['field'] === 'form' ? 'form' : 'bot_token' => $result['message']],
                    'telegram' => $this->presentTelegram($row),
                ], $result['message'], 422);
            }

            // A different bot replaces the old one: stop the old webhook first.
            if (!$useSaved && !empty($row['bot_token_encrypted']) && strcasecmp((string) $row['bot_username'], (string) $result['bot_username']) !== 0) {
                try {
                    (new TelegramWebhookService())->unregister($row, Crypto::decrypt((string) $row['bot_token_encrypted']));
                } catch (RuntimeException $e) {
                    // old token unreadable: nothing to unregister
                }
                $row = $model->save($userId, ['staff_telegram_chat_id' => null, 'staff_linked_name' => null]);
            }

            $nameResult = ['ok' => true, 'message' => ''];
            if ($botName !== '') {
                $nameResult = $telegram->setMyName($token, $botName);
            } else {
                $botName = $telegram->myName($token) ?: (string) ($row['bot_name'] ?? '');
            }

            $row = $model->save($userId, [
                'bot_username'        => $result['bot_username'],
                'bot_name'            => $botName !== '' ? mb_substr($botName, 0, 64) : null,
                'bot_token_encrypted' => Crypto::encrypt($token),
                'connection_status'   => 'connected',
                'is_enabled'          => true,
            ]);

            // Send customer messages to this app (Telegram -> website -> Flowise).
            try {
                $webhook = (new TelegramWebhookService())->register($row, $token);
            } catch (PDOException $e) {
                error_log('[telegram] webhook columns missing: ' . $e->getMessage());
                $webhook = ['ok' => false, 'message' => 'Run the database migrations (C:\\xampp\\php\\php.exe bin\\migrate.php) first, then connect again.'];
            }
            $row = $model->find($userId) ?? $row;

            $message = '@' . $result['bot_username'] . ' is connected.';
            $message .= $webhook['ok'] ? ' Customer messages will be answered by the AI assistant.' : ' But customer messages are not linked yet: ' . $webhook['message'];
            if (!$nameResult['ok']) {
                $message .= ' (' . $nameResult['message'] . ')';
            }

            json_response(true, [
                'telegram' => $this->presentTelegram($row),
                'webhook'  => $this->webhookStatus($row, $token),
            ], $message);
        });
    }

    /**
     * POST ai_settings/telegram_disconnect - the bot stops answering, but it
     * stays saved (encrypted) so it can be selected again later.
     */
    public function telegramDisconnect(): void
    {
        $this->run(function (int $userId): void {
            $model = new TelegramIntegration();
            $row   = $model->find($userId);

            if ($row !== null && !empty($row['bot_token_encrypted'])) {
                try {
                    // Stop Telegram from sending customer messages here.
                    (new TelegramWebhookService())->unregister($row, Crypto::decrypt((string) $row['bot_token_encrypted']));
                } catch (RuntimeException | PDOException $e) {
                    // Unreadable token: nothing to unregister with.
                }
            }

            if ($row !== null) {
                $row = $model->save($userId, ['connection_status' => 'disconnected', 'is_enabled' => false]);
            }

            json_response(true, ['telegram' => $this->presentTelegram($row), 'webhook' => null], 'The chatbot is still saved on this account, so you can connect it again.');
        });
    }

    /** POST ai_settings/telegram_forget - delete the saved bot token completely. */
    public function telegramForget(): void
    {
        $this->run(function (int $userId): void {
            $model = new TelegramIntegration();
            $row   = $model->find($userId);

            if ($row !== null && !empty($row['bot_token_encrypted'])) {
                try {
                    (new TelegramWebhookService())->unregister($row, Crypto::decrypt((string) $row['bot_token_encrypted']));
                } catch (RuntimeException | PDOException $e) {
                    // nothing to unregister with
                }
            }
            if ($row !== null) {
                $row = $model->save($userId, [
                    'bot_username' => null, 'bot_name' => null, 'bot_token_encrypted' => null,
                    'staff_telegram_chat_id' => null, 'staff_linked_name' => null,
                    'staff_link_code' => null, 'staff_link_expires_at' => null,
                    'connection_status' => 'disconnected', 'is_enabled' => false,
                ]);
            }

            json_response(true, ['telegram' => $this->presentTelegram($row), 'webhook' => null], 'The saved chatbot was removed from this account.');
        });
    }

    /**
     * POST ai_settings/telegram_staff_link
     *
     * A one-time link (valid 15 minutes): t.me/<bot>?start=staff_<code>. When
     * the signed-in owner opens it and presses Start, the webhook saves that
     * Telegram chat as this account's staff chat (TelegramWebhookController).
     */
    public function telegramStaffLink(): void
    {
        $this->run(function (int $userId): void {
            $model = new TelegramIntegration();
            $row   = $model->find($userId);

            if ($row === null || ($row['connection_status'] ?? '') !== 'connected' || empty($row['bot_username'])) {
                json_response(false, null, 'Connect a chatbot first.', 422);
            }

            $code = bin2hex(random_bytes(12));
            $model->save($userId, [
                'staff_link_code'       => $code,
                'staff_link_expires_at' => date('c', time() + 900),
            ]);

            json_response(true, [
                'link'       => 'https://t.me/' . rawurlencode((string) $row['bot_username']) . '?start=staff_' . $code,
                'expires_in' => 900,
            ], 'Open the link in Telegram and press Start.');
        });
    }

    /** POST ai_settings/telegram_staff_unlink */
    public function telegramStaffUnlink(): void
    {
        $this->run(function (int $userId): void {
            $model = new TelegramIntegration();
            $row   = $model->find($userId);
            if ($row !== null) {
                $row = $model->save($userId, ['staff_telegram_chat_id' => null, 'staff_linked_name' => null,
                                              'staff_link_code' => null, 'staff_link_expires_at' => null]);
            }
            json_response(true, ['telegram' => $this->presentTelegram($row)], 'Staff chat unlinked.');
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
                'booking_mode'                         => $input['booking_mode'],
                'slot_capacity'                        => $input['slot_capacity'],
                'capacity_unit'                        => $input['capacity_unit'],
                'open_time'                            => $input['open_time'],
                'close_time'                           => $input['close_time'],
                'open_days'                            => $input['open_days'],
            ];
            if ($connected) {
                $values['calendar_id'] = $input['calendar_id'];
            }

            $row = $model->save($userId, $values);

            json_response(true, ['google_calendar' => $this->presentCalendar($row)], 'Booking settings saved.');
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
                '42P01', '42703' => 'The database is missing the latest AI settings tables or columns. Run: C:\\xampp\\php\\php.exe bin\\migrate.php',
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
            'payment_instructions' => (string) ($row['payment_instructions'] ?? ''),
            'payment_qr_url'      => !empty($row['payment_qr_url']) ? asset((string) $row['payment_qr_url']) : null,
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
            'ai_model'            => self::resolveModel($row['ai_model'] ?? null),
            'temperature'         => isset($row['temperature']) ? (float) $row['temperature'] : self::DEFAULT_TEMPERATURE,
            'reply_language'      => (string) ($row['reply_language'] ?? 'auto'),
            'has_api_key'         => !empty($row['api_key_encrypted']),   // the key itself never leaves the server
            'updated_at'          => $this->iso($row['updated_at'] ?? null),
        ];
    }

    private function presentTelegram(?array $row): array
    {
        return [
            'bot_username'           => $row['bot_username'] ?? '',
            'bot_name'               => $row['bot_name'] ?? '',
            'bot_link'               => !empty($row['bot_username']) ? 'https://t.me/' . $row['bot_username'] : null,
            'staff_telegram_chat_id' => $row['staff_telegram_chat_id'] ?? '',
            'staff_linked_name'      => $row['staff_linked_name'] ?? '',
            'connection_status'      => $row['connection_status'] ?? 'disconnected',
            'is_enabled'             => $this->bool($row['is_enabled'] ?? false),
            'has_bot_token'          => !empty($row['bot_token_encrypted']),
            // Customer messages reach this app (Telegram webhook registered).
            'webhook_linked'         => !empty($row['webhook_url']),
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
            'default_appointment_duration_minutes' => (int) ($row['default_appointment_duration_minutes'] ?? 60),
            'timezone'                             => $row['timezone'] ?? 'Asia/Kuala_Lumpur',
            'booking_mode'                         => $row['booking_mode'] ?? 'single',
            'slot_capacity'                        => (int) ($row['slot_capacity'] ?? 1),
            'capacity_unit'                        => $row['capacity_unit'] ?? 'bookings',
            'open_time'                            => substr((string) ($row['open_time'] ?? '09:00'), 0, 5),
            'close_time'                           => substr((string) ($row['close_time'] ?? '18:00'), 0, 5),
            'open_days'                            => array_map('intval', array_filter(explode(',', (string) ($row['open_days'] ?? '1,2,3,4,5,6,7')))),
            'durations'                            => self::DURATIONS,
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
     * Telegram's own view of the webhook: is it pointing here, and the last
     * delivery error (e.g. ngrok offline). Null when no bot is connected.
     */
    private function webhookStatus(?array $row, ?string $token = null): ?array
    {
        if ($row === null || ($row['connection_status'] ?? '') !== 'connected' || empty($row['bot_token_encrypted'])) {
            return null;
        }
        try {
            $token ??= Crypto::decrypt((string) $row['bot_token_encrypted']);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => 'The saved bot token cannot be read. Connect the chatbot again.'];
        }

        $info     = (new TelegramService())->webhookInfo($token, 6);
        $expected = TelegramWebhookService::urlFor((int) $row['id']);

        if ($info === null) {
            return ['ok' => null, 'message' => 'Could not ask Telegram about the webhook right now.'];
        }

        $url       = (string) ($info['url'] ?? '');
        $lastError = (string) ($info['last_error_message'] ?? '');
        $errorAt   = isset($info['last_error_date']) ? date(DATE_ATOM, (int) $info['last_error_date']) : null;
        $recent    = $errorAt !== null && (int) $info['last_error_date'] > time() - 3600;

        $message = match (true) {
            $url === ''          => 'Telegram has no webhook for this bot, so messages never reach this app. Click Reconnect.',
            $url !== $expected   => 'Telegram sends messages to an old address (' . $url . '). Click Reconnect to use ' . $expected . '.',
            $recent              => 'Telegram could not deliver the last message (' . $lastError . '). Check that ngrok and Apache are running, then click Reconnect.',
            default              => 'Customer messages reach this app.',
        };

        return [
            'ok'              => $url === $expected && !$recent,
            'message'         => $message,
            'url'             => $url,
            'expected_url'    => $expected,
            'pending'         => (int) ($info['pending_update_count'] ?? 0),
            'last_error'      => $lastError !== '' ? $lastError : null,
            'last_error_at'   => $errorAt,
        ];
    }

    /** Name ideas for a new bot, from the assistant and company names. */
    private function botSuggestions(int $userId): array
    {
        $assistant = (new AiAssistantSetting())->find($userId);
        $company   = (new CompanyProfile())->find($userId);

        $name = trim((string) ($assistant['assistant_name'] ?? '')) ?: trim((string) ($company['company_name'] ?? '')) ?: 'My Shop Assistant';
        $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) ($company['company_name'] ?? $name)), '_'));
        if ($base === '' || !preg_match('/^[a-z]/', $base)) {
            $base = 'my_shop';
        }
        $username = substr($base, 0, 26) . '_bot';

        return ['bot_name' => mb_substr($name, 0, 64), 'bot_username' => $username];
    }

    /* ---- google calendar ------------------------------------------------- */

    /** @return array{0: array{calendar_id: string, duration: int, timezone: string}, 1: array<string, string>} */
    private function calendarInput(?array $row): array
    {
        $input  = $this->strings($_POST, ['calendar_id', 'default_appointment_duration_minutes', 'timezone',
                                          'booking_mode', 'slot_capacity', 'capacity_unit', 'open_time', 'close_time']);
        $errors = [];

        $duration = $input['default_appointment_duration_minutes'] !== ''
            ? (int) $input['default_appointment_duration_minutes']
            : (int) ($row['default_appointment_duration_minutes'] ?? 60);
        if (!in_array($duration, self::DURATIONS, true)) {
            $errors['default_appointment_duration_minutes'] = 'Choose a slot length from the list.';
        }

        if (!in_array($input['timezone'], DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Choose your timezone from the list.';
        }

        $calendarId = $input['calendar_id'] !== '' ? $input['calendar_id'] : (string) ($row['calendar_id'] ?? '');
        if (($row['connection_status'] ?? '') === 'connected') {
            if ($calendarId === '') {
                $errors['calendar_id'] = 'Choose the calendar for new appointments.';
            } elseif (mb_strlen($calendarId) > 255) {
                $errors['calendar_id'] = 'That calendar ID is too long.';
            }
        }

        // Booking rules (migration 013).
        $mode = in_array($input['booking_mode'], ['single', 'multi'], true) ? $input['booking_mode'] : 'single';
        $unit = in_array($input['capacity_unit'], ['bookings', 'pax'], true) ? $input['capacity_unit'] : 'bookings';
        $capacity = 1;
        if ($mode === 'multi') {
            $capacity = (int) $input['slot_capacity'];
            if (!preg_match('/^\d+$/', $input['slot_capacity']) || $capacity < 2 || $capacity > 1000) {
                $errors['slot_capacity'] = 'Enter how many fit in one slot (2 to 1000).';
            }
        }

        $times = [];
        foreach (['open_time', 'close_time'] as $field) {
            $times[$field] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $input[$field]) ? $input[$field] : null;
            if ($times[$field] === null) {
                $errors[$field] = 'Enter a time like 09:00.';
            }
        }
        if ($times['open_time'] !== null && $times['close_time'] !== null) {
            [$oh, $om] = array_map('intval', explode(':', $times['open_time']));
            [$ch, $cm] = array_map('intval', explode(':', $times['close_time']));
            if (($ch * 60 + $cm) - ($oh * 60 + $om) < $duration) {
                $errors['close_time'] = 'Closing time must be at least one slot (' . $duration . ' min) after opening time.';
            }
        }

        $rawDays = $_POST['open_days'] ?? [];
        if (is_string($rawDays)) {
            $rawDays = explode(',', $rawDays);
        }
        $days = array_values(array_unique(array_filter(array_map('intval', (array) $rawDays),
            static fn (int $d): bool => $d >= 1 && $d <= 7)));
        sort($days);
        if ($days === []) {
            $errors['open_days'] = 'Choose at least one day.';
        }

        return [[
            'calendar_id'   => $calendarId,
            'duration'      => $duration,
            'timezone'      => $input['timezone'],
            'booking_mode'  => $mode,
            'slot_capacity' => $capacity,
            'capacity_unit' => $mode === 'multi' ? $unit : 'bookings',
            'open_time'     => $times['open_time'],
            'close_time'    => $times['close_time'],
            'open_days'     => implode(',', $days),
        ], $errors];
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
