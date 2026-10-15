<?php
/**
 * User.php - every users-table query lives here.
 */
declare(strict_types=1);

class User
{
    /** Columns safe to hand to a view or the session. */
    private const SAFE = 'id, name, email, role, status, created_at';

    /** Columns for the admin list, which is aliased `u` and joined to counts. */
    private const LIST_COLUMNS =
        'u.id, u.name, u.email, u.role, u.status, u.created_at,
         (SELECT COUNT(*) FROM items i WHERE i.user_id = u.id)    AS items_count,
         (SELECT COUNT(*) FROM ai_logs l WHERE l.user_id = u.id)  AS ai_count';

    public const ROLES    = ['admin', 'user'];
    public const STATUSES = ['active', 'disabled'];

    public function find(int $id): ?array
    {
        $stmt = db()->prepare('SELECT ' . self::SAFE . ' FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = db()->prepare('SELECT ' . self::SAFE . ', password_hash FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT 1 FROM users WHERE email = ?';
        $params = [$email];

        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        $stmt = db()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    /** Current password hash, used to verify before a password change. */
    public function passwordHash(int $id): ?string
    {
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $hash = $stmt->fetchColumn();
        return is_string($hash) ? $hash : null;
    }

    public function count(): int
    {
        return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function countByRole(string $role): int
    {
        $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE role = ?');
        $stmt->execute([$role]);
        return (int) $stmt->fetchColumn();
    }

    public function countByStatus(string $status): int
    {
        $stmt = db()->prepare('SELECT COUNT(*) FROM users WHERE status = ?');
        $stmt->execute([$status]);
        return (int) $stmt->fetchColumn();
    }

    /** Just the display name, for freezing into an audit row. */
    public function nameOf(int $id): ?string
    {
        $stmt = db()->prepare('SELECT name FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string) $name;
    }

    /** @param string $passwordHash already hashed by the caller */
    public function create(string $name, string $email, string $passwordHash, string $role = 'user'): int
    {
        $stmt = db()->prepare(
            'INSERT INTO users (name, email, password_hash, role, status)
             VALUES (?, ?, ?, ?, \'active\')'
        );
        $stmt->execute([$name, $email, $passwordHash, in_array($role, self::ROLES, true) ? $role : 'user']);
        return (int) db()->lastInsertId();
    }

    /* ----------------------------------------------------------- Google ---- */

    public function findByGoogleId(string $googleId): ?array
    {
        $stmt = db()->prepare('SELECT ' . self::SAFE . ', password_hash FROM users WHERE google_id = ? LIMIT 1');
        $stmt->execute([$googleId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * A "registered" account is one that has finished onboarding, i.e. it has a
     * local password. Google-only accounts created by the callback start with
     * password_hash = NULL and must set one before they can be logged in.
     */
    public function hasPassword(int $id): bool
    {
        $hash = $this->passwordHash($id);
        return is_string($hash) && $hash !== '';
    }

    /** First login for a Google-linked account: set its local password. */
    public function setInitialPassword(int $id, string $passwordHash): bool
    {
        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Create an account from a Google profile (no local password), or link the
     * google_id onto an existing account with the same email.
     */
    public function createFromGoogle(string $name, string $email, string $googleId): int
    {
        $existing = $this->findByEmail($email);
        if ($existing !== null) {
            $stmt = db()->prepare('UPDATE users SET google_id = ? WHERE id = ?');
            $stmt->execute([$googleId, (int) $existing['id']]);
            return (int) $existing['id'];
        }

        $stmt = db()->prepare(
            'INSERT INTO users (name, email, password_hash, google_id, role, status)
             VALUES (?, ?, NULL, ?, \'user\', \'active\')'
        );
        $stmt->execute([$name, $email, $googleId]);
        return (int) db()->lastInsertId();
    }

    /**
     * Paginated user list for the admin page.
     *
     * @return array{rows:array<int,array>,total:int,page:int,pages:int}
     */
    public function paginate(string $search = '', int $page = 1, int $perPage = 10): array
    {
        $where  = '';
        $params = [];

        if ($search !== '') {
            $where    = ' AND (u.name LIKE ? OR u.email LIKE ?)';
            $like     = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $count = db()->prepare('SELECT COUNT(*) FROM users u WHERE 1=1' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min(max(1, $page), $pages);

        $stmt = db()->prepare(
            'SELECT ' . self::LIST_COLUMNS . '
               FROM users u
              WHERE 1=1' . $where . '
              ORDER BY u.id
              LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function updateName(int $id, string $name): bool
    {
        $stmt = db()->prepare('UPDATE users SET name = ? WHERE id = ?');
        $stmt->execute([$name, $id]);
        return $stmt->rowCount() > 0;
    }

    public function updatePassword(int $id, string $passwordHash): bool
    {
        $stmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$passwordHash, $id]);
        return $stmt->rowCount() > 0;
    }

    public function setRole(int $id, string $role): bool
    {
        if (!in_array($role, self::ROLES, true)) {
            return false;
        }
        $stmt = db()->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->execute([$role, $id]);
        return $stmt->rowCount() > 0;
    }

    public function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $stmt = db()->prepare('UPDATE users SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        return $stmt->rowCount() > 0;
    }
}
