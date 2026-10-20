<?php
/**
 * AiAssistantSetting.php - ai_assistant_settings (one row per user). See UserSettingsRow for the query rules.
 * api_key_encrypted holds Crypto ciphertext only and is never sent to the browser.
 */
declare(strict_types=1);

namespace Src\Models;

final class AiAssistantSetting extends UserSettingsRow
{
    protected function table(): string
    {
        return 'ai_assistant_settings';
    }

    protected function writable(): array
    {
        return ['assistant_name', 'personality', 'tone_description', 'system_instructions', 'is_enabled',
                'ai_model', 'api_key_encrypted', 'temperature'];
    }
}
