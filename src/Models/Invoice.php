<?php
/**
 * Invoice.php - every query against invoices.
 *
 * Every method takes the owner's user id and filters on it, so one user can
 * never see or change another user's invoices. Search, filter and sort
 * values are bound or picked from a fixed list - never pasted into SQL.
 */
declare(strict_types=1);

namespace Src\Models;

use Src\Support\Database;

final class Invoice
{
    public const STATUSES   = ['unpaid', 'partial', 'paid'];

    /**
     * purchase = a supplier billed us (we owe them);
     * sales    = we billed a customer (they owe us).
     * [label, who the other party is, icon]
     */
    public const TYPES = [
        'purchase' => ['Purchase', 'Supplier', 'fa-solid fa-cart-arrow-down'],
        'sales'    => ['Sales',    'Customer', 'fa-solid fa-hand-holding-dollar'],
    ];

    /** Label and badge modifier for each status, shared by the views. */
    public const STATUS_LABELS = [
        'unpaid'  => ['Unpaid',         'badge--warn'],
        'partial' => ['Partially paid', 'badge--info'],
        'paid'    => ['Paid',           'badge--success'],
    ];
    public const CURRENCIES = ['MYR', 'SGD', 'USD', 'EUR', 'GBP', 'IDR', 'THB', 'CNY'];

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
                             sst_amount, amount, currency, status, notes, file_path, file_name,
                             file_mime, file_size, created_at, updated_at';

    /** The header columns InvoiceService validates (everything but status and the file). */
    private const DETAIL_COLUMNS = ['type', 'invoice_number', 'party_name', 'party_address', 'party_phone',
                                    'payment_terms', 'reference_no', 'issue_date', 'due_date', 'subtotal',
                                    'discount_amount', 'sst_rate', 'sst_amount', 'amount', 'currency', 'notes'];

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
            'SELECT ' . self::COLUMNS . '
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
     * Numbers for the stat cards. The app works in MYR, so outstanding money
     * is summed as one figure rather than per currency.
     *
     * @return array{issued: int, paid: int, outstanding_count: int, outstanding_amount: string}
     */
    public function stats(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*)                                          AS issued,
                    COUNT(*) FILTER (WHERE status = 'paid')           AS paid,
                    COUNT(*) FILTER (WHERE status <> 'paid')          AS outstanding_count,
                    COALESCE(SUM(amount) FILTER (WHERE status <> 'paid'), 0) AS outstanding_amount
               FROM invoices
              WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];

        return [
            'issued'             => (int) ($row['issued'] ?? 0),
            'paid'               => (int) ($row['paid'] ?? 0),
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

    public function numberExists(int $userId, string $number, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT 1 FROM invoices WHERE user_id = ? AND LOWER(invoice_number) = LOWER(?)';
        $params = [$userId, $number];

        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        $stmt = Database::connection()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Insert an invoice and its line items in one transaction.
     *
     * @param array<string, mixed> $data validated by InvoiceService (incl. 'items'), plus the file columns
     */
    public function create(int $userId, array $data): int
    {
        $columns = array_merge(self::DETAIL_COLUMNS, ['status', 'file_path', 'file_name', 'file_mime', 'file_size']);
        $values  = array_map(static fn (string $c) => $data[$c] ?? null, $columns);

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO invoices (user_id, ' . implode(', ', $columns) . ')
                 VALUES (?' . str_repeat(', ?', count($columns)) . ')
                 RETURNING id'
            );
            $stmt->execute(array_merge([$userId], $values));
            $id = (int) $stmt->fetchColumn();

            (new InvoiceItem())->replace($id, $data['items'] ?? []);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $id;
    }

    /**
     * Update an invoice and replace its line items in one transaction.
     *
     * @param array<string, mixed> $data validated by InvoiceService (incl. 'items'), plus the file columns
     */
    public function update(int $userId, int $id, array $data): bool
    {
        $columns = array_merge(self::DETAIL_COLUMNS, ['status', 'file_path', 'file_name', 'file_mime', 'file_size']);
        $set     = implode(', ', array_map(static fn (string $c) => $c . ' = ?', $columns));
        $values  = array_map(static fn (string $c) => $data[$c] ?? null, $columns);

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE invoices SET ' . $set . ', updated_at = CURRENT_TIMESTAMP
                  WHERE user_id = ? AND id = ?'
            );
            $stmt->execute(array_merge($values, [$userId, $id]));
            $updated = $stmt->rowCount() > 0;

            if ($updated) {
                (new InvoiceItem())->replace($id, $data['items'] ?? []);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $updated;
    }

    /** Mark an unpaid or partly paid invoice as fully paid. */
    public function markPaid(int $userId, int $id): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE invoices
                SET status = 'paid', updated_at = CURRENT_TIMESTAMP
              WHERE user_id = ? AND id = ? AND status <> 'paid'"
        );
        $stmt->execute([$userId, $id]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $userId, int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM invoices WHERE user_id = ? AND id = ?');
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
            $where[]  = '(invoice_number ILIKE ? OR party_name ILIKE ? OR file_name ILIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $where[]  = 'status = ?';
            $params[] = $status;
        }

        $type = (string) ($filters['type'] ?? '');
        if (isset(self::TYPES[$type])) {
            $where[]  = 'type = ?';
            $params[] = $type;
        }

        return [implode(' AND ', $where), $params];
    }
}
