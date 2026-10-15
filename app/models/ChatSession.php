<?php
/**
 * ChatSession.php - chat threads. Every read is scoped by owner, so a normal
 * user can only ever touch their own sessions; the admin read-only view uses
 * the explicit allSessions()/allMessages() methods instead.
 */
declare(strict_types=1);

class ChatSession
{
    /** A user's own threads, newest activity first. */
    public function forUser(int $userId): array
    {
        $stmt = db()->prepare(
            'SELECT id, title, created_at, updated_at
               FROM chat_sessions
              WHERE user_id = ?
              ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** Scoped find: null when the id is not yours (used to tell 403 from 404). */
    public function findForUser(int $id, int $userId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM chat_sessions WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $userId]);
        return $stmt->fetch() ?: null;
    }

    public function exists(int $id): bool
    {
        $stmt = db()->prepare('SELECT 1 FROM chat_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * The thread the user is currently working in: the newest one, or a fresh
     * one. Keeps the UI single-threaded while the table stays multi-session.
     */
    public function currentForUser(int $userId): array
    {
        $stmt = db()->prepare(
            'SELECT * FROM chat_sessions WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
        $id = $this->create($userId, 'New chat');
        return $this->findForUser($id, $userId) ?? ['id' => $id, 'user_id' => $userId, 'title' => 'New chat'];
    }

    public function create(int $userId, string $title = 'New chat'): int
    {
        $stmt = db()->prepare('INSERT INTO chat_sessions (user_id, title) VALUES (?, ?)');
        $stmt->execute([$userId, $title]);
        return (int) db()->lastInsertId();
    }

    /** Bump updated_at so the thread floats to the top of the list. */
    public function touch(int $id): void
    {
        $stmt = db()->prepare('UPDATE chat_sessions SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function rename(int $id, string $title): void
    {
        $stmt = db()->prepare('UPDATE chat_sessions SET title = ? WHERE id = ?');
        $stmt->execute([$title, $id]);
    }

    /* ---------------------------------------------------- admin (read-only) -- */

    /** Every thread with its owner and message count. Admin view only. */
    public function allSessions(int $limit = 50): array
    {
        $stmt = db()->prepare(
            'SELECT s.id, s.title, s.created_at, s.updated_at,
                    u.name AS owner_name, u.email AS owner_email,
                    (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id = s.id) AS message_count
               FROM chat_sessions s
               LEFT JOIN users u ON u.id = s.user_id
              ORDER BY s.updated_at DESC, s.id DESC
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findAny(int $id): ?array
    {
        $stmt = db()->prepare(
            'SELECT s.*, u.name AS owner_name, u.email AS owner_email
               FROM chat_sessions s
               LEFT JOIN users u ON u.id = s.user_id
              WHERE s.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
