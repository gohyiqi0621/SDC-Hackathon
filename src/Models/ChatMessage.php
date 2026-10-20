<?php
/**
 * ChatMessage.php - chat_messages: every customer message and assistant reply.
 *
 * channel: 'telegram' (chat_id = Telegram chat id) or 'web' (the website's
 * Chat Interface; chat_id = "web-<user id>"). Always scoped to the business
 * (user_id) that owns the assistant.
 */
declare(strict_types=1);

namespace Src\Models;

use Src\Support\Database;

final class ChatMessage
{
    public function add(int $userId, string $channel, string $chatId, string $role, string $content, string $status = 'ok'): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO chat_messages (user_id, channel, chat_id, role, content, status) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $channel, $chatId, $role, $content, $status]);
    }

    /** The latest messages of one conversation, oldest first. */
    public function recent(int $userId, string $channel, string $chatId, int $limit = 30): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT role, content, status, created_at
               FROM (SELECT id, role, content, status, created_at
                       FROM chat_messages
                      WHERE user_id = ? AND channel = ? AND chat_id = ?
                      ORDER BY created_at DESC, id DESC
                      LIMIT ' . max(1, min(200, $limit)) . ') latest
              ORDER BY created_at, id'
        );
        $stmt->execute([$userId, $channel, $chatId]);

        return $stmt->fetchAll();
    }
}
