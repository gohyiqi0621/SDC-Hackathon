<?php
/**
 * DocumentExtractService.php - AI reads an invoice or receipt and returns its fields.
 *
 * The browser turns the uploaded file into 1-4 page images (PDF pages are
 * rendered with pdf.js; photos are shrunk) and sends them here. One AI call
 * returns the fields as JSON, which are cleaned before going back to the
 * form. Nothing is saved: the user checks the filled form and submits it.
 *
 * Which AI: the model + key saved in AI Settings (Staff first, then the
 * Customer assistant), else ANTHROPIC_API_KEY (Claude Haiku 5.5) or
 * AGNES_API_KEY from .env for testing.
 *
 * Cost: one call per file, page images capped at 1600px and 4 pages, Claude
 * at low effort with a JSON-only answer - roughly USD 0.001 per invoice.
 *   Anthropic (Claude Haiku 5.5)        POST https://api.anthropic.com/v1/messages
 *                                       (structured output: the reply always matches the schema)
 *   Agnes / OpenAI (OpenAI-style API)   POST {base}/chat/completions
 * Raw HTTPS through Src\Support\Http, like the rest of the app (no Composer).
 * Images and keys are never logged.
 */
declare(strict_types=1);

namespace Src\Services;

use RuntimeException;
use Src\Controllers\AiSettingsController;
use Src\Models\AiAssistantSetting;
use Src\Models\CompanyProfile;
use Src\Models\Invoice;
use Src\Models\StaffAiAssistantSetting;
use Src\Support\Crypto;
use Src\Support\Http;

final class DocumentExtractService
{
    public const MAX_IMAGES      = 4;
    private const MAX_IMAGE_BYTES = 4 * 1024 * 1024;
    private const IMAGE_TYPES     = ['image/jpeg', 'image/png', 'image/webp'];
    private const TIMEOUT         = 90;

    private const PROVIDER_URLS = [
        'OpenAI' => 'https://api.openai.com/v1',
    ];

    /**
     * @param 'invoice'|'claim'   $kind
     * @param list<string>        $dataUrls  "data:image/jpeg;base64,..." page images, in page order
     * @return array{fields: array<string, mixed>, model: string}
     * @throws RuntimeException with a message safe to show the user
     */
    public function extract(int $userId, string $kind, array $dataUrls): array
    {
        $images = $this->images($dataUrls);
        $ai     = $this->provider($userId);
        $prompt = $kind === 'claim' ? $this->claimPrompt() : $this->invoicePrompt($userId);
        $schema = $kind === 'claim' ? $this->claimSchema() : $this->invoiceSchema();

        $raw = $ai['provider'] === 'Anthropic'
            ? $this->askAnthropic($ai, $prompt, $schema, $images)
            : $this->askOpenAiStyle($ai, $prompt, $images);

        $json = $this->decode($raw);

        return [
            'fields' => $kind === 'claim' ? $this->cleanClaim($json) : $this->cleanInvoice($json),
            'model'  => $ai['label'],
        ];
    }

    /* ------------------------------------------------------------ input --- */

    /** @return list<array{mime: string, data: string}> base64 without the data: prefix */
    private function images(array $dataUrls): array
    {
        $images = [];

        foreach (array_slice(array_values($dataUrls), 0, self::MAX_IMAGES) as $url) {
            if (!is_string($url) || !preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$#', $url, $m)) {
                throw new RuntimeException('The file could not be prepared for reading. Try a JPG, PNG or PDF.');
            }
            if (!in_array($m[1], self::IMAGE_TYPES, true) || strlen($m[2]) * 3 / 4 > self::MAX_IMAGE_BYTES) {
                throw new RuntimeException('The page image is too large to read.');
            }
            $images[] = ['mime' => $m[1], 'data' => $m[2]];
        }

        if ($images === []) {
            throw new RuntimeException('Choose a file first.');
        }

        return $images;
    }

    /**
     * The AI to use: saved Staff settings, then Customer settings, then AGNES_API_KEY.
     *
     * @return array{provider: string, model: string, label: string, key: string, url: string}
     */
    private function provider(int $userId): array
    {
        foreach ([new StaffAiAssistantSetting(), new AiAssistantSetting()] as $settings) {
            $row = $settings->find($userId);
            if ($row === null || empty($row['api_key_encrypted']) || empty($row['ai_model'])) {
                continue;
            }

            $model = AiSettingsController::resolveModel((string) $row['ai_model']);
            [$label, $provider] = AiSettingsController::AI_MODELS[$model];

            try {
                $key = Crypto::decrypt((string) $row['api_key_encrypted']);
            } catch (RuntimeException $e) {
                continue;
            }

            if ($provider === 'DeepSeek') {
                throw new RuntimeException('DeepSeek cannot read images. Choose Claude Haiku 5.5 or Agnes 2.5 Flash in AI Settings.');
            }

            return ['provider' => $provider, 'model' => $model, 'label' => $label, 'key' => $key, 'url' => $this->baseUrl($provider)];
        }

        // .env fallbacks for testing: Claude first, then Agnes.
        $claudeKey = trim((string) env('ANTHROPIC_API_KEY', ''));
        if ($claudeKey !== '') {
            return ['provider' => 'Anthropic', 'model' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5', 'key' => $claudeKey, 'url' => $this->baseUrl('Anthropic')];
        }

        $agnesKey = trim((string) env('AGNES_API_KEY', ''));
        if ($agnesKey !== '') {
            return ['provider' => 'Agnes', 'model' => 'agnes-2.5-flash', 'label' => 'Agnes 2.5 Flash', 'key' => $agnesKey, 'url' => $this->baseUrl('Agnes')];
        }

        throw new RuntimeException('AI auto-fill is not set up yet: save an AI model and key in AI Settings & Integration.');
    }

    private function baseUrl(string $provider): string
    {
        if ($provider === 'Agnes') {
            return rtrim((string) (env('AGNES_API_URL', '') ?: 'https://apihub.agnes-ai.com/v1'), '/');
        }

        return self::PROVIDER_URLS[$provider] ?? 'https://api.anthropic.com/v1';
    }

    /* -------------------------------------------------------- providers --- */

    /** Claude: images first, then the instructions; the reply must match $schema. */
    private function askAnthropic(array $ai, string $prompt, array $schema, array $images): string
    {
        $content = [];
        foreach ($images as $img) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img['mime'], 'data' => $img['data']]];
        }
        $content[] = ['type' => 'text', 'text' => $prompt];

        $outputConfig = ['format' => ['type' => 'json_schema', 'schema' => $schema]];
        // Reading a document is routine work: low effort keeps it fast and cheap.
        // Only newer models accept "effort"; Claude Haiku 4.5 (and other 4.x
        // models) reject the whole request with HTTP 400 when it is sent.
        if (!preg_match('/^claude-(haiku|sonnet|opus)-4/', (string) $ai['model'])) {
            $outputConfig['effort'] = 'low';
        }

        $payload = [
            'model'         => $ai['model'],
            'max_tokens'    => 4000, // a hard cap on the reply; a normal invoice needs well under 1,000
            'output_config' => $outputConfig,
            'messages'      => [['role' => 'user', 'content' => $content]],
        ];
        $headers = [
            'x-api-key'         => $ai['key'],
            'anthropic-version' => '2023-06-01',
            'Content-Type'      => 'application/json',
        ];

        try {
            $response = $this->post('https://api.anthropic.com/v1/messages', $headers, $payload, $ai['label']);
        } catch (RuntimeException $e) {
            // HTTP 400 = this model refused one of the options. Ask once more in
            // the plainest form (no effort, no schema; JSON asked for in the text).
            if (!str_contains($e->getMessage(), '(HTTP 400)')) {
                throw $e;
            }
            unset($payload['output_config']);
            $last = count($content) - 1;
            $content[$last]['text'] .= "\n\nReply with the JSON object only, no other text.";
            $payload['messages'] = [['role' => 'user', 'content' => $content]];
            $response = $this->post('https://api.anthropic.com/v1/messages', $headers, $payload, $ai['label']);
        }

        $json = $response['json'];
        if (($json['stop_reason'] ?? '') === 'refusal') {
            throw new RuntimeException('The AI declined to read this file. Please type the details instead.');
        }
        if (($json['stop_reason'] ?? '') === 'max_tokens') {
            throw new RuntimeException('The document is too long for one read. Please check and type the remaining details.');
        }

        foreach ($json['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                return (string) $block['text'];
            }
        }

        throw new RuntimeException('The AI gave an empty answer. Try "Read again".');
    }

    /** Agnes / OpenAI: the OpenAI chat format with image_url parts; JSON asked for in the prompt. */
    private function askOpenAiStyle(array $ai, string $prompt, array $images): string
    {
        $content = [['type' => 'text', 'text' => $prompt . "\n\nReply with the JSON object only, no other text."]];
        foreach ($images as $img) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $img['mime'] . ';base64,' . $img['data']]];
        }

        $response = $this->post($ai['url'] . '/chat/completions', [
            'Authorization' => 'Bearer ' . $ai['key'],
            'Content-Type'  => 'application/json',
        ], [
            'model'       => $ai['model'],
            'temperature' => 0,
            'max_tokens'  => 4000,
            'messages'    => [['role' => 'user', 'content' => $content]],
        ], $ai['label']);

        $message = $response['json']['choices'][0]['message']['content'] ?? '';
        if (is_array($message)) { // some gateways return content parts
            $message = implode('', array_map(static fn ($p) => is_array($p) ? (string) ($p['text'] ?? '') : (string) $p, $message));
        }

        if (trim((string) $message) === '') {
            throw new RuntimeException('The AI gave an empty answer. Try "Read again".');
        }

        return (string) $message;
    }

    /** @return array{status: int, json: mixed, raw: string} */
    private function post(string $url, array $headers, array $payload, string $label): array
    {
        try {
            $response = Http::request('POST', $url, $headers, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), self::TIMEOUT);
        } catch (RuntimeException $e) {
            error_log('[extract] ' . $label . ': ' . $e->getMessage());
            throw new RuntimeException('Could not reach ' . $label . '. Check the internet connection and try again.');
        }

        $status = $response['status'];
        if ($status === 401 || $status === 403) {
            throw new RuntimeException($label . ' rejected the API key. Check the key in AI Settings.');
        }
        if ($status === 429) {
            throw new RuntimeException($label . ' is busy or the usage limit was reached. Try again in a minute.');
        }
        if ($status < 200 || $status >= 300 || !is_array($response['json'])) {
            $detail = is_array($response['json']) ? (string) ($response['json']['error']['message'] ?? '') : '';
            error_log('[extract] ' . $label . ' HTTP ' . $status . ($detail !== '' ? ': ' . $detail : ''));
            \Src\Support\FlowLog::write('extract.error', ['model' => $label, 'status' => $status, 'detail' => mb_substr($detail, 0, 300)]);
            throw new RuntimeException($label . ' could not read the file (HTTP ' . $status . ').' . (APP_DEBUG && $detail !== '' ? ' ' . $detail : ''));
        }

        return $response;
    }

    /** The JSON object in the AI's answer (tolerates ```json fences and text around it). */
    private function decode(string $text): array
    {
        $text  = trim($text);
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        $json  = ($start !== false && $end !== false) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;

        if (!is_array($json)) {
            error_log('[extract] unreadable answer: ' . mb_substr($text, 0, 1500));
            throw new RuntimeException('The AI answer could not be read. Try "Read again", or type the details.');
        }

        return $json;
    }

    /* ---------------------------------------------------------- prompts --- */

    private function invoicePrompt(int $userId): string
    {
        $company = (string) ((new CompanyProfile())->find($userId)['company_name'] ?? '');
        $today   = date('Y-m-d');
        $us      = $company !== '' ? '"' . $company . '"' : 'our company (name unknown)';

        return <<<TXT
You read invoices for a small business in Malaysia. The images are the page(s) of ONE invoice.
Today is {$today}. Our company is {$us}.

Extract these fields. Copy text exactly as printed; do not invent anything. Use "" for anything not shown.
- type: "purchase" if the invoice was issued TO us by a supplier (we must pay), "sales" if WE issued it to a customer, "unknown" if unclear.
- invoice_number: the invoice No.
- party_name, party_address, party_phone: the OTHER party (the supplier for purchase, the customer for sales). Address may span lines (use \\n).
- payment_terms: e.g. "Net 45 days", "COD".
- reference_no: PO / GRN / DO number if shown.
- issue_date, due_date: as YYYY-MM-DD. Malaysian dates are usually DD/MM/YYYY. If only terms are given, leave due_date "".
- currency: 3-letter code (RM = MYR).
- items: every line item in order. quantity, unit_price, discount and amount as plain numbers without commas or currency (e.g. "2100.00"); discount "" if none.
- discount_amount: invoice-level discount only (not line discounts), "" if none.
- sst_rate: SST / service tax percent as a number (e.g. "6"), "" if none.
- total: the final amount payable.
TXT;
    }

    private function claimPrompt(): string
    {
        $today = date('Y-m-d');

        return <<<TXT
You read expense receipts for a small business in Malaysia. The images are the page(s) of ONE receipt
that a staff member paid for and wants to claim back. Today is {$today}.

Extract these fields. Do not invent anything; use "" for anything not shown.
- merchant: the shop or company that issued the receipt.
- title: a short description of what was bought, max 8 words (e.g. "Petrol at Shell Bangsar", "Lunch with client").
- expense_date: the receipt date as YYYY-MM-DD (Malaysian dates are usually DD/MM/YYYY).
- amount: the total paid, a plain number without commas or currency (e.g. "150.00").
- currency: 3-letter code (RM = MYR).
- category: "travel" (petrol, toll, parking, transport, hotel), "meals" (food and drinks),
  "office" (stationery, supplies, printing, equipment) or "other".
TXT;
    }

    private function invoiceSchema(): array
    {
        $str  = ['type' => 'string'];
        $item = [
            'type'                 => 'object',
            'properties'           => array_fill_keys(['sku', 'description', 'uom', 'quantity', 'unit_price', 'discount', 'amount'], $str),
            'required'             => ['sku', 'description', 'uom', 'quantity', 'unit_price', 'discount', 'amount'],
            'additionalProperties' => false,
        ];
        $fields = ['invoice_number', 'party_name', 'party_address', 'party_phone', 'payment_terms', 'reference_no',
                   'issue_date', 'due_date', 'currency', 'discount_amount', 'sst_rate', 'total'];

        return [
            'type'                 => 'object',
            'properties'           => ['type' => ['type' => 'string', 'enum' => ['purchase', 'sales', 'unknown']]]
                                      + array_fill_keys($fields, $str)
                                      + ['items' => ['type' => 'array', 'items' => $item]],
            'required'             => array_merge(['type'], $fields, ['items']),
            'additionalProperties' => false,
        ];
    }

    private function claimSchema(): array
    {
        $str = ['type' => 'string'];

        return [
            'type'                 => 'object',
            'properties'           => array_fill_keys(['merchant', 'title', 'expense_date', 'amount', 'currency'], $str)
                                      + ['category' => ['type' => 'string', 'enum' => ['travel', 'meals', 'office', 'other']]],
            'required'             => ['merchant', 'title', 'expense_date', 'amount', 'currency', 'category'],
            'additionalProperties' => false,
        ];
    }

    /* --------------------------------------------------------- cleaning --- */

    /** Only well-formed values go back to the form; anything doubtful becomes "". */
    private function cleanInvoice(array $j): array
    {
        $items = [];
        foreach (array_slice(is_array($j['items'] ?? null) ? $j['items'] : [], 0, 100) as $row) {
            if (!is_array($row) || self::str($row['description'] ?? '', 255) === '') {
                continue;
            }
            $items[] = [
                'sku'         => self::str($row['sku'] ?? '', 100),
                'description' => self::str($row['description'] ?? '', 255),
                'uom'         => self::str($row['uom'] ?? '', 20),
                'quantity'    => self::num($row['quantity'] ?? '', 3),
                'unit_price'  => self::num($row['unit_price'] ?? '', 2),
                'discount'    => self::num($row['discount'] ?? '', 2),
                'amount'      => self::num($row['amount'] ?? '', 2),
            ];
        }

        $type = (string) ($j['type'] ?? '');

        return [
            'type'            => isset(Invoice::TYPES[$type]) ? $type : '',
            'invoice_number'  => self::str($j['invoice_number'] ?? '', 100),
            'party_name'      => self::str($j['party_name'] ?? '', 200),
            'party_address'   => self::str($j['party_address'] ?? '', 1000),
            'party_phone'     => self::str($j['party_phone'] ?? '', 50),
            'payment_terms'   => self::str($j['payment_terms'] ?? '', 100),
            'reference_no'    => self::str($j['reference_no'] ?? '', 100),
            'issue_date'      => self::date($j['issue_date'] ?? ''),
            'due_date'        => self::date($j['due_date'] ?? ''),
            'currency'        => self::currency($j['currency'] ?? ''),
            'discount_amount' => self::num($j['discount_amount'] ?? '', 2),
            'sst_rate'        => self::num($j['sst_rate'] ?? '', 2),
            'total'           => self::num($j['total'] ?? '', 2),
            'items'           => $items,
        ];
    }

    private function cleanClaim(array $j): array
    {
        $category = (string) ($j['category'] ?? '');

        return [
            'merchant'     => self::str($j['merchant'] ?? '', 200),
            'title'        => self::str($j['title'] ?? '', 200),
            'expense_date' => self::date($j['expense_date'] ?? ''),
            'amount'       => self::num($j['amount'] ?? '', 2),
            'currency'     => self::currency($j['currency'] ?? ''),
            'category'     => in_array($category, ['travel', 'meals', 'office', 'other'], true) ? $category : '',
        ];
    }

    private static function str(mixed $v, int $max): string
    {
        $v = trim(str_replace(["\r\n", "\\n"], "\n", is_scalar($v) ? (string) $v : ''));

        return mb_substr($v, 0, $max);
    }

    /** "RM 2,100.00" -> "2100.00"; "" when it is not a plain non-negative number. */
    private static function num(mixed $v, int $decimals): string
    {
        $v = preg_replace('/[^0-9.]/', '', is_scalar($v) ? (string) $v : '') ?? '';

        return preg_match('/^\d{1,10}(\.\d+)?$/', $v) ? number_format((float) $v, $decimals, '.', '') : '';
    }

    private static function date(mixed $v): string
    {
        return InvoiceService::date(is_scalar($v) ? (string) $v : '') ?? '';
    }

    private static function currency(mixed $v): string
    {
        $v = strtoupper(trim(is_scalar($v) ? (string) $v : ''));
        $v = $v === 'RM' ? 'MYR' : $v;

        return in_array($v, Invoice::CURRENCIES, true) ? $v : '';
    }
}
