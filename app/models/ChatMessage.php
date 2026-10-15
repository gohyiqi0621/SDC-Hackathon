<?php
/**
 * ChatMessage.php - messages inside a chat thread.
 */
declare(strict_types=1);

class ChatMessage
{
    /** Oldest first, so the UI can append downward. */
    public function forSession(int $sessionId): array
    {
        $stmt = db()->prepare(
            'SELECT id, session_id, role, body, image_name, created_at
               FROM chat_messages
              WHERE session_id = ?
              ORDER BY id ASC'
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll();
    }

    public function create(int $sessionId, string $role, string $body, ?string $imageName = null): int
    {
        $stmt = db()->prepare(
            'INSERT INTO chat_messages (session_id, role, body, image_name) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $sessionId,
            $role === 'assistant' ? 'assistant' : 'user',
            $body,
            $imageName,
        ]);
        return (int) db()->lastInsertId();
    }

    public function countForSession(int $sessionId): int
    {
        $stmt = db()->prepare('SELECT COUNT(*) FROM chat_messages WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        return (int) $stmt->fetchColumn();
    }

    public function countAll(): int
    {
        return (int) db()->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();
    }
}
