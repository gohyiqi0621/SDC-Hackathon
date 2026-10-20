<?php
/**
 * AiAssistantSetting.php - ai_assistant_settings (one row per user). See UserSettingsRow for the query rules.
 *
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
        return ['assistant_name', 'personality', 'tone_description', 'system_instructions', 'is_enabled'];
    }
}
