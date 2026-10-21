<?php
/**
 * Claim.php - every query against claims.
 *
 * Every method takes the owner's user id and filters on it, so one user can
 * never see or change another user's claims. Search, filter and sort values
 * are bound or picked from a fixed list - never pasted into SQL.
 */
declare(strict_types=1);

namespace Src\Models;

use Src\Support\Database;

final class Claim
{
    public const STATUSES   = ['submitted', 'approved', 'rejected', 'reimbursed'];
    public const CURRENCIES = Invoice::CURRENCIES;

    /** Label and badge modifier for each status, shared by the views. */
    public const STATUS_LABELS = [
        'submitted'  => ['Submitted',  'badge--info'],
        'approved'   => ['Approved',   'badge--warn'],
        'rejected'   => ['Rejected',   'badge--danger'],
        'reimbursed' => ['Reimbursed', 'badge--success'],
    ];

    /** Label and icon for each category. */
    public const CATEGORIES = [
        'travel' => ['Travel',          'fa-solid fa-car'],
        'meals'  => ['Meals',           'fa-solid fa-utensils'],
        'office' => ['Office supplies', 'fa-solid fa-paperclip'],
        'other'  => ['Other',           'fa-solid fa-ellipsis'],
    ];

    /**
     * Which status each review action moves a claim to, and from which
     * statuses it is allowed. Anything else is refused.
     */
    public const TRANSITIONS = [
        'approve'   => ['approved',   ['submitted', 'rejected']],
        'reject'    => ['rejected',   ['submitted', 'approved']],
        'reimburse' => ['reimbursed', ['approved']],
        'reopen'    => ['submitted',  ['approved', 'rejected']],
    ];

    /** Sort keys the page offers, mapped to safe ORDER BY clauses. */
    public const SORTS = [
        'newest'      => ['Newest first',       'created_at DESC, id DESC'],
        'oldest'      => ['Oldest first',       'created_at ASC, id ASC'],
        'expense'     => ['Expense date',       'expense_date DESC, id DESC'],
        'amount_desc' => ['Amount (high-low)',  'amount DESC, id DESC'],
        'amount_asc'  => ['Amount (low-high)',  'amount ASC, id ASC'],
    ];

    private const COLUMNS = 'id, claimant_name, title, category, expense_date, amount, currency, status,
                             notes, file_path, file_name, file_mime, file_size, decided_at,
                             reimbursed_at, created_at, updated_at';

    /**
     * One page of a user's claims.
     *
     * @param array{q?:string, status?:string, category?:string, sort?:string} $filters
     * @return array{items: array<int, array>, total: int}
     */
    public function search(int $userId, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($userId, $filters);

        $count = Database::connection()->prepare('SELECT COUNT(*) FROM claims WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $order = self::SORTS[$filters['sort'] ?? 'newest'][1] ?? self::SORTS['newest'][1];

        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM claims
              WHERE ' . $where . '
              ORDER BY ' . $order . '
              LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function countForUser(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM claims WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Numbers for the stat cards: how many claims sit in each stage, and the
     * money still owed to staff (approved but not yet reimbursed).
     *
     * @return array{submitted: int, approved: int, reimbursed: int, owed: string, reimbursed_amount: string}
     */
    public function stats(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FILTER (WHERE status = 'submitted')                     AS submitted,
                    COUNT(*) FILTER (WHERE status = 'approved')                      AS approved,
                    COUNT(*) FILTER (WHERE status = 'reimbursed')                    AS reimbursed,
                    COALESCE(SUM(amount) FILTER (WHERE status = 'approved'), 0)      AS owed,
                    COALESCE(SUM(amount) FILTER (WHERE status = 'reimbursed'), 0)    AS reimbursed_amount
               FROM claims
              WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];

        return [
            'submitted'         => (int) ($row['submitted'] ?? 0),
            'approved'          => (int) ($row['approved'] ?? 0),
            'reimbursed'        => (int) ($row['reimbursed'] ?? 0),
            'owed'              => (string) ($row['owed'] ?? '0'),
            'reimbursed_amount' => (string) ($row['reimbursed_amount'] ?? '0'),
        ];
    }

    public function find(int $userId, int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM claims WHERE user_id = ? AND id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $id]);

        return $stmt->fetch() ?: null;
    }

    /** @param array<string, mixed> $data validated by ClaimService, plus the file columns */
    public function create(int $userId, array $data): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO claims
                (user_id, claimant_name, title, category, expense_date, amount, currency, notes,
                 file_path, file_name, file_mime, file_size)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING id'
        );
        $stmt->execute([
            $userId,
            $data['claimant_name'],
            $data['title'],
            $data['category'],
            $data['expense_date'],
            $data['amount'],
            $data['currency'],
            $data['notes'],
            $data['file_path'] ?? null,
            $data['file_name'] ?? null,
            $data['file_mime'] ?? null,
            $data['file_size'] ?? null,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $data validated by ClaimService, plus the file columns */
    public function update(int $userId, int $id, array $data): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE claims
                SET claimant_name = ?, title = ?, category = ?, expense_date = ?, amount = ?,
                    currency = ?, notes = ?, file_path = ?, file_name = ?, file_mime = ?,
                    file_size = ?, updated_at = CURRENT_TIMESTAMP
              WHERE user_id = ? AND id = ?'
        );
        $stmt->execute([
            $data['claimant_name'],
            $data['title'],
            $data['category'],
            $data['expense_date'],
            $data['amount'],
            $data['currency'],
            $data['notes'],
            $data['file_path'],
            $data['file_name'],
            $data['file_mime'],
            $data['file_size'],
            $userId,
            $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Move a claim to another status. The current status is part of the WHERE,
     * so two people clicking at once cannot skip a step.
     */
    public function setStatus(int $userId, int $id, string $from, string $to): bool
    {
        $stmt = Database::connection()->prepare(
            "UPDATE claims
                SET status        = ?,
                    decided_at    = CASE WHEN ? IN ('approved', 'rejected') THEN CURRENT_TIMESTAMP
                                         WHEN ? = 'submitted' THEN NULL ELSE decided_at END,
                    reimbursed_at = CASE WHEN ? = 'reimbursed' THEN CURRENT_TIMESTAMP ELSE NULL END,
                    updated_at    = CURRENT_TIMESTAMP
              WHERE user_id = ? AND id = ? AND status = ?"
        );
        $stmt->execute([$to, $to, $to, $to, $userId, $id, $from]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $userId, int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM claims WHERE user_id = ? AND id = ?');
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
            $where[]  = '(claimant_name ILIKE ? OR title ILIKE ? OR file_name ILIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $where[]  = 'status = ?';
            $params[] = $status;
        }

        $category = (string) ($filters['category'] ?? '');
        if (isset(self::CATEGORIES[$category])) {
            $where[]  = 'category = ?';
            $params[] = $category;
        }

        return [implode(' AND ', $where), $params];
    }
}
