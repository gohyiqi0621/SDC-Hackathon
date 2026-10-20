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
        $company   = (new CompanyProfile())->find($userId) ?? [];
        $assistant = (new AiAssistantSetting())->find($userId) ?? [];
        $problems  = [];

        if ($company === []) {
            $problems[] = 'Company information is not saved yet.';
        }
        if ($assistant === []) {
            $problems[] = 'AI assistant settings are not saved yet.';
        }

        $model    = (string) ($assistant['ai_model'] ?? '');
        $models   = AiSettingsController::AI_MODELS;
        if (!isset($models[$model])) {
            $model = AiSettingsController::DEFAULT_AI_MODEL;
        }

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

        foreach (preg_split('/\R/', str_replace("\r\n", "\n", $instructions)) ?: [] as $line) {
            if (preg_match('/^###\s+(.+?)\s*$/', $line, $m)) {
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
