<?php
/**
 * OrderInvoiceService.php - the invoice that goes with a customer order.
 *
 *   order placed (buy_product)        -> invoice created, status Unpaid
 *   bank reference approved by staff  -> payment recorded, invoice Paid
 *   order cancelled / replaced        -> its unissued draft is removed and its issued invoice is voided
 *
 * The invoice uses the order's own lines and prices (what the customer was
 * quoted), so its total always equals the order total. No SST is added on
 * top, because the customer pays the order total.
 *
 * Every method is best effort: an invoice problem is logged and never stops
 * the order itself (the customer is already chatting about it).
 *
 * If the invoice tables are missing or old (migration 022 not run on this
 * database, e.g. a fresh Replit deployment), they are created first from the
 * idempotent migration files 005, 016, 018 and 022.
 */
declare(strict_types=1);

namespace Src\Services;

use Src\Models\Invoice;
use Src\Models\Order;
use Src\Models\Receipt;
use Src\Support\Database;
use Src\Support\FlowLog;
use Throwable;

final class OrderInvoiceService
{
    /** Create the Unpaid invoice of an order (once). Returns the invoice, or null if it could not be made. */
    public function createFor(int $userId, array $order): ?array
    {
        try {
            self::ensureSchema();
            $invoices = new Invoice();
            $existing = $invoices->findByOrder($userId, (int) $order['id']);
            if ($existing !== null) {
                return $existing;
            }

            $items = [];
            foreach ((new Order())->items((int) $order['id']) as $item) {
                $items[] = [
                    'product_id'  => $item['product_id'] !== null ? (int) $item['product_id'] : null,
                    'sku'         => ($item['sku'] ?? '') !== '' ? (string) $item['sku'] : null,
                    'description' => mb_substr((string) $item['product_name'], 0, 255),
                    'uom'         => null,
                    'quantity'    => (string) $item['quantity'],
                    'unit_price'  => number_format((float) $item['unit_price'], 2, '.', ''),
                    'discount'    => '0.00',
                    'amount'      => number_format((float) $item['line_total'], 2, '.', ''),
                ];
            }
            if ($items === []) {
                return null;
            }

            $currency = strtoupper((string) ($order['currency'] ?: 'MYR'));
            $total    = number_format((float) $order['total'], 2, '.', '');
            $username = trim((string) ($order['customer_username'] ?? ''));

            $id = $invoices->create($userId, [
                'party_name'      => mb_substr(trim((string) ($order['customer_name'] ?? '')) ?: ($username !== '' ? '@' . $username : 'Telegram customer'), 0, 200),
                'party_address'   => null,
                'party_phone'     => ($order['customer_phone'] ?? '') !== '' ? mb_substr((string) $order['customer_phone'], 0, 50) : null,
                'payment_terms'   => 'Due on receipt',
                'reference_no'    => (string) $order['order_no'],
                'issue_date'      => date('Y-m-d'),
                'due_date'        => date('Y-m-d'),
                'subtotal'        => $total,
                'discount_amount' => '0.00',
                'sst_rate'        => '0.00',
                'sst_amount'      => '0.00',
                'amount'          => $total,
                'currency'        => in_array($currency, Invoice::CURRENCIES, true) ? $currency : 'MYR',
                'notes'           => ($order['delivery_note'] ?? '') !== '' ? (string) $order['delivery_note'] : null,
                'order_id'        => (int) $order['id'],
                'items'           => $items,
            ], true);

            $invoice = $invoices->find($userId, $id);
            FlowLog::write('invoice.from_order', ['order_no' => $order['order_no'], 'invoice' => $invoice['invoice_number'] ?? null]);

            return $invoice;
        } catch (Throwable $e) {
            error_log('[invoice] order ' . ($order['order_no'] ?? '?') . ': ' . $e->getMessage());
            FlowLog::write('invoice.from_order_error', ['order_no' => $order['order_no'] ?? null, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** The bank reference was approved: record the payment, so the invoice becomes Paid. */
    public function markPaid(int $userId, array $order): ?array
    {
        try {
            $invoice = $this->createFor($userId, $order);
            if ($invoice === null || !in_array($invoice['status'], ['unpaid', 'partial'], true)) {
                return $invoice;
            }

            $balance = round((float) $invoice['amount'] - (float) $invoice['amount_paid'], 2);
            if ($balance > 0) {
                (new Receipt())->record($userId, (int) $invoice['id'], [
                    'amount'       => number_format($balance, 2, '.', ''),
                    'payment_date' => date('Y-m-d'),
                    'method'       => 'bank_transfer',
                    'reference'    => mb_substr((string) $order['order_no'], 0, 100),
                    'notes'        => 'Bank reference verified for order ' . $order['order_no'] . '.',
                ]);
            }

            $invoice = (new Invoice())->find($userId, (int) $invoice['id']);
            FlowLog::write('invoice.paid_from_order', ['order_no' => $order['order_no'], 'invoice' => $invoice['invoice_number'] ?? null]);

            return $invoice;
        } catch (Throwable $e) {
            error_log('[invoice] mark paid, order ' . ($order['order_no'] ?? '?') . ': ' . $e->getMessage());
            FlowLog::write('invoice.paid_from_order_error', ['order_no' => $order['order_no'] ?? null, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private static bool $schemaChecked = false;

    /** Create / update the invoice tables when this database does not have them yet. */
    public static function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        $db = Database::connection();
        $ready = (bool) $db->query(
            "SELECT to_regclass('public.invoices') IS NOT NULL
                AND to_regclass('public.receipts') IS NOT NULL
                AND to_regclass('public.document_counters') IS NOT NULL
                AND to_regclass('public.finance_settings') IS NOT NULL
                AND EXISTS (SELECT 1 FROM information_schema.columns
                             WHERE table_schema = current_schema() AND table_name = 'invoices' AND column_name = 'order_id')
                AND EXISTS (SELECT 1 FROM information_schema.columns
                             WHERE table_schema = current_schema() AND table_name = 'invoice_items' AND column_name = 'product_id')"
        )->fetchColumn();
        if ($ready || $db->inTransaction()) {
            return;
        }

        $applied = [];
        foreach (['005_create_invoices.sql', '016_add_invoice_details_and_items.sql',
                  '018_reconcile_invoice_items_print_columns.sql', '022_invoice_create_flow_and_receipts.sql'] as $file) {
            $path = APP_ROOT . '/database/migrations/' . $file;
            if (is_file($path)) {
                $db->exec((string) file_get_contents($path));
                $applied[] = $file;
            }
        }
        FlowLog::write('invoice.schema_created', ['migrations' => $applied]);
    }

    /** Older name, kept so existing callers keep working. */
    public function removeFor(int $userId, array $orderNos): void
    {
        $this->cancelForOrders($userId, $orderNos);
    }

    /**
     * Cancel invoices for orders that were cancelled or replaced.
     * Unissued drafts can be removed; numbered invoices are retained as voided records.
     */
    public function cancelForOrders(int $userId, array $orderNos): void
    {
        $orderNos = array_values(array_filter(array_map('strval', $orderNos)));
        if ($orderNos === []) {
            return;
        }

        try {
            $marks = implode(',', array_fill(0, count($orderNos), '?'));
            Database::transaction(static function ($pdo) use ($userId, $orderNos, $marks): void {
                $params = array_merge([$userId, $userId], $orderNos);
                $whereOrders = "order_id IN (SELECT id FROM orders WHERE user_id = ? AND order_no IN ($marks))";

                $drafts = $pdo->prepare(
                    "DELETE FROM invoices
                      WHERE user_id = ? AND status = 'draft' AND amount_paid = 0
                        AND $whereOrders
                        AND NOT EXISTS (SELECT 1 FROM receipts r WHERE r.invoice_id = invoices.id)"
                );
                $drafts->execute($params);

                $issued = $pdo->prepare(
                    "UPDATE invoices
                        SET status = 'voided', updated_at = CURRENT_TIMESTAMP
                      WHERE user_id = ? AND status = 'unpaid' AND amount_paid = 0
                        AND $whereOrders
                        AND NOT EXISTS (SELECT 1 FROM receipts r WHERE r.invoice_id = invoices.id)"
                );
                $issued->execute($params);
            });
        } catch (Throwable $e) {
            error_log('[invoice] remove for cancelled orders: ' . $e->getMessage());
        }
    }
}
