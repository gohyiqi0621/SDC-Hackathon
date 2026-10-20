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

    public function build(array $config): string
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

        foreach (self::EMPTY as $key => $fallback) {
            if (trim((string) $values[$key]) === '') {
                $values[$key] = $fallback;
            }
        }

        $message = (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            static fn (array $m): string => (string) ($values[$m[1]] ?? $m[0]),
            str_replace("\r\n", "\n", $template)
        );

        if (trim((string) $config['general']) !== '') {
            $message = rtrim($message) . "\n\n## 6. Additional Instructions From " . $config['company_name'] . "\n"
                . trim((string) $config['general']) . "\n";
        }

        return trim($message) . "\n";
    }
}
