<?php
/**
 * DocumentCounter.php - running numbers per company, per kind, per year.
 *
 *   (new DocumentCounter())->next($userId, 'invoice', 'INV')   // "INV-2026-0001"
 *   (new DocumentCounter())->next($userId, 'receipt', 'RCPT')  // "RCPT-2026-0001"
 *
 * One atomic UPSERT, so two people creating at the same moment can never get
 * the same number. Numbering restarts at 0001 every year.
 */
declare(strict_types=1);

namespace Src\Models;

use Src\Support\Database;

final class DocumentCounter
{
    public function next(int $userId, string $kind, string $prefix): string
    {
        $year = (int) date('Y');

        $stmt = Database::connection()->prepare(
            'INSERT INTO document_counters (user_id, kind, year, last_no)
             VALUES (?, ?, ?, 1)
             ON CONFLICT (user_id, kind, year) DO UPDATE SET last_no = document_counters.last_no + 1
             RETURNING last_no'
        );
        $stmt->execute([$userId, $kind, $year]);

        return sprintf('%s-%d-%04d', $prefix, $year, (int) $stmt->fetchColumn());
    }
}
