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
    public function add(int $userId, string $channel, string $chatId, string $role, string $content, string $status = 'ok',
                        ?string $customerName = null, ?string $customerUsername = null): void
    {
        $db = Database::connection();
        if ($customerName !== null || $customerUsername !== null) {
            try {
                $db->prepare(
                    'INSERT INTO chat_messages (user_id, channel, chat_id, role, content, status, customer_name, customer_username)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$userId, $channel, $chatId, $role, $content, $status,
                            $customerName !== '' ? mb_substr((string) $customerName, 0, 150) : null,
                            $customerUsername !== '' ? mb_substr((string) $customerUsername, 0, 64) : null]);
                return;
            } catch (\PDOException $e) {
                // migration 012 not run yet: save without the name
            }
        }
        $db->prepare('INSERT INTO chat_messages (user_id, channel, chat_id, role, content, status) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$userId, $channel, $chatId, $role, $content, $status]);
    }

    /**
     * One row per customer conversation, newest activity first: channel,
     * chat_id, last message, its time, message count, and the customer's
     * name (from chat_messages, else from their latest order).
     */
    public function conversations(int $userId, int $limit = 100): array
    {
        $db = Database::connection();
        $hasNames = (bool) $db->query(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'chat_messages' AND column_name = 'customer_name'"
        )->fetchColumn();

        $nameSql = $hasNames
            ? "(SELECT m.customer_name FROM chat_messages m WHERE m.user_id = c.user_id AND m.channel = c.channel
                   AND m.chat_id = c.chat_id AND m.customer_name IS NOT NULL ORDER BY m.created_at DESC LIMIT 1)"
            : 'NULL';
        $userSql = $hasNames
            ? "(SELECT m.customer_username FROM chat_messages m WHERE m.user_id = c.user_id AND m.channel = c.channel
                   AND m.chat_id = c.chat_id AND m.customer_username IS NOT NULL ORDER BY m.created_at DESC LIMIT 1)"
            : 'NULL';

        $stmt = $db->prepare(
            "SELECT c.channel, c.chat_id, c.messages, c.last_at,
                    (SELECT m.content FROM chat_messages m WHERE m.user_id = c.user_id AND m.channel = c.channel
                        AND m.chat_id = c.chat_id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_message,
                    (SELECT m.role FROM chat_messages m WHERE m.user_id = c.user_id AND m.channel = c.channel
                        AND m.chat_id = c.chat_id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_role,
                    $nameSql AS customer_name,
                    $userSql AS customer_username
               FROM (SELECT user_id, channel, chat_id, COUNT(*) AS messages, MAX(created_at) AS last_at
                       FROM chat_messages WHERE user_id = ? AND channel <> 'staff'
                      GROUP BY user_id, channel, chat_id) c
              ORDER BY c.last_at DESC
              LIMIT " . max(1, min(500, $limit))
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        // Older conversations without a saved name: use the name on their latest order.
        foreach ($rows as $i => $row) {
            if ($row['customer_name'] === null && $row['customer_username'] === null) {
                try {
                    $o = $db->prepare('SELECT customer_name, customer_username FROM orders WHERE user_id = ? AND channel = ? AND customer_chat_id = ?
                                        ORDER BY created_at DESC LIMIT 1');
                    $o->execute([$userId, $row['channel'], $row['chat_id']]);
                    if ($found = $o->fetch()) {
                        $rows[$i]['customer_name']     = $found['customer_name'];
                        $rows[$i]['customer_username'] = $found['customer_username'];
                    }
                } catch (\PDOException $e) {
                    // no orders table yet
                }
            }
        }

        return $rows;
    }

    /** Messages of one conversation newer than $afterId, oldest first (live Chat Interface). */
    public function after(int $userId, string $channel, string $chatId, int $afterId, int $limit = 200): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, role, content, status, created_at FROM chat_messages
              WHERE user_id = ? AND channel = ? AND chat_id = ? AND id > ?
              ORDER BY id LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute([$userId, $channel, $chatId, $afterId]);

        return $stmt->fetchAll();
    }

    /** The latest messages of one conversation, oldest first. */
    public function recent(int $userId, string $channel, string $chatId, int $limit = 30): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, role, content, status, created_at
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
