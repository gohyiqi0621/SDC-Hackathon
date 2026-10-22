<?php
/**
 * StaffAiAssistantSetting.php - staff_ai_assistant_settings (one row per user).
 *
 * The staff assistant's settings: same columns and rules as AiAssistantSetting
 * (the customer assistant), stored separately. See UserSettingsRow for the
 * query rules. api_key_encrypted holds Crypto ciphertext only.
 */
declare(strict_types=1);

namespace Src\Models;

final class StaffAiAssistantSetting extends UserSettingsRow
{
    protected function table(): string
    {
        return 'staff_ai_assistant_settings';
    }

    protected function writable(): array
    {
        return ['assistant_name', 'personality', 'tone_description', 'system_instructions', 'is_enabled',
                'ai_model', 'api_key_encrypted', 'temperature', 'reply_language'];
    }
}
