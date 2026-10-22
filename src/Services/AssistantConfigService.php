<?php
/**
 * AssistantConfigService.php - everything Flowise needs for one business,
 * loaded from PostgreSQL in one place.
 *
 *   company_profiles       -> company name, category, background
 *   ai_assistant_settings  -> assistant name, personality, tone, SOPs,
 *                             AI model, temperature, provider API key, on/off
 *
 * The SOPs are stored together in ai_assistant_settings.system_instructions,
 * each under a "### <Section label>" heading (written by ai-settings.js).
 * They are split back into separate values here.
 */
declare(strict_types=1);

namespace Src\Services;

use RuntimeException;
use Src\Controllers\AiSettingsController;
use Src\Models\AiAssistantSetting;
use Src\Models\CompanyProfile;
use Src\Models\StaffAiAssistantSetting;
use Src\Models\UserSettingsRow;
use Src\Support\Crypto;

final class AssistantConfigService
{
    /** "### <label>" headings used by the settings page -> config keys. */
    private const SOP_HEADINGS = [
        'customer service sop' => 'customer_service',
        'booking sop'          => 'booking_sop',
        'salesman sop'         => 'salesman_sop',
        'general ai behavior'  => 'general',
    ];

    /** Used when tone_description is empty (same wording as the settings page). */
    private const PERSONALITY_TONES = [
        'friendly'     => 'casual, upbeat and easy to talk to',
        'professional' => 'clear, polite and to the point',
        'warm'         => 'caring, patient and reassuring',
        'persuasive'   => 'confident, highlighting benefits and next steps',
        'custom'       => 'helpful and polite',
    ];

    /**
     * @return array{
     *   user_id:int, enabled:bool, problems:string[],
     *   assistant_name:string, personality:string, tone:string,
     *   company_name:string, business_category:string, company_background:string,
     *   customer_service:string, booking_sop:string, salesman_sop:string, general:string,
     *   ai_model:string, provider:string, temperature:float, api_key:?string
     * }
     */
    public function forUser(int $userId): array
    {
        return $this->build($userId, new AiAssistantSetting());
    }

    /**
     * The staff assistant (Staff > Setting & Integration): same shape, read
     * from staff_ai_assistant_settings, plus 'functions' (the ticked checks,
     * e.g. ['check_invoice', 'check_orders']).
     */
    public function forStaff(int $userId): array
    {
        $config = $this->build($userId, new StaffAiAssistantSetting());
        $row    = (new StaffAiAssistantSetting())->find($userId) ?? [];
        $config['functions'] = array_key_exists('functions', $row)
            ? array_values(array_filter(explode(',', (string) $row['functions'])))
            : array_keys(AiSettingsController::STAFF_FUNCTIONS);   // migration 016 not run: all on
        $config['problems'] = str_replace('AI assistant settings are not saved yet.',
            'Staff AI assistant settings are not saved yet (Staff > Setting & Integration).', $config['problems']);
        return $config;
    }

    private function build(int $userId, UserSettingsRow $settings): array
    {
        $company   = (new CompanyProfile())->find($userId) ?? [];
        try {
            $assistant = $settings->find($userId) ?? [];
        } catch (\PDOException $e) {
            $assistant = [];   // table not created yet (run the migrations)
        }
        $problems  = [];

        if ($company === []) {
            $problems[] = 'Company information is not saved yet.';
        }
        if ($assistant === []) {
            $problems[] = 'AI assistant settings are not saved yet.';
        }

        $models   = AiSettingsController::AI_MODELS;
        $model    = AiSettingsController::resolveModel($assistant['ai_model'] ?? null);

        $apiKey = null;
        if (!empty($assistant['api_key_encrypted'])) {
            try {
                $apiKey = Crypto::decrypt((string) $assistant['api_key_encrypted']);
            } catch (RuntimeException $e) {
                $problems[] = 'The saved AI provider key cannot be read (was APP_ENCRYPTION_KEY changed?). Save the key again.';
            }
        } else {
            $problems[] = 'No AI provider secret key is saved.';
        }

        $personality = (string) ($assistant['personality'] ?? 'friendly');
        $tone        = trim((string) ($assistant['tone_description'] ?? ''));
        if ($tone === '') {
            $tone = self::PERSONALITY_TONES[$personality] ?? self::PERSONALITY_TONES['friendly'];
        }

        $background = trim((string) ($company['company_background'] ?? ''));
        $description = trim((string) ($company['company_description'] ?? ''));
        if ($description !== '' && $description !== $background) {
            $background = trim($background . "\n\n" . $description);
        }

        return [
            'user_id'            => $userId,
            'enabled'            => !empty($assistant) && $this->isTrue($assistant['is_enabled'] ?? true),
            'problems'           => $problems,
            'assistant_name'     => trim((string) ($assistant['assistant_name'] ?? '')) ?: 'Assistant',
            'personality'        => $personality,
            'reply_language'     => (string) ($assistant['reply_language'] ?? 'auto'),
            'tone'               => $tone,
            'company_name'       => trim((string) ($company['company_name'] ?? '')) ?: APP_NAME,
            'business_category'  => trim((string) ($company['business_category'] ?? '')) ?: 'general',
            'company_background' => $background,
            'ai_model'           => $model,
            'provider'           => $models[$model][1],
            'temperature'        => isset($assistant['temperature']) ? (float) $assistant['temperature'] : AiSettingsController::DEFAULT_TEMPERATURE,
            'api_key'            => $apiKey,
        ] + $this->splitSops((string) ($assistant['system_instructions'] ?? ''));
    }

    /**
     * "### Customer Service SOP\n...\n\n### Booking SOP\n..." -> separate texts.
     * Text before the first heading, or under an unknown heading, counts as general.
     *
     * @return array{customer_service:string, booking_sop:string, salesman_sop:string, general:string}
     */
    public function splitSops(string $instructions): array
    {
        $out     = ['customer_service' => '', 'booking_sop' => '', 'salesman_sop' => '', 'general' => ''];
        $current = 'general';

        foreach (preg_split('/\R/u', str_replace("\r\n", "\n", $instructions)) ?: [] as $line) {
            if (preg_match('/^###\s+(.+?)\s*$/u', $line, $m)) {
                $current = self::SOP_HEADINGS[mb_strtolower($m[1])] ?? 'general';
                continue;
            }
            $out[$current] .= $line . "\n";
        }

        return array_map('trim', $out);
    }

    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === '1' || $value === 1 || $value === 'true';
    }
}
