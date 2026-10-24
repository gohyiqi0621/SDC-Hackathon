<?php
/**
 * OfficialReceiptPdf.php - the business's official receipt for a paid order (A4 PDF).
 *
 * Made only after staff approved the customer's bank reference (status
 * "Payment successful" or "Shipped"). Everything about the business comes from
 * Company Profile (company_profiles): name, logo, registration no., address,
 * phone, email. Order, items and customer come from orders / order_items.
 *
 *   Receipt no.  "OR-" + the order number without "ORD-" (e.g. OR-261021-25NJ),
 *                so it is fixed for an order and never needs storing.
 *   Date         when staff approved the payment (orders.reviewed_at).
 *
 * Layout: DocumentPdf, the same formal black-and-white design as the invoice.
 */
declare(strict_types=1);

namespace Src\Services;

use RuntimeException;

final class OfficialReceiptPdf
{
    /** Statuses that have an official receipt. */
    public const STATUSES = ['paid', 'shipped'];

    public static function receiptNo(array $order): string
    {
        return 'OR-' . preg_replace('/^ORD-/i', '', (string) $order['order_no']);
    }

    public static function fileName(array $order): string
    {
        return 'Official-Receipt-' . preg_replace('/[^A-Za-z0-9-]/', '', self::receiptNo($order)) . '.pdf';
    }

    /**
     * @param array $order   orders row (paid or shipped)
     * @param array $items   order_items rows
     * @param array $company company_profiles row ([] when not saved)
     * @return string the PDF bytes
     */
    public function render(array $order, array $items, array $company): string
    {
        if (!in_array((string) $order['status'], self::STATUSES, true)) {
            throw new RuntimeException('An official receipt is only issued after the payment is approved.');
        }

        $co     = DocumentPdf::company($company);
        $cur    = (string) ($order['currency'] ?: 'MYR');
        $issued = strtotime((string) ($order['reviewed_at'] ?: $order['updated_at'] ?: 'now'));
        $no     = self::receiptNo($order);

        $doc = new DocumentPdf('Official Receipt ' . $no, $co,
            'This is a computer-generated official receipt. No signature is required.');
        $doc->footerRef($no . '  ·  Generated ' . date('d-m-Y, g:i a'));
        $doc->header('Official Receipt');

        $customer = trim((string) ($order['customer_name'] ?? '')) ?: 'Customer';
        $doc->parties('Received From', $customer, [
            !empty($order['customer_phone']) ? 'Tel: ' . $order['customer_phone'] : '',
            !empty($order['customer_username']) ? 'Telegram: @' . $order['customer_username'] : '',
        ], [
            ['Receipt No.',  $no],
            ['Receipt Date', date('d-m-Y', $issued)],
            ['Order No.',    (string) $order['order_no']],
            ['Order Date',   date('d-m-Y', strtotime((string) $order['created_at']))],
            ['Payment',      $co['bank'] !== '' ? 'Bank transfer (' . $co['bank'] . ')' : 'Bank transfer'],
            ['Currency',     $cur],
        ]);

        $rows = [];
        $subtotal = 0.0;
        foreach (array_values($items) as $i => $item) {
            $rows[] = [
                (string) ($i + 1),
                (string) $item['product_name'],
                (string) ($item['sku'] ?? '') ?: '-',
                (string) (int) $item['quantity'],
                number_format((float) $item['unit_price'], 2),
                number_format((float) $item['line_total'], 2),
            ];
            $subtotal += (float) $item['line_total'];
        }
        $doc->table([
            ['No.', 12, 'C'],
            ['Description', 72, 'L', true],
            ['SKU', 24, 'L'],
            ['Qty', 14, 'C'],
            ['Unit Price', 26, 'R'],
            ['Amount', 26, 'R'],
        ], $rows, 'Order ' . $order['order_no']);

        $total  = (float) $order['total'];
        $totals = [['Subtotal', number_format($items === [] ? $total : $subtotal, 2)]];
        if ($items !== [] && abs($subtotal - $total) >= 0.01) {
            $totals[] = [$total < $subtotal ? 'Discount' : 'Other charges', ($total < $subtotal ? '-' : '') . number_format(abs($total - $subtotal), 2)];
        }
        $doc->totals($totals, 'Amount Paid (' . $cur . ')', number_format($total, 2));

        if ($cur === 'MYR') {
            $doc->labelled('Amount in words:', self::ringgitInWords($total));
        }

        $contact = array_filter([$co['email'], $co['tel']]);
        $doc->section('Notes', [
            'Payment for order ' . $order['order_no'] . ' has been received and verified'
                . ($order['receipt_uploaded_at'] ? ' (bank reference received ' . date('d-m-Y, g:i a', strtotime((string) $order['receipt_uploaded_at'])) . ')' : '')
                . '. Please keep this receipt for your records.',
            !empty($order['delivery_note']) ? 'Order note: ' . $order['delivery_note'] : '',
            $contact !== [] ? 'For questions, contact us at ' . implode(' or ', $contact) . '.' : '',
            'Thank you for your purchase.',
        ]);

        return $doc->output();
    }

    /** "Ringgit Malaysia One Hundred Twenty And Sen Fifty Only" */
    public static function ringgitInWords(float $amount): string
    {
        $ringgit = (int) floor(round($amount, 2));
        $sen     = (int) round(($amount - $ringgit) * 100);
        $text    = 'Ringgit Malaysia ' . ($ringgit === 0 ? 'Zero' : self::words($ringgit));
        if ($sen > 0) {
            $text .= ' And Sen ' . self::words($sen);
        }
        return $text . ' Only';
    }

    private static function words(int $n): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
                 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $out  = [];
        foreach ([[1000000000, 'Billion'], [1000000, 'Million'], [1000, 'Thousand'], [1, '']] as [$unit, $name]) {
            $chunk = intdiv($n, $unit);
            $n %= $unit;
            if ($chunk === 0) {
                continue;
            }
            $part = [];
            if ($chunk >= 100) {
                $part[] = $ones[intdiv($chunk, 100)] . ' Hundred';
                $chunk %= 100;
            }
            if ($chunk >= 20) {
                $part[] = $tens[intdiv($chunk, 10)] . ($chunk % 10 ? ' ' . $ones[$chunk % 10] : '');
            } elseif ($chunk > 0) {
                $part[] = $ones[$chunk];
            }
            $out[] = trim(implode(' ', $part) . ' ' . $name);
        }
        return implode(' ', $out);
    }
}
