<?php
/**
 * Item.php - every items-table query lives here.
 *
 * Every read and write takes the acting user and is scoped in SQL:
 * a normal user only ever touches their own rows, an admin touches all of them.
 * Views hide buttons; this file is what actually enforces it.
 */
declare(strict_types=1);

class Item
{
    public const STATUSES = ['draft', 'active', 'archived'];

    /** Columns safe to hand to a view. */
    private const SAFE = 'i.id, i.user_id, i.title, i.description, i.status, i.created_at, i.updated_at';

    /* ------------------------------------------------------------ scope ---- */

    /**
     * The user id to filter on, or null for "no restriction".
     * This is the single place the role -> scope decision is made.
     */
    public static function scopeUserId(array $user): ?int
    {
        return ($user['role'] ?? 'user') === 'admin' ? null : (int) $user['id'];
    }

    /**
     * SQL fragment starting with AND, plus its bind values.
     *
     * @return array{0:string,1:array<int,int>}
     */
    private static function scope(string $alias, array $user): array
    {
        $id = self::scopeUserId($user);
        return $id === null ? ['', []] : [" AND {$alias}.user_id = ?", [$id]];
    }

    /* ------------------------------------------------------------- read ---- */

    public function count(array $user): int
    {
        [$where, $params] = self::scope('i', $user);
        $stmt = db()->prepare('SELECT COUNT(*) FROM items i WHERE 1=1' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function countByStatus(string $status, array $user): int
    {
        [$where, $params] = self::scope('i', $user);
        $stmt = db()->prepare('SELECT COUNT(*) FROM items i WHERE i.status = ?' . $where);
        $stmt->execute(array_merge([$status], $params));
        return (int) $stmt->fetchColumn();
    }

    /**
     * Items created per day for the last $days days, gaps filled with 0.
     *
     * @return array<string,int> date (Y-m-d) => count
     */
    public function countCreatedPerDay(int $days, array $user): array
    {
        return self::fillDays(
            self::countsByDay('items', 'created_at', $days, self::scopeUserId($user)),
            $days
        );
    }

    /** Newest visible items with their owner's name, for the dashboard table. */
    public function recent(array $user, int $limit = 5): array
    {
        [$where, $params] = self::scope('i', $user);
        // LIMIT is cast to int rather than bound: the value is never user input.
        $stmt = db()->prepare(
            'SELECT ' . self::SAFE . ', u.name AS owner_name
               FROM items i
               JOIN users u ON u.id = i.user_id
              WHERE 1=1' . $where . '
              ORDER BY i.created_at DESC, i.id DESC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Filtered, paginated list for the items page.
     *
     * @return array{rows:array<int,array>,total:int,page:int,pages:int,per_page:int}
     */
    public function paginate(array $user, string $search = '', string $status = '', int $page = 1, int $perPage = 10): array
    {
        [$where, $params] = self::scope('i', $user);

        if ($search !== '') {
            $where .= ' AND (i.title LIKE ? OR i.description LIKE ?)';
            $like = '%' . self::escapeLike($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if (in_array($status, self::STATUSES, true)) {
            $where .= ' AND i.status = ?';
            $params[] = $status;
        }

        $count = db()->prepare('SELECT COUNT(*) FROM items i WHERE 1=1' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min(max(1, $page), $pages);

        $stmt = db()->prepare(
            'SELECT ' . self::SAFE . ', u.name AS owner_name
               FROM items i
               JOIN users u ON u.id = i.user_id
              WHERE 1=1' . $where . '
              ORDER BY i.created_at DESC, i.id DESC
              LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $stmt->execute($params);

        return [
            'rows'     => $stmt->fetchAll(),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /** Scoped lookup: null when the row is missing OR not visible to $user. */
    public function find(int $id, array $user): ?array
    {
        [$where, $params] = self::scope('i', $user);
        $stmt = db()->prepare(
            'SELECT ' . self::SAFE . ', u.name AS owner_name
               FROM items i
               JOIN users u ON u.id = i.user_id
              WHERE i.id = ?' . $where . '
              LIMIT 1'
        );
        $stmt->execute(array_merge([$id], $params));
        return $stmt->fetch() ?: null;
    }

    /** Unscoped existence check. Only used to tell 403 (not yours) from 404 (gone). */
    public function exists(int $id): bool
    {
        $stmt = db()->prepare('SELECT 1 FROM items WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    /* ------------------------------------------------------------ write ---- */

    public function create(array $user, string $title, string $description, string $status): int
    {
        $stmt = db()->prepare(
            'INSERT INTO items (user_id, title, description, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([(int) $user['id'], $title, $description !== '' ? $description : null, $status]);
        return (int) db()->lastInsertId();
    }

    /** Scoped update. False means "not yours" or "gone", never "bad input". */
    public function update(int $id, array $user, string $title, string $description, string $status): bool
    {
        $sql    = 'UPDATE items SET title = ?, description = ?, status = ? WHERE id = ?';
        $params = [$title, $description !== '' ? $description : null, $status, $id];

        $owner = self::scopeUserId($user);
        if ($owner !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $owner;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /** Scoped delete. False means "not yours" or "gone". */
    public function delete(int $id, array $user): bool
    {
        $sql    = 'DELETE FROM items WHERE id = ?';
        $params = [$id];

        $owner = self::scopeUserId($user);
        if ($owner !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $owner;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /* ---------------------------------------------------------- helpers ---- */

    /** Shared day-bucketing query so Item and AiLog do not duplicate it. */
    public static function countsByDay(string $table, string $column, int $days, ?int $userId = null): array
    {
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        // Table/column names come from our own code, never from a request.
        $sql    = "SELECT DATE({$column}) AS d, COUNT(*) AS c FROM {$table} WHERE {$column} >= ?";
        $params = [$from];

        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }

        $stmt = db()->prepare($sql . " GROUP BY DATE({$column})");
        $stmt->execute($params);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['d']] = (int) $row['c'];
        }
        return $counts;
    }

    public static function fillDays(array $counts, int $days): array
    {
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $series[$day] = $counts[$day] ?? 0;
        }
        return $series;
    }

    /** Stop a user typing % or _ from turning a search into a wildcard scan. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
