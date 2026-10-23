<?php
/**
 * Receipt.php - payments received against an invoice.
 *
 * Each payment is one receipt (RCPT-2026-0001). Recording one also updates
 * the invoice's amount_paid and status (unpaid -> partial -> paid) in the
 * same transaction, with the invoice row locked so two payments at once
 * cannot over-pay it. Receipts are never edited or deleted: they are the
 * money history.
 */
declare(strict_types=1);

namespace Src\Models;

use RuntimeException;
use Src\Support\Database;

final class Receipt
{
    /** Payment methods offered, value => label. */
    public const METHODS = [
        'cash'          => 'Cash',
        'bank_transfer' => 'Bank transfer',
        'duitnow'       => 'DuitNow / QR',
        'card'          => 'Card',
        'cheque'        => 'Cheque',
        'other'         => 'Other',
    ];

    private const COLUMNS = 'r.id, r.invoice_id, r.receipt_number, r.amount, r.currency, r.payment_date,
                             r.method, r.reference, r.notes, r.created_at';

    /** @return array<int, array> an invoice's receipts, oldest first */
    public function forInvoice(int $userId, int $invoiceId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM receipts r
              WHERE r.user_id = ? AND r.invoice_id = ?
              ORDER BY r.payment_date, r.id'
        );
        $stmt->execute([$userId, $invoiceId]);

        return $stmt->fetchAll();
    }

    /** One receipt with the invoice details it needs for printing. */
    public function find(int $userId, int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ', i.invoice_number, i.party_name, i.party_address, i.party_phone,
                    i.amount AS invoice_amount, i.amount_paid AS invoice_paid
               FROM receipts r
               JOIN invoices i ON i.id = r.invoice_id AND i.user_id = r.user_id
              WHERE r.user_id = ? AND r.id = ?
              LIMIT 1'
        );
        $stmt->execute([$userId, $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Record a payment and return the new receipt id.
     *
     * @param array{amount: string, payment_date: string, method: string, reference: ?string, notes: ?string} $data
     * @throws RuntimeException with a message for the user (wrong status, over-payment)
     */
    public function record(int $userId, int $invoiceId, array $data): int
    {
        return Database::transaction(function () use ($userId, $invoiceId, $data): int {
            $pdo = Database::connection();

            $lock = $pdo->prepare('SELECT status, amount, amount_paid, currency FROM invoices WHERE user_id = ? AND id = ? FOR UPDATE');
            $lock->execute([$userId, $invoiceId]);
            $invoice = $lock->fetch();

            if ($invoice === false || !in_array($invoice['status'], ['unpaid', 'partial'], true)) {
                throw new RuntimeException('Payments can only be recorded on an unpaid or partly paid invoice.');
            }

            $balance = round((float) $invoice['amount'] - (float) $invoice['amount_paid'], 2);
            if ((float) $data['amount'] - $balance > 0.001) {
                throw new RuntimeException('That is more than the balance due (' . number_format($balance, 2) . ').');
            }

            $number = (new DocumentCounter())->next($userId, 'receipt', 'RCPT');

            $stmt = $pdo->prepare(
                'INSERT INTO receipts (user_id, invoice_id, receipt_number, amount, currency, payment_date, method, reference, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 RETURNING id'
            );
            $stmt->execute([
                $userId, $invoiceId, $number, $data['amount'], $invoice['currency'],
                $data['payment_date'], $data['method'], $data['reference'], $data['notes'],
            ]);
            $id = (int) $stmt->fetchColumn();

            $pdo->prepare(
                "UPDATE invoices
                    SET amount_paid = amount_paid + ?,
                        status      = CASE WHEN amount_paid + ? >= amount THEN 'paid' ELSE 'partial' END,
                        updated_at  = CURRENT_TIMESTAMP
                  WHERE user_id = ? AND id = ?"
            )->execute([$data['amount'], $data['amount'], $userId, $invoiceId]);

            return $id;
        });
    }
}
