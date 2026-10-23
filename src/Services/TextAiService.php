<?php
/**
 * TextAiService.php - one place for the app's own AI writing jobs (not the
 * chatbots, which run in Flowise):
 *   - "Generate instruction with AI" (SOPs) on the Setup pages
 *   - email summary and reply draft on the dashboard (first page)
 *
 * Providers are tried in this order; when one is not set up or fails, the
 * next one is used:
 *   1. RunPod      RUNPOD_API_KEY (+ RUNPOD_ENDPOINT_ID, default smpmca8hazvkxi)
 *                  POST https://api.runpod.ai/v2/{endpoint}/runsync
 *   2. Claude      ANTHROPIC_API_KEY (or ANTHROPIC_KEY), else the business's
 *                  saved Claude key from AI Assistant Settings
 *   3. Agnes       AGNES_API_KEY, else the business's saved Agnes key
 *
 * Keys come from Replit Secrets or .env and are never logged or returned.
 */
declare(strict_types=1);

namespace Src\Services;

use RuntimeException;
use Src\Controllers\AiSettingsController;
use Src\Support\Crypto;
use Src\Support\FlowLog;
use Src\Support\Http;
use Throwable;

final class TextAiService
{
    private const RUNPOD_DEFAULT_ENDPOINT = 'smpmca8hazvkxi';
    private const RUNPOD_WAIT_SECONDS     = 90;   // cold starts can take a while
    private const PLACEHOLDERS            = ['your-runpod-api-key', 'your-endpoint-id', 'changeme', 'xxx'];

    /**
     * @param array|null $assistant saved assistant settings row (its own key is a last resort)
     * @return array{text: string, provider: string}
     * @throws RuntimeException with a message for the user when every provider failed
     */
    public function complete(string $system, string $user, int $maxTokens = 1500, float $temperature = 0.4, ?array $assistant = null): array
    {
        $tried = [];
        $last  = null;

        foreach (['RunPod', 'Claude', 'Agnes'] as $provider) {
            $key = $this->keyFor($provider, $assistant);
            if ($key === null) {
                continue;
            }
            $tried[] = $provider;
            $started = microtime(true);
            try {
                $text = match ($provider) {
                    'RunPod' => $this->runpod($key, $system, $user, $maxTokens, $temperature),
                    'Claude' => $this->claude($key, $system, $user, $maxTokens, $temperature),
                    'Agnes'  => $this->agnes($key, $system, $user, $maxTokens, $temperature),
                };
                if (trim($text) === '') {
                    throw new RuntimeException('empty answer');
                }
                FlowLog::write('ai.text_ok', ['provider' => $provider, 'ms' => (int) round((microtime(true) - $started) * 1000)]);
                return ['text' => trim($text), 'provider' => $provider];
            } catch (Throwable $e) {
                $last = $e;
                error_log('[ai] ' . $provider . ' failed: ' . $e->getMessage());
                FlowLog::write('ai.text_failed', ['provider' => $provider, 'error' => $e->getMessage(),
                                                  'ms' => (int) round((microtime(true) - $started) * 1000)]);
            }
        }

        if ($tried === []) {
            throw new RuntimeException('AI writing is not set up yet. Add RUNPOD_API_KEY, ANTHROPIC_API_KEY or AGNES_API_KEY in Replit Secrets (or .env).');
        }
        throw new RuntimeException('The AI service could not answer right now (' . implode(', ', $tried) . ' tried). Please try again in a minute.', 0, $last);
    }

    /* ----------------------------------------------------------- RunPod --- */

    private function runpod(string $key, string $system, string $user, int $maxTokens, float $temperature): string
    {
        $endpoint = $this->env('RUNPOD_ENDPOINT_ID') ?? self::RUNPOD_DEFAULT_ENDPOINT;
        $base     = rtrim($this->env('RUNPOD_API_URL') ?? 'https://api.runpod.ai/v2', '/') . '/' . rawurlencode($endpoint);
        $headers  = ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'];

        // "prompt" for simple workers; "messages" lets a vLLM chat worker apply its chat template.
        $input = [
            'prompt'          => "### Instructions\n" . $system . "\n\n### Task\n" . $user . "\n\n### Answer\n",
            'messages'        => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            'sampling_params' => ['max_tokens' => $maxTokens, 'temperature' => $temperature],
            'max_tokens'      => $maxTokens,
            'temperature'     => $temperature,
        ];

        $deadline = time() + self::RUNPOD_WAIT_SECONDS;
        $response = Http::request('POST', $base . '/runsync', $headers,
            json_encode(['input' => $input], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::RUNPOD_WAIT_SECONDS);
        $this->checkStatus($response, 'RunPod');
        $job = is_array($response['json']) ? $response['json'] : [];

        // Still queued / running (cold start): poll until it finishes or we give up.
        while (in_array($job['status'] ?? '', ['IN_QUEUE', 'IN_PROGRESS'], true) && !empty($job['id']) && time() < $deadline) {
            sleep(2);
            $poll = Http::request('GET', $base . '/status/' . rawurlencode((string) $job['id']), $headers, null, 20);
            $this->checkStatus($poll, 'RunPod');
            $job = is_array($poll['json']) ? $poll['json'] : [];
        }

        $status = (string) ($job['status'] ?? '');
        if ($status !== 'COMPLETED') {
            throw new RuntimeException('RunPod job ' . ($status !== '' ? $status : 'gave no status')
                . (isset($job['error']) ? ': ' . mb_substr(is_string($job['error']) ? $job['error'] : json_encode($job['error']), 0, 200) : ''));
        }

        return $this->textFrom($job['output'] ?? null);
    }

    /** Pull the generated text out of the many output shapes RunPod workers use. */
    private function textFrom(mixed $out, int $depth = 0): string
    {
        if ($depth > 6 || $out === null) {
            return '';
        }
        if (is_string($out)) {
            return $out;
        }
        if (!is_array($out)) {
            return '';
        }
        if (array_is_list($out)) {
            // vLLM: [{"choices":[{"tokens":["..."]}]}] ; or a list of strings
            if ($out !== [] && is_string($out[0])) {
                return implode('', array_filter($out, 'is_string'));
            }
            foreach ($out as $item) {
                $text = $this->textFrom($item, $depth + 1);
                if ($text !== '') {
                    return $text;
                }
            }
            return '';
        }
        foreach (['text', 'generated_text', 'response', 'content', 'result', 'answer'] as $field) {
            if (isset($out[$field]) && (is_string($out[$field]) || is_array($out[$field]))) {
                $text = $this->textFrom($out[$field], $depth + 1);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        if (isset($out['choices'][0])) {
            $choice = $out['choices'][0];
            if (isset($choice['tokens'])) {
                return $this->textFrom($choice['tokens'], $depth + 1);
            }
            return $this->textFrom($choice['message']['content'] ?? ($choice['text'] ?? null), $depth + 1);
        }
        if (isset($out['message'])) {
            return $this->textFrom($out['message'], $depth + 1);
        }
        return $this->textFrom($out['output'] ?? null, $depth + 1);
    }

    /* ----------------------------------------------------------- Claude --- */

    private function claude(string $key, string $system, string $user, int $maxTokens, float $temperature): string
    {
        $response = Http::request('POST', 'https://api.anthropic.com/v1/messages', [
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
            'Content-Type'      => 'application/json',
        ], json_encode([
            'model'       => $this->env('TEXT_AI_CLAUDE_MODEL') ?? 'claude-haiku-4-5',
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
            'system'      => $system,
            'messages'    => [['role' => 'user', 'content' => $user]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 60);
        $this->checkStatus($response, 'Claude');

        $parts = is_array($response['json']) ? (array) ($response['json']['content'] ?? []) : [];
        return implode('', array_map(static fn ($p): string => is_array($p) ? (string) ($p['text'] ?? '') : '', $parts));
    }

    /* ------------------------------------------------------------ Agnes --- */

    private function agnes(string $key, string $system, string $user, int $maxTokens, float $temperature): string
    {
        $base = rtrim($this->env('AGNES_API_URL') ?? 'https://apihub.agnes-ai.com/v1', '/');
        $url  = str_ends_with($base, '/chat/completions') ? $base : $base . '/chat/completions';

        $response = Http::request('POST', $url, [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
        ], json_encode([
            'model'       => $this->env('AGNES_MODEL') ?? $this->env('AGNES_SOP_MODEL') ?? 'agnes-2.5-flash',
            'temperature' => $temperature,
            'max_tokens'  => max($maxTokens, 4000),   // room for models that think first
            'messages'    => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 60);
        $this->checkStatus($response, 'Agnes');

        $choice  = is_array($response['json']) ? ($response['json']['choices'][0] ?? []) : [];
        $content = $choice['message']['content'] ?? ($choice['text'] ?? '');
        $text    = is_array($content)
            ? implode('', array_map(static fn ($p): string => is_array($p) ? (string) ($p['text'] ?? '') : (string) $p, $content))
            : (string) $content;

        return trim((string) preg_replace('/<think>.*?<\/think>/isu', '', $text));
    }

    /* ---------------------------------------------------------- helpers --- */

    private function keyFor(string $provider, ?array $assistant): ?string
    {
        return match ($provider) {
            'RunPod' => $this->env('RUNPOD_API_KEY'),
            'Claude' => $this->env('ANTHROPIC_API_KEY') ?? $this->env('ANTHROPIC_KEY') ?? $this->savedKey($assistant, 'Anthropic'),
            'Agnes'  => $this->env('AGNES_API_KEY') ?? $this->savedKey($assistant, 'Agnes'),
            default  => null,
        };
    }

    /** The business's own key from AI Assistant Settings, when its model is from this provider. */
    private function savedKey(?array $assistant, string $provider): ?string
    {
        if (empty($assistant['api_key_encrypted'])) {
            return null;
        }
        $model = AiSettingsController::resolveModel($assistant['ai_model'] ?? null);
        if ((AiSettingsController::AI_MODELS[$model][1] ?? '') !== $provider) {
            return null;
        }
        try {
            $key = trim(Crypto::decrypt((string) $assistant['api_key_encrypted']));
            return $key !== '' ? $key : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** A setting, or null when it is empty or still the .env.example placeholder. */
    private function env(string $name): ?string
    {
        $value = trim((string) env($name, ''));
        return $value === '' || in_array(strtolower($value), self::PLACEHOLDERS, true) ? null : $value;
    }

    private function checkStatus(array $response, string $provider): void
    {
        $status = (int) ($response['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            return;
        }
        $json   = is_array($response['json'] ?? null) ? $response['json'] : [];
        $detail = (string) ($json['error']['message'] ?? (is_string($json['error'] ?? null) ? $json['error'] : ($json['message'] ?? '')));
        throw new RuntimeException($provider . ' HTTP ' . $status . ($detail !== '' ? ': ' . mb_substr($detail, 0, 200) : ''));
    }
}
