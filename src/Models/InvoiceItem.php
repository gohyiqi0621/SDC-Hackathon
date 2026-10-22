<?php
/**
 * InvoiceItem.php - the line items of an invoice.
 *
 * Items have no user_id of their own: they are always reached through an
 * invoice the caller has already loaded for that user (Invoice::find), so
 * ownership is checked once, on the invoice.
 */
declare(strict_types=1);

namespace Src\Models;

use Src\Support\Database;

final class InvoiceItem
{
    /** The editable columns, in form / template order. */
    public const FIELDS = ['sku', 'description', 'uom', 'quantity', 'unit_price', 'discount', 'amount'];

    /** @return array<int, array> the invoice's items, in their original order */
    public function forInvoice(int $invoiceId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, position, sku, description, uom, quantity, unit_price, discount, amount
               FROM invoice_items
              WHERE invoice_id = ?
              ORDER BY position, id'
        );
        $stmt->execute([$invoiceId]);

        return $stmt->fetchAll();
    }

    /**
     * Replace all of an invoice's items. Call inside the same transaction as
     * the invoice insert / update, so the two never disagree.
     *
     * @param array<int, array<string, mixed>> $items validated by InvoiceService
     */
    public function replace(int $invoiceId, array $items): void
    {
        $pdo = Database::connection();

        $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);

        if ($items === []) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, position, sku, description, uom, quantity, unit_price, discount, amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach (array_values($items) as $i => $item) {
            $stmt->execute([
                $invoiceId,
                $i + 1,
                $item['sku'],
                $item['description'],
                $item['uom'],
                $item['quantity'],
                $item['unit_price'],
                $item['discount'],
                $item['amount'],
            ]);
        }
    }
}
