<?php
/**
 * Invoice.php - every query against invoices.
 *
 * The business CREATES invoices for its customers from its own Products &
 * Services. Life cycle:
 *   draft  (no number yet, still editable)
 *   -> unpaid  (created: numbered INV-2026-0001, locked for Users)
 *   -> partial -> paid   (driven by receipts, see Receipt)
 *
 * Every method takes the owner's user id and filters on it, so one company
 * can never see or change another company's invoices. Search, filter and
 * sort values are bound or picked from a fixed list - never pasted into SQL.
 */
declare(strict_types=1);

namespace Src\Models;

use Src\Support\Database;

final class Invoice
{
    public const STATUSES = ['draft', 'unpaid', 'partial', 'paid'];

    /** Label and badge modifier for each status, shared by the views. */
    public const STATUS_LABELS = [
        'draft'   => ['Draft',          'badge--muted'],
        'unpaid'  => ['Unpaid',         'badge--warn'],
        'partial' => ['Partially paid', 'badge--info'],
        'paid'    => ['Paid',           'badge--success'],
    ];

    /**
     * Older invoices (from the upload flow) may be 'purchase'; everything
     * created now is 'sales'. Kept for the print template's title.
     * [label, who the other party is, icon]
     */
    public const TYPES = [
        'purchase' => ['Purchase', 'Supplier', 'fa-solid fa-cart-arrow-down'],
        'sales'    => ['Sales',    'Customer', 'fa-solid fa-hand-holding-dollar'],
    ];

    public const CURRENCIES = ['MYR', 'SGD', 'USD', 'EUR', 'GBP', 'IDR', 'THB', 'CNY'];

    /** Payment terms offered in the create popup: label => days until due. */
    public const PAYMENT_TERMS = [
        'Due on receipt'        => 0,
        'COD (cash on delivery)' => 0,
        'Net 7 days'            => 7,
        'Net 14 days'           => 14,
        'Net 30 days'           => 30,
        'Net 45 days'           => 45,
        'Net 60 days'           => 60,
    ];

    /** Sort keys the page offers, mapped to safe ORDER BY clauses. */
    public const SORTS = [
        'newest'      => ['Newest first',        'created_at DESC, id DESC'],
        'oldest'      => ['Oldest first',        'created_at ASC, id ASC'],
        'due_soon'    => ['Due date (soonest)',  'due_date ASC NULLS LAST, id DESC'],
        'amount_desc' => ['Amount (high-low)',   'amount DESC, id DESC'],
        'amount_asc'  => ['Amount (low-high)',   'amount ASC, id ASC'],
    ];

    private const COLUMNS = 'id, type, invoice_number, party_name, party_address, party_phone, payment_terms,
                             reference_no, issue_date, due_date, subtotal, discount_amount, sst_rate,
                             sst_amount, amount, amount_paid, currency, status, notes, order_id, issued_at,
                             file_path, file_name, file_mime, file_size, created_at, updated_at';

    /** The columns InvoiceService produces for a create / save. */
    private const HEADER_COLUMNS = ['party_name', 'party_address', 'party_phone', 'payment_terms', 'reference_no',
                                    'issue_date', 'due_date', 'subtotal', 'discount_amount', 'sst_rate',
                                    'sst_amount', 'amount', 'currency', 'notes', 'order_id'];

    /**
     * One page of a user's invoices.
     *
     * @param array{q?:string, status?:string, sort?:string} $filters
     * @return array{items: array<int, array>, total: int}
     */
    public function search(int $userId, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($userId, $filters);

        $count = Database::connection()->prepare('SELECT COUNT(*) FROM invoices WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $order = self::SORTS[$filters['sort'] ?? 'newest'][1] ?? self::SORTS['newest'][1];

        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ',
                    (SELECT COUNT(*) FROM invoice_items i WHERE i.invoice_id = invoices.id AND i.product_id IS NULL) AS loose_items
               FROM invoices
              WHERE ' . $where . '
              ORDER BY ' . $order . '
              LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /** Total invoices the user owns, ignoring filters. */
    public function countForUser(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM invoices WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Numbers for the stat cards (drafts are not counted as issued). The app
     * works in MYR, so outstanding money is summed as one figure.
     *
     * @return array{issued: int, paid: int, drafts: int, outstanding_count: int, outstanding_amount: string}
     */
    public function stats(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FILTER (WHERE status <> 'draft')                         AS issued,
                    COUNT(*) FILTER (WHERE status = 'paid')                           AS paid,
                    COUNT(*) FILTER (WHERE status = 'draft')                          AS drafts,
                    COUNT(*) FILTER (WHERE status IN ('unpaid', 'partial'))           AS outstanding_count,
                    COALESCE(SUM(amount - amount_paid) FILTER (WHERE status IN ('unpaid', 'partial')), 0) AS outstanding_amount
               FROM invoices
              WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];

        return [
            'issued'             => (int) ($row['issued'] ?? 0),
            'paid'               => (int) ($row['paid'] ?? 0),
            'drafts'             => (int) ($row['drafts'] ?? 0),
            'outstanding_count'  => (int) ($row['outstanding_count'] ?? 0),
            'outstanding_amount' => (string) ($row['outstanding_amount'] ?? '0'),
        ];
    }

    public function find(int $userId, int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM invoices WHERE user_id = ? AND id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $id]);

        return $stmt->fetch() ?: null;
    }

    /** The invoice already made from an order, if any. */
    public function findByOrder(int $userId, int $orderId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM invoices WHERE user_id = ? AND order_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $orderId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Customers invoiced before (latest details per name), for the
     * "start typing a customer" suggestions in the create popup.
     *
     * @return array<int, array{party_name: string, party_address: ?string, party_phone: ?string}>
     */
    public function customers(int $userId, int $limit = 200): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT party_name, party_address, party_phone
               FROM (SELECT DISTINCT ON (LOWER(party_name)) party_name, party_address, party_phone, created_at
                       FROM invoices
                      WHERE user_id = ? AND type = 'sales' AND party_name <> ''
                      ORDER BY LOWER(party_name), created_at DESC) latest
              ORDER BY created_at DESC
              LIMIT " . max(1, $limit)
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll();
    }

    /**
     * Insert an invoice and its items in one transaction. With $issue the
     * invoice is created straight away (numbered, status unpaid); otherwise
     * it is saved as a draft.
     *
     * @param array<string, mixed> $data validated by InvoiceService (incl. 'items')
     */
    public function create(int $userId, array $data, bool $issue): int
    {
        $values = array_map(static fn (string $c) => $data[$c] ?? null, self::HEADER_COLUMNS);

        return Database::transaction(function () use ($userId, $data, $issue, $values): int {
            $stmt = Database::connection()->prepare(
                "INSERT INTO invoices (user_id, type, status, " . implode(', ', self::HEADER_COLUMNS) . ")
                 VALUES (?, 'sales', 'draft'" . str_repeat(', ?', count(self::HEADER_COLUMNS)) . ")
                 RETURNING id"
            );
            $stmt->execute(array_merge([$userId], $values));
            $id = (int) $stmt->fetchColumn();

            (new InvoiceItem())->replace($id, $data['items'] ?? []);

            if ($issue) {
                $this->issue($userId, $id);
            }

            return $id;
        });
    }

    /**
     * Save changes to a draft, or to an unpaid invoice that has no payments
     * yet. With $issue a draft is created (numbered) as well.
     *
     * @param array<string, mixed> $data validated by InvoiceService (incl. 'items')
     * @return bool false when the invoice can no longer be changed
     */
    public function save(int $userId, int $id, array $data, bool $issue): bool
    {
        $set    = implode(', ', array_map(static fn (string $c) => $c . ' = ?', self::HEADER_COLUMNS));
        $values = array_map(static fn (string $c) => $data[$c] ?? null, self::HEADER_COLUMNS);

        return Database::transaction(function () use ($userId, $id, $issue, $set, $values, $data): bool {
            $stmt = Database::connection()->prepare(
                "UPDATE invoices SET " . $set . ", updated_at = CURRENT_TIMESTAMP
                  WHERE user_id = ? AND id = ?
                    AND (status = 'draft' OR (status = 'unpaid' AND amount_paid = 0))"
            );
            $stmt->execute(array_merge($values, [$userId, $id]));

            if ($stmt->rowCount() === 0) {
                return false;
            }

            (new InvoiceItem())->replace($id, $data['items'] ?? []);

            if ($issue) {
                $this->issue($userId, $id);
            }

            return true;
        });
    }

    /** Draft -> unpaid: give it the next number. Call inside a transaction. */
    private function issue(int $userId, int $id): void
    {
        $number = (new DocumentCounter())->next($userId, 'invoice', 'INV');

        Database::connection()->prepare(
            "UPDATE invoices
                SET status = 'unpaid', invoice_number = ?, issued_at = CURRENT_TIMESTAMP,
                    issue_date = COALESCE(issue_date, CURRENT_DATE), updated_at = CURRENT_TIMESTAMP
              WHERE user_id = ? AND id = ? AND status = 'draft'"
        )->execute([$number, $userId, $id]);
    }

    /**
     * Remove a draft or an unpaid invoice that has no payments. Paid history
     * is never deleted (receipts also block it at database level).
     */
    public function delete(int $userId, int $id): bool
    {
        $stmt = Database::connection()->prepare(
            "DELETE FROM invoices
              WHERE user_id = ? AND id = ? AND status IN ('draft', 'unpaid') AND amount_paid = 0
                AND NOT EXISTS (SELECT 1 FROM receipts r WHERE r.invoice_id = invoices.id)"
        );
        $stmt->execute([$userId, $id]);

        return $stmt->rowCount() > 0;
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private function where(int $userId, array $filters): array
    {
        $where  = ['user_id = ?'];
        $params = [$userId];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            // Escape LIKE wildcards so "50%" searches for the text "50%".
            $like     = '%' . addcslashes($q, '%_\\') . '%';
            $where[]  = '(invoice_number ILIKE ? OR party_name ILIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $where[]  = 'status = ?';
            $params[] = $status;
        }

        return [implode(' AND ', $where), $params];
    }
}
