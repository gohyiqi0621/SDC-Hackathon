<?php
/**
 * CompanyProfile.php - company_profiles (one row per user). See UserSettingsRow for the query rules.
 *
 *   Company Profile page   company_name, logo, category, background, and the
 *                          details printed on the official receipt:
 *                          registration_no, company_address, company_phone,
 *                          company_email (migration 019)
 *   Payment Method         payment_bank, payment_account_no, payment_account_name
 *   (Customer Assistant    (migration 020), payment_instructions (= payment
 *    > Setup)              notes) and payment_qr_url
 *
 * ensureColumns() adds the 019/020 columns when those migrations have not
 * been run yet, so saving never fails.
 */
declare(strict_types=1);

namespace Src\Models;

use Src\Support\Database;

final class CompanyProfile extends UserSettingsRow
{
    public const RECEIPT_FIELDS = ['registration_no', 'company_address', 'company_phone', 'company_email'];
    public const PAYMENT_FIELDS = ['payment_bank', 'payment_account_no', 'payment_account_name'];

    /** Banks and e-wallets in Malaysia for the Payment Method dropdown. */
    public const BANKS = [
        'Banks' => [
            'Maybank', 'CIMB Bank', 'Public Bank', 'RHB Bank', 'Hong Leong Bank', 'AmBank', 'Bank Islam', 'Bank Rakyat',
            'Bank Muamalat', 'Affin Bank', 'Alliance Bank', 'OCBC Bank', 'HSBC Bank', 'UOB Bank', 'Standard Chartered',
            'Agrobank', 'Bank Simpanan Nasional (BSN)', 'MBSB Bank', 'Al Rajhi Bank', 'Kuwait Finance House (KFH)',
            'Citibank', 'Bank of China', 'AEON Bank', 'GXBank', 'Boost Bank',
        ],
        'E-wallets & DuitNow' => [
            'DuitNow (mobile no. / IC / business reg.)', "Touch 'n Go eWallet", 'Boost', 'GrabPay', 'ShopeePay', 'BigPay',
        ],
    ];

    protected function table(): string
    {
        return 'company_profiles';
    }

    protected function writable(): array
    {
        return array_merge(['company_name', 'company_logo_url', 'company_background', 'company_description', 'business_category',
                'payment_instructions', 'payment_qr_url'], self::RECEIPT_FIELDS, self::PAYMENT_FIELDS);
    }

    /** @return list<string> every bank / e-wallet name in BANKS */
    public static function bankNames(): array
    {
        return array_merge(...array_values(self::BANKS));
    }

    /**
     * How a customer pays, as one text for the AI and Telegram:
     * "Bank transfer to Maybank, account 5123 4567 8901 (Ah Hock Trading Sdn Bhd). <notes>"
     * Falls back to the notes alone (older profiles only had free text).
     */
    public static function paymentText(array $company): string
    {
        $bank = trim((string) ($company['payment_bank'] ?? ''));
        $acc  = trim((string) ($company['payment_account_no'] ?? ''));
        $name = trim((string) ($company['payment_account_name'] ?? ''));
        $note = trim((string) ($company['payment_instructions'] ?? ''));

        $parts = [];
        if ($bank !== '' && $acc !== '') {
            $wallet  = !in_array($bank, self::BANKS['Banks'], true);
            $parts[] = ($wallet ? 'Pay by ' . $bank . ' to ' : 'Bank transfer to ' . $bank . ', account no. ') . $acc
                     . ($name !== '' ? ' (' . $name . ')' : '') . '.';
        }
        if ($note !== '') {
            $parts[] = $note;
        }
        return implode(' ', $parts);
    }

    /** Add the 019 / 020 columns if those migrations have not run (safe to call again). */
    public static function ensureColumns(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $columns = [
            'registration_no' => 'VARCHAR(60)', 'company_address' => 'TEXT', 'company_phone' => 'VARCHAR(40)',
            'company_email' => 'VARCHAR(150)', 'payment_bank' => 'VARCHAR(80)', 'payment_account_no' => 'VARCHAR(40)',
            'payment_account_name' => 'VARCHAR(150)',
        ];
        $db   = Database::connection();
        $have = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'company_profiles'")
                   ->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($columns as $column => $type) {
            if (!in_array($column, $have, true)) {
                $db->exec('ALTER TABLE company_profiles ADD COLUMN IF NOT EXISTS ' . $column . ' ' . $type);
            }
        }
        $done = true;
    }

    /** @deprecated use ensureColumns() */
    public static function ensureReceiptColumns(): void
    {
        self::ensureColumns();
    }
}
