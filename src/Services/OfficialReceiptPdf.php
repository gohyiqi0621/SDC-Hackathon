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
 * Black-and-white layout: company + address top left, logo top right, the
 * title, "Received From" and the receipt facts, a black-header item table,
 * totals, amount in words, and Notes at the bottom.
 *
 * Uses tFPDF (src/Lib/tfpdf, LGPL) with DejaVu Sans, so names in Malay and
 * other Latin scripts print correctly.
 */
declare(strict_types=1);

namespace Src\Services;

use RuntimeException;

final class OfficialReceiptPdf
{
    // Black and white only (prints well on any printer).
    private const BLACK = [17, 17, 17];
    private const DARK  = [51, 51, 51];
    private const GREY  = [110, 110, 110];
    private const SHADE = [238, 238, 238];
    private const LINEC = [200, 200, 200];

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

        self::load();
        $pdf = new \tFPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 40);
        $pdf->SetMargins(18, 18, 18);
        $pdf->AddFont('DejaVu', '', 'DejaVuSans.ttf', true);
        $pdf->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.ttf', true);
        $pdf->SetTitle('Official Receipt ' . self::receiptNo($order), true);
        $pdf->SetAuthor((string) ($company['company_name'] ?? APP_NAME), true);
        $pdf->SetCreator(APP_NAME, true);
        $pdf->AddPage();

        $cur    = (string) ($order['currency'] ?: 'MYR');
        $issued = strtotime((string) ($order['reviewed_at'] ?: $order['updated_at'] ?: 'now'));
        $name   = trim((string) ($company['company_name'] ?? '')) ?: APP_NAME;
        $L = 18;          // left margin
        $R = 192;         // right edge
        $W = $R - $L;     // 174 mm

        /* ---- Company (left) and logo (right) ---- */
        $pdf->SetXY($L, 20);
        $this->font($pdf, '', 15, self::BLACK);
        $pdf->MultiCell(100, 7, $name, 0, 'L');
        $pdf->Ln(1.5);
        $this->font($pdf, '', 9.5, self::DARK);
        $address = trim((string) ($company['company_address'] ?? ''));
        if ($address !== '') {
            $pdf->SetX($L);
            $pdf->MultiCell(100, 5, $address, 0, 'L');
        }
        $this->font($pdf, '', 8.5, self::GREY);
        foreach (array_filter([
            !empty($company['registration_no']) ? 'Reg. No. ' . $company['registration_no'] : null,
            implode('   ', array_filter([
                !empty($company['company_phone']) ? 'Tel: ' . $company['company_phone'] : null,
                !empty($company['company_email']) ? (string) $company['company_email'] : null,
            ])) ?: null,
        ]) as $line) {
            $pdf->SetX($L);
            $pdf->MultiCell(100, 4.6, (string) $line, 0, 'L');
        }
        $leftBottom = $pdf->GetY();

        $logo = $this->logoFile($company);
        $logoBottom = 20;
        if ($logo !== null) {
            [$w, $h] = $this->fit($logo, 60, 24);
            $pdf->Image($logo, $R - $w, 20, $w, $h);
            $logoBottom = 20 + $h;
            @unlink($logo);
        }

        /* ---- Title ---- */
        $y = max($leftBottom, $logoBottom) + 12;
        $pdf->SetXY($L, $y);
        $this->font($pdf, 'B', 24, self::BLACK);
        $pdf->Cell($W, 11, 'OFFICIAL RECEIPT', 0, 1, 'R');
        $y = $pdf->GetY() + 8;

        /* ---- Received from (left) and receipt facts (right) ---- */
        $customer = trim((string) ($order['customer_name'] ?? '')) ?: 'Customer';
        $pdf->SetXY($L, $y);
        $this->font($pdf, 'B', 9.5, self::BLACK);
        $pdf->Cell(90, 5, 'Received From', 0, 1, 'L');
        $pdf->SetX($L);
        $this->font($pdf, '', 13, self::BLACK);
        $pdf->MultiCell(90, 7, $customer, 0, 'L');
        $this->font($pdf, '', 9.5, self::DARK);
        foreach (array_filter([
            !empty($order['customer_phone']) ? 'Tel: ' . $order['customer_phone'] : null,
            !empty($order['customer_username']) ? 'Telegram: @' . $order['customer_username'] : null,
        ]) as $c) {
            $pdf->SetX($L);
            $pdf->Cell(90, 5.2, $c, 0, 1, 'L');
        }
        $leftEnd = $pdf->GetY();

        $bank  = trim((string) ($company['payment_bank'] ?? ''));
        $facts = [
            ['Receipt #',    self::receiptNo($order)],
            ['Receipt date', date('d-m-Y', $issued)],
            ['Order #',      (string) $order['order_no']],
            ['Order date',   date('d-m-Y', strtotime((string) $order['created_at']))],
            ['Paid by',      $bank !== '' ? 'Transfer · ' . $bank : 'Bank transfer'],
            ['Paid date',    date('d-m-Y', $issued)],
        ];
        $pdf->SetY($y + 1);
        foreach ($facts as [$k, $v]) {
            $pdf->SetX($R - 92);
            $this->font($pdf, 'B', 9.5, self::BLACK);
            $pdf->Cell(36, 6.2, $k, 0, 0, 'R');
            $this->font($pdf, '', 9.5, self::DARK);
            $pdf->Cell(56, 6.2, $v, 0, 1, 'R');
        }
        $y = max($leftEnd, $pdf->GetY()) + 10;

        /* ---- Items ---- */
        $cols = [['QTY', 16, 'C'], ['Description', 98, 'L'], ['Unit Price', 30, 'R'], ['Amount', 30, 'R']];
        $pdf->SetXY($L, $y);
        $pdf->SetFillColor(...self::BLACK);
        $this->font($pdf, 'B', 9.5, [255, 255, 255]);
        foreach ($cols as $n => [$h, $w, $a]) {
            $pdf->Cell($w, 9, ($a === 'L' ? '  ' : '') . $h . ($a === 'R' ? '  ' : ''), 0, 0, $a, true);
        }
        $pdf->Ln();

        $this->font($pdf, '', 9.5, self::BLACK);
        $subtotal = 0.0;
        foreach (array_values($items) as $item) {
            $desc = (string) $item['product_name'] . (!empty($item['sku']) ? '  (' . $item['sku'] . ')' : '');
            $rowH = max(8.5, 5 * $this->lineCount($pdf, $desc, $cols[1][1] - 6) + 3.5);
            if ($pdf->GetY() + $rowH > 297 - 70) {
                $pdf->AddPage();
            }
            $rowY = $pdf->GetY();
            $pdf->SetXY($L, $rowY);
            $pdf->Cell($cols[0][1], $rowH, (string) (int) $item['quantity'], 0, 0, 'C');
            $pdf->SetXY($L + $cols[0][1] + 3, $rowY + 1.8);
            $pdf->MultiCell($cols[1][1] - 6, 5, $desc, 0, 'L');
            $pdf->SetXY($L + $cols[0][1] + $cols[1][1], $rowY);
            $pdf->Cell($cols[2][1], $rowH, number_format((float) $item['unit_price'], 2) . '  ', 0, 0, 'R');
            $pdf->Cell($cols[3][1], $rowH, number_format((float) $item['line_total'], 2) . '  ', 0, 0, 'R');
            $pdf->SetXY($L, $rowY + $rowH);
            $subtotal += (float) $item['line_total'];
        }
        if ($items === []) {
            $pdf->SetX($L);
            $pdf->Cell($W, 8.5, '  Order ' . $order['order_no'], 0, 1, 'L');
        }
        $pdf->SetDrawColor(...self::BLACK);
        $pdf->SetLineWidth(0.4);
        $pdf->Line($L, $pdf->GetY() + 1, $R, $pdf->GetY() + 1);
        $pdf->Ln(3);

        /* ---- Totals (right) ---- */
        $total = (float) $order['total'];
        $tx = $R - 82;
        $row = function (string $label, string $value, bool $strong = false) use ($pdf, $tx): void {
            $pdf->SetX($tx);
            $this->font($pdf, $strong ? 'B' : '', $strong ? 10.5 : 9.5, self::BLACK);
            $pdf->Cell(46, $strong ? 10 : 7.5, '  ' . $label, 0, 0, 'L', $strong);
            $pdf->Cell(36, $strong ? 10 : 7.5, $value . '  ', 0, 1, 'R', $strong);
        };
        $row('Subtotal', number_format($items === [] ? $total : $subtotal, 2));
        if ($items !== [] && abs($subtotal - $total) >= 0.01) {
            $row($total < $subtotal ? 'Discount' : 'Other charges', ($total < $subtotal ? '-' : '') . number_format(abs($total - $subtotal), 2));
        }
        $pdf->Line($tx, $pdf->GetY() + 0.5, $R, $pdf->GetY() + 0.5);
        $pdf->Ln(1);
        $pdf->SetFillColor(...self::SHADE);
        $totalY = $pdf->GetY();
        $row('Total (' . $cur . ')', $cur . ' ' . number_format($total, 2), true);
        $pdf->Line($tx, $pdf->GetY(), $R, $pdf->GetY());

        $pdf->SetY(max($pdf->GetY(), $totalY + 10) + 6);
        if ($cur === 'MYR') {
            $pdf->SetX($L);
            $this->font($pdf, 'B', 8.5, self::BLACK);
            $pdf->Cell(32, 5, 'Amount in words:', 0, 0, 'L');
            $this->font($pdf, '', 8.5, self::DARK);
            $pdf->MultiCell($W - 32, 5, self::ringgitInWords($total), 0, 'L');
        }

        /* ---- Notes (bottom of the last page) ---- */
        $pdf->SetAutoPageBreak(false);
        $notes = [];
        $notes[] = 'Thank you for your purchase! Payment for order ' . $order['order_no'] . ' has been received and verified'
            . ($order['receipt_uploaded_at'] ? ' (bank reference received ' . date('d-m-Y, g:i a', strtotime((string) $order['receipt_uploaded_at'])) . ')' : '')
            . '. Please keep this receipt for your records.';
        if (!empty($order['delivery_note'])) {
            $notes[] = 'Order note: ' . $order['delivery_note'];
        }
        $contact = array_filter([(string) ($company['company_email'] ?? ''), (string) ($company['company_phone'] ?? '')]);
        if ($contact !== []) {
            $notes[] = 'For questions or support, contact us at ' . implode(' or ', $contact) . '.';
        }
        $pdf->SetY(-62);
        $pdf->SetX($L);
        $this->font($pdf, 'B', 10, self::BLACK);
        $pdf->Cell($W, 6, 'Notes', 0, 1, 'L');
        $this->font($pdf, '', 9, self::DARK);
        foreach ($notes as $n) {
            $pdf->SetX($L);
            $pdf->MultiCell($W, 5, $n, 0, 'L');
            $pdf->Ln(1.5);
        }
        $pdf->SetY(-22);
        $pdf->SetDrawColor(...self::LINEC);
        $pdf->Line($L, $pdf->GetY(), $R, $pdf->GetY());
        $pdf->Ln(2);
        $this->font($pdf, '', 7.5, self::GREY);
        $pdf->SetX($L);
        $pdf->Cell($W, 4, 'This is a computer-generated official receipt from ' . $name . '. No signature is required.', 0, 1, 'C');
        $pdf->SetX($L);
        $pdf->Cell($W, 4, self::receiptNo($order) . '  ·  Generated ' . date('d-m-Y, g:i a'), 0, 0, 'C');

        return $pdf->Output('S');
    }

    /* ------------------------------------------------------------------ */

    private static function load(): void
    {
        if (class_exists('tFPDF', false)) {
            return;
        }
        $dir = SRC_ROOT . '/Lib/tfpdf/';
        if (!defined('FPDF_FONTPATH')) {
            define('FPDF_FONTPATH', $dir . 'font/');
        }
        require_once $dir . 'font/unifont/ttfonts.php';
        require_once $dir . 'tfpdf.php';
    }

    private function font(\tFPDF $pdf, string $style, float $size, array $rgb): void
    {
        $pdf->SetFont('DejaVu', $style, $size);
        $pdf->SetTextColor(...$rgb);
    }

    /** How many lines $text needs in a cell $width mm wide (current font). */
    private function lineCount(\tFPDF $pdf, string $text, float $width): int
    {
        $lines = 0;
        foreach (explode("\n", $text) as $para) {
            $line = '';
            $lines++;
            foreach (preg_split('/\s+/u', $para) ?: [] as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($pdf->GetStringWidth($try) > $width && $line !== '') {
                    $lines++;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
        }
        return max(1, $lines);
    }

    /** The company logo as a temporary PNG/JPG tFPDF can read (WebP and others go through GD), or null. */
    private function logoFile(array $company): ?string
    {
        $rel = (string) ($company['company_logo_url'] ?? '');
        if ($rel === '' || str_contains($rel, '..') || preg_match('#^https?://#i', $rel)) {
            return null;
        }
        $path = PUBLIC_ROOT . '/' . ltrim($rel, '/');
        if (!is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            return null;
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $tmp  = tempnam(sys_get_temp_dir(), 'orlogo');
        if ($tmp === false) {
            return null;
        }

        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring((string) file_get_contents($path));
            if ($img !== false) {
                imagesavealpha($img, true);
                imageinterlace($img, false);
                $ok = imagepng($img, $tmp . '.png');
                imagedestroy($img);
                @unlink($tmp);
                return $ok ? $tmp . '.png' : null;
            }
        }
        if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
            $ext = $mime === 'image/png' ? '.png' : '.jpg';
            @unlink($tmp);
            return copy($path, $tmp . $ext) ? $tmp . $ext : null;
        }
        @unlink($tmp);
        return null;
    }

    /** @return array{0: float, 1: float} width, height in mm inside a box */
    private function fit(string $file, float $maxW, float $maxH): array
    {
        $size = @getimagesize($file);
        if (!$size || $size[0] <= 0 || $size[1] <= 0) {
            return [$maxH, $maxH];
        }
        $ratio = min($maxW / $size[0], $maxH / $size[1]);
        return [round($size[0] * $ratio, 2), round($size[1] * $ratio, 2)];
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
