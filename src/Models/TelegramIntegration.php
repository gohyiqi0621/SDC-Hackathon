<?php
/**
 * TelegramIntegration.php - telegram_integrations (one row per user). See UserSettingsRow for the query rules.
 *
 * bot_token_encrypted holds Crypto ciphertext only and is never sent to the browser.
 */
declare(strict_types=1);

namespace Src\Models;

final class TelegramIntegration extends UserSettingsRow
{
    protected function table(): string
    {
        return 'telegram_integrations';
    }

    protected function writable(): array
    {
        return ['bot_username', 'bot_token_encrypted', 'staff_telegram_chat_id', 'connection_status', 'is_enabled'];
    }
}
