<?php
/**
 * SetupStatus.php - what is done and what is missing in the AI assistant setup.
 *
 * Used by the Setup checklists (Customer Assistant > Setup, Staff Assistant >
 * Setup) and by the sidebar (a dot next to an assistant whose setup is not
 * finished). Each step: key, label, hint, done, optional, fix_url, fix_label.
 * Results are kept for the rest of the request.
 */
declare(strict_types=1);

namespace Src\Services;

use Src\Models\AiAssistantSetting;
use Src\Models\CompanyProfile;
use Src\Models\GmailConnection;
use Src\Models\GoogleCalendarIntegration;
use Src\Models\Product;
use Src\Models\StaffAiAssistantSetting;
use Src\Models\TelegramIntegration;
use Throwable;

final class SetupStatus
{
    /** @var array<string, array> */
    private static array $cache = [];

    /** @return list<array> */
    public static function customer(int $userId): array
    {
        return self::$cache['customer:' . $userId] ??= self::buildCustomer($userId);
    }

    /** @return list<array> */
    public static function staff(int $userId): array
    {
        return self::$cache['staff:' . $userId] ??= self::buildStaff($userId);
    }

    /** True when every required step is done. */
    public static function complete(array $steps): bool
    {
        foreach ($steps as $step) {
            if (!$step['optional'] && !$step['done']) {
                return false;
            }
        }
        return true;
    }

    /** @return array{done: int, total: int} required steps only */
    public static function progress(array $steps): array
    {
        $required = array_filter($steps, static fn (array $s): bool => !$s['optional']);
        return ['done' => count(array_filter($required, static fn (array $s): bool => $s['done'])), 'total' => count($required)];
    }

    /* ------------------------------------------------------------------ */

    private static function buildCustomer(int $userId): array
    {
        $assistant = self::row(new AiAssistantSetting(), $userId);
        $telegram  = self::row(new TelegramIntegration(), $userId);
        $calendar  = self::row(new GoogleCalendarIntegration(), $userId);
        $setup     = url('customer', ['action' => 'settings']);
        $connect   = url('customer', ['action' => 'connections']);
        $company   = self::row(new CompanyProfile(), $userId);
        $payment   = CompanyProfile::paymentText($company);

        try {
            $products = (new Product())->countForUser($userId);
        } catch (Throwable $e) {
            $products = 0;
        }

        return [
            self::companyStep($userId),
            self::step('assistant', 'AI model and key saved', 'Choose the AI model and paste its secret key.',
                $assistant !== [] && !empty($assistant['api_key_encrypted']),
                false, $setup . '#assistant', 'Set up'),
            self::step('instructions', 'Instructions (SOPs) written', 'How the assistant answers, sells and handles problems. "Generate with AI" can write them for you.',
                trim((string) ($assistant['system_instructions'] ?? '')) !== '', true, $setup . '#assistant', 'Write'),
            self::step('payment', 'Payment method set', $payment !== '' ? $payment : 'The bank account (or e-wallet) customers pay to, and an optional payment QR.',
                $payment !== '' || !empty($company['payment_qr_url']), false, $setup . '#payment', 'Set up'),
            self::step('products', 'Products & services added', $products > 0 ? $products . ' item' . ($products === 1 ? '' : 's') . ' the assistant can sell and answer about.' : 'Add what you sell, with prices, so the assistant can answer and take orders.',
                $products > 0, false, url('products'), 'Add'),
            self::step('telegram', 'Telegram bot connected', 'Customers chat with your assistant on Telegram.',
                ($telegram['connection_status'] ?? '') === 'connected', false, $connect . '#telegram', 'Connect'),
            self::step('calendar', 'Google Calendar for bookings', 'Only needed if customers book appointments or classes.',
                ($calendar['connection_status'] ?? '') === 'connected' && !empty($calendar['calendar_id']), true, $connect . '#calendar', 'Connect'),
        ];
    }

    private static function buildStaff(int $userId): array
    {
        $assistant = self::row(new StaffAiAssistantSetting(), $userId);
        $telegram  = self::row(new TelegramIntegration(), $userId);
        $setup     = url('staff', ['action' => 'settings']);
        $connect   = url('staff', ['action' => 'connections']);
        $functions = array_key_exists('functions', $assistant)
            ? array_values(array_filter(explode(',', (string) $assistant['functions']))) : [];
        $wantsMail = in_array('check_email', $functions, true);

        try {
            $gmail = (new GmailConnection())->find($userId);
        } catch (Throwable $e) {
            $gmail = null;
        }

        return [
            self::companyStep($userId),
            self::step('assistant', 'AI model and key saved', 'Choose the AI model and paste its secret key.',
                $assistant !== [] && !empty($assistant['api_key_encrypted']),
                false, $setup . '#assistant', 'Set up'),
            self::step('functions', 'Functions chosen', $functions !== [] ? count($functions) . ' function' . (count($functions) === 1 ? '' : 's') . ' enabled for the staff assistant.' : 'Select the functions the staff assistant may use (email, invoices, claims, orders).',
                $functions !== [], false, $setup . '#assistant', 'Choose'),
            self::step('gmail', 'Gmail connected', $wantsMail ? 'Needed for "Check email".' : 'Only needed if you switch on "Check email".',
                $gmail !== null, !$wantsMail, $connect . '#gmail', 'Connect'),
            self::step('alerts', 'Telegram staff alerts', ($telegram['connection_status'] ?? '') === 'connected'
                    ? 'Get "new receipt to review" messages in your own Telegram.'
                    : 'Connect the customer Telegram bot first, then link your own Telegram.',
                !empty($telegram['staff_telegram_chat_id']), true, $connect . '#alerts', 'Link'),
        ];
    }

    private static function companyStep(int $userId): array
    {
        $company = self::row(new CompanyProfile(), $userId);
        return self::step('company', 'Company profile saved', 'Your business name, category and background. Both assistants use it.',
            trim((string) ($company['company_name'] ?? '')) !== '' && trim((string) ($company['company_background'] ?? '')) !== '',
            false, url('company'), 'Fill in');
    }

    private static function step(string $key, string $label, string $hint, bool $done, bool $optional, string $fixUrl, string $fixLabel): array
    {
        return ['key' => $key, 'label' => $label, 'hint' => $hint, 'done' => $done, 'optional' => $optional,
                'fix_url' => $fixUrl, 'fix_label' => $fixLabel];
    }

    private static function row(object $model, int $userId): array
    {
        try {
            return $model->find($userId) ?? [];
        } catch (Throwable $e) {
            return [];  // table not created yet (migrations)
        }
    }

    private static function on(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
