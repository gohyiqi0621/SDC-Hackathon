<?php
/**
 * SystemMessageBuilder.php - fills src/Prompts/system_message.md with one
 * business's configuration (from AssistantConfigService).
 *
 * The template is a plain Markdown file with {{placeholders}}, so the wording
 * can be changed without touching code. An empty SOP becomes a short,
 * explicit "no SOP" note instead of a blank, so the model does not guess.
 * If the business wrote general instructions, they are added at the end.
 */
declare(strict_types=1);

namespace Src\Services;

final class SystemMessageBuilder
{
    private const TEMPLATE = SRC_ROOT . '/Prompts/system_message.md';

    private const EMPTY = [
        'customer_service'   => 'No specific SOP has been provided. Answer politely from verified company information and offer to connect the customer with staff when unsure.',
        'booking_sop'        => 'No specific booking SOP has been provided. Collect the details below and confirm with the customer before booking.',
        'salesman_sop'       => 'No additional sales SOP has been provided.',
        'company_background' => 'No company background has been provided.',
    ];

    /** How an order's status is described to the AI. */
    private const ORDER_STATUS = [
        'pending_payment'   => 'UNPAID, waiting for the customer to pay and send the receipt',
        'payment_submitted' => 'BANK REFERENCE RECEIVED, staff are verifying it (not unpaid: never ask the customer to pay it again)',
        'paid'              => 'PAID (payment successful), being prepared for shipping',
        'shipped'           => 'PAID and SHIPPED',
        'payment_rejected'  => 'payment rejected by staff, waiting for a new receipt',
        'cancelled'         => 'cancelled',
    ];

    /** The "This customer's orders" section, newest first. */
    private static function ordersSection(array $orders): string
    {
        $lines = [];
        foreach ($orders as $order) {
            $items = implode(', ', array_map(
                static fn (array $i): string => $i['product_name'] . ' x' . (int) $i['quantity'],
                $order['items'] ?? []
            ));
            $lines[] = '- ' . $order['order_no'] . ' (' . date('j M, g:i a', strtotime((string) $order['created_at'])) . '): '
                . $items . ' - ' . format_money($order['total'], $order['currency']) . ' - '
                . (self::ORDER_STATUS[$order['status']] ?? $order['status']);
        }

        return "## This customer's orders (newest first)\n" . implode("\n", $lines) . "\n\n"
            . "- Every order above stays as it is. \"One more X\", \"add X\" or any change means a NEW, separate order with ONLY X (quantity as asked, 1 if not said), with its own payment. Never repeat earlier items, never say \"updated order\", never add totals of different orders together.\n"
            . "- When telling the customer what is still to pay, list ONLY the orders marked UNPAID or payment rejected. Orders with a bank reference received are being verified, not unpaid.\n"
            . "- Only mention order numbers listed above or returned by buy_product in this chat. Never invent an order number: if buy_product did not return success, the order was not created.\n"
            . "- When unsure what the customer wants in the new order, ask before calling buy_product.\n\n";
    }

    /** reply_language => [name used in the prompt, how to write it] */
    public const LANGUAGES = [
        'en' => ['English', 'English'],
        'ms' => ['Bahasa Melayu', 'Bahasa Melayu (Malay)'],
        'zh' => ['Chinese', 'Simplified Chinese (简体中文)'],
        'ta' => ['Tamil', 'Tamil (தமிழ்)'],
    ];

    /**
     * The language section, at the top of the prompt, and a short check at
     * the very end (models follow the first and last instructions best).
     *
     * @return array{0: string, 1: string} [language_rule, language_check]
     */
    public static function languageRules(string $setting): array
    {
        if (isset(self::LANGUAGES[$setting])) {
            [$name, $write] = self::LANGUAGES[$setting];

            return [
                "Always reply in {$write}, whatever language the customer writes in.\n"
                . "- If the customer writes in another language, still answer in {$name}; you may add one short line in their language saying you will continue in {$name}.\n"
                . "- Product names, prices, order numbers and SKUs stay exactly as written.\n"
                . "- The company information and SOPs below may be in another language: translate what you use into {$name}.",
                "Is the whole reply in {$name}? If not, rewrite it in {$name} before sending.",
            ];
        }

        return [
            "Reply in the same language as the customer's LATEST message. Decide again for every message: customers may switch languages, and earlier messages in this chat do not decide the language.\n"
            . "- English message -> reply in English. Bahasa Melayu -> reply in Bahasa Melayu. Chinese -> reply in Simplified Chinese (or Traditional if the customer used Traditional). Tamil -> reply in Tamil.\n"
            . "- Mixed Malaysian style (e.g. Manglish, \"boleh tak\", \"lah\", \"can or not\"): reply in the main language of the message, in the same casual style.\n"
            . "- Very short or unclear messages (\"ok\", \"?\", \"hi\", emoji only, a number): use the language the customer used most recently; if there is none, use English.\n"
            . "- The company information, SOPs and tool results below are written in one language only for you. They never decide the reply language: translate what you use.\n"
            . "- Never switch to a language the customer did not use. Do not reply in Chinese to an English or Malay message.\n"
            . "- Product names, prices, order numbers and SKUs stay exactly as written.",
            "Which language is the customer's latest message in? Is your whole reply in that same language? If not, rewrite it in that language before sending.",
        ];
    }

    /** Common Malay words, to tell a Malay message from an English one (both use Latin letters). */
    private const MALAY_WORDS = ['saya', 'aku', 'awak', 'kamu', 'anda', 'kami', 'kita', 'nak', 'mahu', 'mau', 'apa', 'apakah',
        'boleh', 'tak', 'tidak', 'ada', 'harga', 'berapa', 'jual', 'beli', 'terima', 'kasih', 'untuk', 'dengan', 'yang',
        'ini', 'itu', 'mana', 'bila', 'macam', 'mana', 'tolong', 'sila', 'dah', 'sudah', 'belum', 'lagi', 'sangat', 'banyak',
        'murah', 'mahal', 'hantar', 'bayar', 'pesan', 'tempah', 'buka', 'tutup', 'jam', 'hari', 'esok', 'semalam', 'kat',
        'dekat', 'pukul', 'sikit', 'lah', 'ke', 'ni', 'tu', 'je', 'jer', 'pun', 'dan', 'atau', 'tapi', 'kenapa', 'bagaimana',
        'macamana', 'camne', 'selamat', 'pagi', 'petang', 'malam', 'encik', 'cik', 'puan', 'makan', 'minum', 'kedai', 'barang'];

    private const ENGLISH_WORDS = ['the', 'is', 'are', 'what', 'how', 'much', 'many', 'do', 'does', 'you', 'your', 'have',
        'can', 'want', 'need', 'please', 'price', 'order', 'buy', 'any', 'recommend', 'when', 'where', 'which', 'thanks',
        'thank', 'open', 'close', 'today', 'tomorrow', 'and', 'or', 'but', 'for', 'with', 'this', 'that', 'there', 'u', 'ur',
        'pls', 'plz', 'would', 'like', 'menu', 'sell', 'available', 'delivery', 'pay', 'payment'];

    /**
     * The language of one customer message, when it is clear:
     * 'zh', 'ta', 'ms', 'en', or null (too short / unclear, e.g. "hi", "ok", "👍").
     */
    public static function detectLanguage(string $message): ?string
    {
        $text = trim($message);
        if ($text === '') {
            return null;
        }
        if (preg_match('/\p{Han}/u', $text)) {
            return 'zh';
        }
        if (preg_match('/\p{Tamil}/u', $text)) {
            return 'ta';
        }

        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 2) {
            return null;
        }

        $malay   = count(array_intersect($words, self::MALAY_WORDS));
        $english = count(array_intersect($words, self::ENGLISH_WORDS));

        if ($malay > $english) {
            return 'ms';
        }
        if ($english > $malay) {
            return 'en';
        }
        return null;
    }

    /**
     * @param string $latestMessage the customer's message being answered: in
     *        "same as customer" mode its language is stated in the prompt, which
     *        keeps the model from carrying on in the previous language.
     */
    public function build(array $config, string $latestMessage = '', array $recentOrders = []): string
    {
        $template = is_file(self::TEMPLATE) ? (string) file_get_contents(self::TEMPLATE) : '';

        $values = [
            'assistant_name'     => $config['assistant_name'],
            'personality'        => $config['personality'],
            'tone'               => $config['tone'],
            'company_name'       => $config['company_name'],
            'business_category'  => $config['business_category'],
            'company_background' => $config['company_background'],
            'customer_service'   => $config['customer_service'],
            'booking_sop'        => $config['booking_sop'],
            'salesman_sop'       => $config['salesman_sop'],
        ];
        $setting = (string) ($config['reply_language'] ?? 'auto');
        [$values['language_rule'], $values['language_check']] = self::languageRules($setting);

        // "Today" for bookings: the AI has no clock of its own.
        $zone = new \DateTimeZone(date_default_timezone_get());
        $now  = new \DateTimeImmutable('now', $zone);
        $values['current_datetime'] = $now->format('l, j F Y, g:i A') . ' (' . $zone->getName() . ', UTC' . $now->format('P') . ')';
        $values['timezone']         = $zone->getName();
        $values['datetime_example'] = $now->setTime(15, 0)->modify('+1 day')->format('Y-m-d\TH:i:sP')
                                    . ' for 3:00 PM tomorrow';

        $detected = !isset(self::LANGUAGES[$setting]) ? self::detectLanguage($latestMessage) : null;
        if ($detected !== null) {
            [$name, $write] = self::LANGUAGES[$detected];
            $values['language_rule']  .= "\n\nThe customer's latest message is in {$name}. Reply in {$write}.";
            $values['language_check'] .= "\nThe customer's latest message is in {$name}: the reply must be in {$name}.";
        }

        foreach (self::EMPTY as $key => $fallback) {
            if (trim((string) $values[$key]) === '') {
                $values[$key] = $fallback;
            }
        }

        $message = (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/u',
            static fn (array $m): string => (string) ($values[$m[1]] ?? $m[0]),
            str_replace("\r\n", "\n", $template)
        );

        // This customer's orders, before the final check.
        if ($recentOrders !== []) {
            $section = self::ordersSection($recentOrders);
            $message = str_contains($message, '## Final check before every reply')
                ? str_replace('## Final check before every reply', $section . '## Final check before every reply', $message)
                : rtrim($message) . "\n\n" . $section;
        }

        // Extra instructions go before the final language check, so it stays last.
        if (trim((string) $config['general']) !== '') {
            $extra   = "## 6. Additional Instructions From " . $config['company_name'] . "\n" . trim((string) $config['general']) . "\n\n";
            $message = str_contains($message, '## Final check before every reply')
                ? str_replace('## Final check before every reply', $extra . '## Final check before every reply', $message)
                : rtrim($message) . "\n\n" . $extra;
        }

        return trim($message) . "\n";
    }
}
