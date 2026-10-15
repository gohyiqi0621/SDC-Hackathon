<?php
/**
 * AiLog.php - every ai_logs-table query lives here.
 * Reads are scoped like items: your own logs, or all of them if you are admin.
 */
declare(strict_types=1);

class AiLog
{
    public function count(array $user): int
    {
        $owner = Item::scopeUserId($user);
        if ($owner === null) {
            return (int) db()->query('SELECT COUNT(*) FROM ai_logs')->fetchColumn();
        }

        $stmt = db()->prepare('SELECT COUNT(*) FROM ai_logs WHERE user_id = ?');
        $stmt->execute([$owner]);
        return (int) $stmt->fetchColumn();
    }

    public function countByStatus(string $status, array $user): int
    {
        $owner  = Item::scopeUserId($user);
        $sql    = 'SELECT COUNT(*) FROM ai_logs WHERE status = ?';
        $params = [$status];

        if ($owner !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $owner;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * AI calls per day for the last $days days, gaps filled with 0.
     *
     * @return array<string,int> date (Y-m-d) => count
     */
    public function countPerDay(int $days, array $user): array
    {
        return Item::fillDays(
            Item::countsByDay('ai_logs', 'created_at', $days, Item::scopeUserId($user)),
            $days
        );
    }

    /** Newest visible calls, for the AI panel history. */
    public function recent(array $user, int $limit = 10): array
    {
        $owner  = Item::scopeUserId($user);
        $sql    = 'SELECT l.id, l.user_id, l.input_text, l.output_text, l.status, l.created_at, u.name AS owner_name
                     FROM ai_logs l
                     LEFT JOIN users u ON u.id = l.user_id
                    WHERE 1=1';
        $params = [];

        if ($owner !== null) {
            $sql .= ' AND l.user_id = ?';
            $params[] = $owner;
        }

        // LIMIT is cast to int rather than bound: the value is never user input.
        $sql .= ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . max(1, $limit);

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Record one call. Both successes and failures are logged, so the panel's
     * history shows when the Python service was down.
     */
    public function create(array $user, string $input, string $output, string $status): int
    {
        $stmt = db()->prepare(
            'INSERT INTO ai_logs (user_id, input_text, output_text, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $user['id'],
            $input,
            $output !== '' ? $output : null,
            in_array($status, ['success', 'error'], true) ? $status : 'error',
        ]);
        return (int) db()->lastInsertId();
    }
}
