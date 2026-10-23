<?php
/**
 * SopGeneratorService.php - Agnes AI writes three SOPs for one business.
 *
 * Input: the saved company profile (name, category, background, description)
 * and up to 20 active products. Output: three SOPs, five short steps each,
 * one for each SOP box on the AI Settings page:
 *
 *   service  customer service / after-sales (complaints, refunds, questions)
 *   booking  the business's main booking or order-taking process (a shop with
 *            no appointments gets e.g. "Order & Delivery SOP" instead)
 *   sales    how to recommend, upsell and close
 *
 * Agnes AI uses the OpenAI-style API:
 *   POST {AGNES_API_URL}/chat/completions   Authorization: Bearer <key>
 *
 * Key: AGNES_API_KEY in .env, or the business's own saved key when its
 * assistant runs on an Agnes model. The key is never logged or returned.
 */
declare(strict_types=1);

namespace Src\Services;

use RuntimeException;
use Src\Controllers\AiSettingsController;
use Src\Support\Crypto;
use Src\Support\Database;
use Src\Support\Http;
use Throwable;

final class SopGeneratorService
{
    public const SLOTS = [
        'service' => 'Customer Service SOP',
        'booking' => 'Booking SOP',
        'sales'   => 'Salesman SOP',
    ];
    private const STEPS        = 5;
    private const MAX_STEP_LEN = 180;
    private const TIMEOUT      = 60;

    /**
     * @param array      $company   company_profiles row
     * @param array      $products  from productsFor()
     * @param array|null $assistant ai_assistant_settings row (for a saved Agnes key)
     * @return array{model: string, sops: list<array{slot: string, heading: string, title: string, why: string, steps: list<string>}>}
     */
    public function generate(array $company, array $products, ?array $assistant): array
    {
        $apiKey = $this->apiKey($assistant);
        $model  = (string) (env('AGNES_SOP_MODEL', '') ?: 'agnes-2.5-flash');
        $url    = rtrim((string) (env('AGNES_API_URL', '') ?: 'https://apihub.agnes-ai.com/v1'), '/') . '/chat/completions';

        $payload = [
            'model'       => $model,
            'temperature' => 0.4,
            'max_tokens'  => 4000,   // room for models that think before answering
            'messages'    => [
                ['role' => 'system', 'content' => $this->instructions()],
                ['role' => 'user',   'content' => $this->companyBrief($company, $products)],
            ],
        ];

        try {
            $response = Http::request('POST', $url, [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::TIMEOUT);
        } catch (RuntimeException $e) {
            error_log('[sop] ' . $e->getMessage());
            throw new RuntimeException('Could not reach the AI service. Check the internet connection and try again.');
        }

        if ($response['status'] === 401 || $response['status'] === 403) {
            throw new RuntimeException('The AI service rejected the API key. Check the key in the server settings.');
        }
        if ($response['status'] === 429) {
            throw new RuntimeException('The AI service is busy or the usage limit was reached. Try again in a minute.');
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $detail = is_array($response['json']) ? (string) ($response['json']['error']['message'] ?? '') : '';
            error_log('[sop] Agnes HTTP ' . $response['status'] . ($detail !== '' ? ': ' . $detail : ''));
            throw new RuntimeException('The instructions could not be generated (HTTP ' . $response['status'] . ').' . (APP_DEBUG && $detail !== '' ? ' ' . $detail : ''));
        }

        $choice = is_array($response['json']) ? ($response['json']['choices'][0] ?? []) : [];
        $text   = $this->contentText($choice['message']['content'] ?? ($choice['text'] ?? ''));

        try {
            $sops = $this->parse($text);
        } catch (RuntimeException $e) {
            // The AI's own answer (no secrets in it), so the format can be checked in Apache's error.log.
            error_log('[sop] could not read Agnes answer (finish_reason=' . (string) ($choice['finish_reason'] ?? '?') . '): '
                . ($text !== '' ? mb_substr($text, 0, 3000) : '(empty) raw=' . mb_substr($response['raw'], 0, 1500)));
            if (($choice['finish_reason'] ?? '') === 'length') {
                throw new RuntimeException('The AI stopped before finishing the instructions. Please try again.');
            }
            throw $e;
        }

        return ['model' => $model, 'sops' => $sops];
    }

    /** Up to 20 active products: name, category, price, so the steps can mention real items. */
    public static function productsFor(int $userId): array
    {
        try {
            $stmt = Database::connection()->prepare(
                "SELECT p.name, c.name AS category, p.price, p.promotion_price, p.currency
                   FROM products p
                   JOIN product_categories c ON c.id = p.category_id AND c.user_id = p.user_id
                  WHERE p.user_id = ? AND p.status = 'active'
                  ORDER BY p.updated_at DESC NULLS LAST, p.id DESC
                  LIMIT 20"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return []; // products are optional context
        }
    }

    /* ---------------------------------------------------------- private ---- */

    private function apiKey(?array $assistant): string
    {
        $key = trim((string) env('AGNES_API_KEY', ''));
        if ($key !== '') {
            return $key;
        }

        $model = AiSettingsController::resolveModel($assistant['ai_model'] ?? null);
        if (!empty($assistant['api_key_encrypted']) && (AiSettingsController::AI_MODELS[$model][1] ?? '') === 'Agnes') {
            try {
                return Crypto::decrypt((string) $assistant['api_key_encrypted']);
            } catch (RuntimeException $e) {
                // fall through to the message below
            }
        }

        throw new RuntimeException('Generate with AI is not set up on this server yet (no AI key). Write the instructions yourself, or ask the administrator to add the key.');
    }

    private function instructions(): string
    {
        return <<<TXT
You are an operations consultant for small and medium businesses in Malaysia.
Read the company information and write the 3 Standard Operating Procedures (SOPs)
that matter most for THIS business. They are used by staff and by the company's
AI chat assistant when talking to customers.

Write exactly 3 SOPs, one for each slot:
- "service": customer service / after-sales for this business (complaints, refunds, returns, questions).
- "booking": this business's main booking or order-taking process. If it takes appointments or
  reservations, describe that. If it does not (e.g. a shop or online seller), choose its most important
  order process instead, such as order & delivery, custom orders, pre-orders or pickup.
- "sales": how to recommend, upsell and close a sale for its actual products or services.

Rules:
- Give each SOP a short title that fits the business (2-6 words, ending with "SOP"),
  e.g. "Custom Cake Order SOP", "Car Service Booking SOP".
- Exactly 5 steps per SOP. Each step is one short, simple, practical sentence (max 20 words),
  starting with a verb, specific to this business. Mention real products, services or details
  from the information when useful. No generic filler, no numbering inside the text.
- Do not invent prices, discounts, phone numbers, addresses or policies that are not given.
  If a detail is unknown, say what to check or ask instead.
- "why": one short sentence on why this SOP matters for this business.
- Write in the same language as the company information (English if unsure).

Reply with JSON only, no other text, in this exact shape:
{"sops":[
 {"slot":"service","title":"...","why":"...","steps":["...","...","...","...","..."]},
 {"slot":"booking","title":"...","why":"...","steps":["...","...","...","...","..."]},
 {"slot":"sales","title":"...","why":"...","steps":["...","...","...","...","..."]}
]}
TXT;
    }

    private function companyBrief(array $company, array $products): string
    {
        $lines = [
            'Company name: ' . trim((string) ($company['company_name'] ?? '')),
            'Business category: ' . (trim((string) ($company['business_category'] ?? '')) ?: 'not given'),
            'Company background: ' . (trim((string) ($company['company_background'] ?? '')) ?: 'not given'),
            'Company description: ' . (trim((string) ($company['company_description'] ?? '')) ?: 'not given'),
        ];

        if ($products !== []) {
            $lines[] = 'Products and services (active):';
            foreach ($products as $p) {
                $price = $p['currency'] . ' ' . $p['price'];
                $lines[] = '- ' . $p['name'] . ' [' . $p['category'] . '] ' . $price;
            }
        } else {
            $lines[] = 'Products and services: none listed yet.';
        }

        // Keep the request small even with a very long background.
        return mb_substr(implode("\n", $lines), 0, 6000);
    }

    /** message.content can be a string or a list of {type, text} parts. */
    private function contentText(mixed $content): string
    {
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                $parts[] = is_array($part) ? (string) ($part['text'] ?? '') : (string) $part;
            }
            $content = implode('', $parts);
        }
        return trim((string) $content);
    }

    /** @return list<array{slot: string, heading: string, title: string, why: string, steps: list<string>}> */
    private function parse(string $text): array
    {
        // Drop any "thinking" block and code fences around the JSON.
        $text  = (string) preg_replace('/<think>.*?<\/think>/isu', '', $text);
        $text  = trim((string) preg_replace('/```(?:json|JSON)?/u', '', $text));
        $items = $this->jsonItems($text) ?? $this->textItems($text);

        if (!is_array($items) || $items === []) {
            throw new RuntimeException('The AI sent an answer in an unexpected format. Please try again.');
        }

        $bySlot = [];
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $slot = strtolower(trim((string) ($item['slot'] ?? '')));
            if (!isset(self::SLOTS[$slot]) || isset($bySlot[$slot])) {
                // Missing or repeated slot: use the next free one in order.
                $slot = array_values(array_diff(array_keys(self::SLOTS), array_keys($bySlot)))[0] ?? null;
                if ($slot === null) {
                    break;
                }
            }

            $rawSteps = $item['steps'] ?? $item['Steps'] ?? $item['procedure'] ?? [];
            if (is_string($rawSteps)) {
                $rawSteps = preg_split('/\R+/u', $rawSteps) ?: [];
            }
            $steps = [];
            foreach ((array) $rawSteps as $step) {
                if (is_array($step)) {
                    $step = $step['step'] ?? $step['text'] ?? $step['description'] ?? implode(' ', array_filter($step, 'is_string'));
                }
                $step = trim((string) preg_replace('/^\s*(?:\d+[.)]|[-*•])\s*/u', '', (string) $step));
                $step = trim((string) preg_replace('/\s+/u', ' ', $step));
                if ($step !== '') {
                    $steps[] = mb_strimwidth($step, 0, self::MAX_STEP_LEN, '…');
                }
            }
            if (count($steps) < self::STEPS) {
                throw new RuntimeException('The AI returned an incomplete instruction. Please try again.');
            }

            $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($item['title'] ?? $item['name'] ?? '')));
            $bySlot[$slot] = [
                'slot'    => $slot,
                'heading' => self::SLOTS[$slot],
                'title'   => mb_substr($title !== '' ? $title : self::SLOTS[$slot], 0, 80),
                'why'     => mb_substr(trim((string) ($item['why'] ?? '')), 0, 240),
                'steps'   => array_slice($steps, 0, self::STEPS),
            ];
        }

        if (count($bySlot) !== count(self::SLOTS)) {
            throw new RuntimeException('The AI did not return all three instructions. Please try again.');
        }

        return array_values(array_map(static fn (string $slot): array => $bySlot[$slot], array_keys(self::SLOTS)));
    }

    /** The SOP list from a JSON answer, or null when there is no usable JSON. */
    private function jsonItems(string $text): ?array
    {
        $start = strcspn($text, '{[');
        $end   = max((int) strrpos($text, '}'), (int) strrpos($text, ']'));
        if ($start >= strlen($text) || $end <= $start) {
            return null;
        }

        $json = substr($text, $start, $end - $start + 1);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $data = json_decode((string) preg_replace('/,\s*([}\]])/u', '$1', $json), true); // trailing commas
        }
        if (!is_array($data)) {
            return null;
        }

        foreach (['sops', 'SOPs', 'SOPS', 'sop', 'data', 'result'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data = $data[$key];
                break;
            }
        }
        if (isset($data['steps'])) {
            return null; // a single SOP, not three
        }
        // {"service": {...}, "booking": {...}, "sales": {...}}
        if (!array_is_list($data)) {
            $list = [];
            foreach ($data as $slot => $item) {
                if (is_array($item)) {
                    $list[] = $item + ['slot' => (string) $slot];
                }
            }
            $data = $list;
        }
        return $data;
    }

    /**
     * Fallback for a plain-text answer: a title line, then numbered or
     * bulleted steps, repeated three times.
     */
    private function textItems(string $text): array
    {
        $items   = [];
        $current = null;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/[*_#]+/u', '', $line));
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(?:\d+[.)]|[-•])\s*(.+)$/u', $line, $m)) {
                if ($current !== null) {
                    $items[$current]['steps'][] = $m[1];
                }
                continue;
            }
            if (preg_match('/^why\s*:\s*(.+)$/iu', $line, $m) && $current !== null) {
                $items[$current]['why'] = $m[1];
                continue;
            }
            $items[] = ['title' => (string) preg_replace('/^(?:title\s*:\s*|SOP\s*\d+\s*[:.-]\s*)/iu', '', $line), 'steps' => []];
            $current = count($items) - 1;
        }
        return array_values(array_filter($items, static fn (array $i): bool => count($i['steps']) >= self::STEPS));
    }
}
