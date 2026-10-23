<?php
/**
 * InvoiceReceiptPdf.php - the official receipt for one payment on an invoice (A4 PDF).
 *
 * One receipt per payment (partial payments get one each). Black-and-white,
 * like OfficialReceiptPdf (orders): "OFFICIAL RECEIPT" centred at the top, the
 * company top left with the user's own logo on the right (none if not saved),
 * "Received From" and the receipt facts, then what was paid, the invoice
 * balance and the amount in words.
 *
 * Company details come from company() (the signed-in user's Company Profile,
 * falling back to src/company.php). Uses the bundled tFPDF (src/Lib/tfpdf)
 * with DejaVu Sans so Malay names print correctly.
 */
declare(strict_types=1);

namespace Src\Services;

use Src\Models\Receipt;

final class InvoiceReceiptPdf
{
    private const BLACK = [17, 17, 17];
    private const DARK  = [51, 51, 51];
    private const GREY  = [110, 110, 110];
    private const SHADE = [238, 238, 238];
    private const LINEC = [200, 200, 200];

    /**
     * @param array $receipt Receipt::find() row (with the invoice details)
     * @param array $company company(): name, reg_no, address, tel, email, logo
     * @return string the PDF bytes
     */
    public function render(array $receipt, array $company): string
    {
        self::load();
        $pdf = new \tFPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 40);
        $pdf->SetMargins(18, 18, 18);
        $pdf->AddFont('DejaVu', '', 'DejaVuSans.ttf', true);
        $pdf->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.ttf', true);

        $name = trim((string) ($company['name'] ?? '')) ?: APP_NAME;
        $pdf->SetTitle('Official Receipt ' . $receipt['receipt_number'], true);
        $pdf->SetAuthor($name, true);
        $pdf->SetCreator(APP_NAME, true);
        $pdf->AddPage();

        $cur = (string) ($receipt['currency'] ?: 'MYR');
        $L = 18;
        $R = 192;
        $W = $R - $L;

        /* ---- Title, centred at the very top ---- */
        $pdf->SetXY($L, 14);
        $this->font($pdf, 'B', 22, self::BLACK);
        $pdf->Cell($W, 11, 'OFFICIAL RECEIPT', 0, 1, 'C');
        $pdf->SetDrawColor(...self::BLACK);
        $pdf->SetLineWidth(0.5);
        $pdf->Line($L, 28, $R, 28);
        $top = 34;

        /* ---- Company (left) and the user's logo (right, only when saved) ---- */
        $logoBottom = $top;
        $logo = $company['logo_path'] ?? null;
        $img  = PdfImage::load($logo);
        if ($img !== null) {
            [$lw, $lh] = PdfImage::fit($img, 55, 24);
            $pdf->Image($logo, $R - $lw, $top, $lw, $lh);
            $logoBottom = $top + $lh;
        }

        $pdf->SetXY($L, $top);
        $this->font($pdf, '', 15, self::BLACK);
        $pdf->MultiCell(100, 7, $name, 0, 'L');
        $pdf->Ln(1.5);
        $this->font($pdf, '', 9.5, self::DARK);
        $address = trim((string) ($company['address'] ?? ''));
        if ($address !== '') {
            $pdf->SetX($L);
            $pdf->MultiCell(100, 5, $address, 0, 'L');
        }
        $this->font($pdf, '', 8.5, self::GREY);
        foreach (array_filter([
            !empty($company['reg_no']) ? 'Reg. No. ' . $company['reg_no'] : null,
            implode('   ', array_filter([
                !empty($company['tel']) ? 'Tel: ' . $company['tel'] : null,
                !empty($company['email']) ? (string) $company['email'] : null,
            ])) ?: null,
        ]) as $line) {
            $pdf->SetX($L);
            $pdf->MultiCell(100, 4.6, (string) $line, 0, 'L');
        }
        $y = max($pdf->GetY(), $logoBottom) + 12;

        /* ---- Received from (left) and receipt facts (right) ---- */
        $pdf->SetXY($L, $y);
        $this->font($pdf, 'B', 9.5, self::BLACK);
        $pdf->Cell(90, 5, 'Received From', 0, 1, 'L');
        $pdf->SetX($L);
        $this->font($pdf, '', 13, self::BLACK);
        $pdf->MultiCell(90, 7, (string) $receipt['party_name'], 0, 'L');
        $this->font($pdf, '', 9.5, self::DARK);
        if (!empty($receipt['party_address'])) {
            $pdf->SetX($L);
            $pdf->MultiCell(90, 5, (string) $receipt['party_address'], 0, 'L');
        }
        if (!empty($receipt['party_phone'])) {
            $pdf->SetX($L);
            $pdf->Cell(90, 5.2, 'Tel: ' . preg_replace('/^\s*(tel|phone|hp|h\/p)\s*[:.]\s*/i', '', (string) $receipt['party_phone']), 0, 1, 'L');
        }
        $leftEnd = $pdf->GetY();

        $facts = [
            ['Receipt #',    (string) $receipt['receipt_number']],
            ['Receipt date', date('d-m-Y', strtotime((string) $receipt['payment_date']))],
            ['Invoice #',    (string) $receipt['invoice_number']],
            ['Paid by',      Receipt::METHODS[$receipt['method']] ?? ucfirst((string) $receipt['method'])],
        ];
        if (!empty($receipt['reference'])) {
            $facts[] = ['Reference', (string) $receipt['reference']];
        }
        $pdf->SetY($y + 1);
        foreach ($facts as [$k, $v]) {
            $pdf->SetX($R - 92);
            $this->font($pdf, 'B', 9.5, self::BLACK);
            $pdf->Cell(36, 6.2, $k, 0, 0, 'R');
            $this->font($pdf, '', 9.5, self::DARK);
            $pdf->Cell(56, 6.2, $v, 0, 1, 'R');
        }
        $y = max($leftEnd, $pdf->GetY()) + 10;

        /* ---- What was paid ---- */
        $amount = (float) $receipt['amount'];
        $pdf->SetXY($L, $y);
        $pdf->SetFillColor(...self::BLACK);
        $this->font($pdf, 'B', 9.5, [255, 255, 255]);
        $pdf->Cell($W - 40, 9, '  Description', 0, 0, 'L', true);
        $pdf->Cell(40, 9, 'Amount (' . $cur . ')  ', 0, 1, 'R', true);

        $this->font($pdf, '', 9.5, self::BLACK);
        $paidInFull = (float) $receipt['invoice_paid'] >= (float) $receipt['invoice_amount'] - 0.001;
        $pdf->SetX($L);
        $pdf->Cell($W - 40, 10, '  ' . ($paidInFull ? 'Payment' : 'Part payment') . ' for invoice ' . $receipt['invoice_number'], 0, 0, 'L');
        $pdf->Cell(40, 10, number_format($amount, 2) . '  ', 0, 1, 'R');
        $pdf->SetDrawColor(...self::BLACK);
        $pdf->SetLineWidth(0.4);
        $pdf->Line($L, $pdf->GetY() + 1, $R, $pdf->GetY() + 1);
        $pdf->Ln(3);

        /* ---- Invoice balance (right) ---- */
        $tx  = $R - 82;
        $row = function (string $label, string $value, bool $strong = false) use ($pdf, $tx): void {
            $pdf->SetX($tx);
            $this->font($pdf, $strong ? 'B' : '', $strong ? 10.5 : 9.5, self::BLACK);
            $pdf->Cell(46, $strong ? 10 : 7.5, '  ' . $label, 0, 0, 'L', $strong);
            $pdf->Cell(36, $strong ? 10 : 7.5, $value . '  ', 0, 1, 'R', $strong);
        };
        $pdf->SetFillColor(...self::SHADE);
        $row('Amount received', $cur . ' ' . number_format($amount, 2), true);
        $row('Invoice total', number_format((float) $receipt['invoice_amount'], 2));
        $row('Paid to date', number_format((float) $receipt['invoice_paid'], 2));
        $row('Balance due', number_format(max(0, (float) $receipt['invoice_amount'] - (float) $receipt['invoice_paid']), 2));

        $pdf->Ln(4);
        if ($cur === 'MYR') {
            $pdf->SetX($L);
            $this->font($pdf, 'B', 8.5, self::BLACK);
            $pdf->Cell(32, 5, 'Amount in words:', 0, 0, 'L');
            $this->font($pdf, '', 8.5, self::DARK);
            $pdf->MultiCell($W - 32, 5, OfficialReceiptPdf::ringgitInWords($amount), 0, 'L');
        }

        /* ---- Notes (bottom) ---- */
        $pdf->SetAutoPageBreak(false);
        $notes = ['Thank you. This receipt confirms the payment above for invoice ' . $receipt['invoice_number'] . '. Please keep it for your records.'];
        if (!empty($receipt['notes'])) {
            $notes[] = 'Note: ' . $receipt['notes'];
        }
        $contact = array_filter([(string) ($company['email'] ?? ''), (string) ($company['tel'] ?? '')]);
        if ($contact !== []) {
            $notes[] = 'For questions, contact us at ' . implode(' or ', $contact) . '.';
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
        $pdf->Cell($W, 4, $receipt['receipt_number'] . '  ·  Generated ' . date('d-m-Y, g:i a'), 0, 0, 'C');

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

}
