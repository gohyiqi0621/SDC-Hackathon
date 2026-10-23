<?php
/**
 * FinanceSetting.php - per-company invoice settings (one row per user).
 *
 * SST: a business registered for SST charges one fixed rate on every new
 * invoice (copied onto the invoice when it is saved, so changing the setting
 * later never alters old invoices). Not registered = no SST at all.
 */
declare(strict_types=1);

namespace Src\Models;

use Src\Support\Database;

final class FinanceSetting
{
    /** Rates a Malaysian business usually charges (the field also accepts others). */
    public const COMMON_SST_RATES = ['8', '6', '10', '5'];

    /** @return array{sst_registered: bool, sst_rate: string} defaults when nothing is saved */
    public function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT sst_registered, sst_rate FROM finance_settings WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return [
            'sst_registered' => (bool) ($row['sst_registered'] ?? false),
            'sst_rate'       => (string) ($row['sst_rate'] ?? '8.00'),
        ];
    }

    /** The rate to charge on a new invoice: "0.00" when not SST-registered. */
    public function sstRate(int $userId): string
    {
        $s = $this->forUser($userId);

        return $s['sst_registered'] ? number_format((float) $s['sst_rate'], 2, '.', '') : '0.00';
    }

    public function save(int $userId, bool $registered, string $rate): void
    {
        Database::connection()->prepare(
            'INSERT INTO finance_settings (user_id, sst_registered, sst_rate)
             VALUES (?, ?, ?)
             ON CONFLICT (user_id) DO UPDATE
                SET sst_registered = EXCLUDED.sst_registered,
                    sst_rate       = EXCLUDED.sst_rate,
                    updated_at     = CURRENT_TIMESTAMP'
        )->execute([$userId, $registered ? 't' : 'f', $rate]);
    }
}
