<?php
/**
 * TelegramIntegration.php - telegram_integrations (one row per user). See UserSettingsRow for the query rules.
 *
 * bot_token_encrypted holds Crypto ciphertext only and is never sent to the browser.
 * webhook_secret is the value Telegram echoes in X-Telegram-Bot-Api-Secret-Token
 * (migration 007); it proves a webhook call came from Telegram for this bot.
 */
declare(strict_types=1);

namespace Src\Models;

use Src\Support\Database;

final class TelegramIntegration extends UserSettingsRow
{
    protected function table(): string
    {
        return 'telegram_integrations';
    }

    protected function writable(): array
    {
        return ['bot_username', 'bot_token_encrypted', 'staff_telegram_chat_id', 'connection_status', 'is_enabled',
                'webhook_secret', 'webhook_url', 'webhook_set_at'];
    }

    /** The integration a webhook call is for (the id is in the webhook URL). */
    public function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM telegram_integrations WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** Every bot that is connected and switched on (used by bin/telegram-webhook.php). */
    public function allConnected(): array
    {
        return Database::connection()->query(
            "SELECT * FROM telegram_integrations
              WHERE connection_status = 'connected' AND is_enabled = TRUE AND bot_token_encrypted IS NOT NULL
              ORDER BY id"
        )->fetchAll();
    }

    /**
     * Remember an update_id. Returns false when it was already handled, so a
     * Telegram retry of the same message is not answered twice.
     */
    public function claimUpdate(int $integrationId, int $updateId): bool
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO telegram_updates (integration_id, update_id) VALUES (?, ?) ON CONFLICT DO NOTHING'
        );
        $stmt->execute([$integrationId, $updateId]);

        return $stmt->rowCount() === 1;
    }
}
