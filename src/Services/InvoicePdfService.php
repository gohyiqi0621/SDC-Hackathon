<?php
/** Dependency-free A4 invoice renderer, styled after the supplied purchase invoice. */
declare(strict_types=1);

namespace Src\Services;

final class InvoicePdfService
{
    private const W = 595.28;
    private const H = 841.89;
    private const BLUE = [0.12, 0.31, 0.48];
    private array $pages = [];
    private string $content = '';
    private float $y = 0;
    private int $rowNo = 0;

    public function render(array $invoice, array $items): string
    {
        $this->pages = [];
        $this->rowNo = 0;
        $this->startPage($invoice, true);
        foreach ($items as $item) {
            $lines = $this->wrap((string) ($item['description'] ?? ''), 34);
            $rowHeight = max(22, count($lines) * 11 + 9);
            if ($this->y + $rowHeight > self::H - 160) {
                $this->finishPage();
                $this->startPage($invoice, false);
            }
            $this->itemRow($item, $lines, $rowHeight, (string) ($invoice['currency'] ?? 'MYR'));
        }
        if ($items === []) {
            $this->text(54, $this->y + 14, 'No item details were saved for this invoice.', 9, 'muted');
            $this->y += 28;
        }
        $this->summary($invoice);
        $this->finishPage();
        return $this->pdf();
    }

    private function startPage(array $invoice, bool $cover): void
    {
        $this->content = '';
        $this->rect(0, 0, self::W, self::H, [1, 1, 1]);
        if ($cover) {
            $this->rect(50, 42, 495, 78, self::BLUE);
            // No logo: the invoice belongs to the user's company, so the header is
            // its name and details only (from Company Profile via company()).
            $company = \company();
            $name = (string) ($company['name'] ?? APP_NAME);
            $this->text(66, 66, $name, 16, 'white', true);
            $addressLines = preg_split('/\R/', trim((string) ($company['address'] ?? ''))) ?: [];
            $this->text(66, 84, (string) ($addressLines[0] ?? ''), 7, 'white');
            $addressRemainder = implode(', ', array_slice($addressLines, 1));
            if ($addressRemainder !== '') $this->text(66, 95, $addressRemainder, 7, 'white');
            $issuerContact = trim('Tel: ' . (string) ($company['tel'] ?? '') . '  |  Co. Reg. No. ' . (string) ($company['reg_no'] ?? ''), ' |');
            $this->text(66, 108, $issuerContact, 7, 'white');
            $invoiceType = ($invoice['type'] ?? 'purchase') === 'sales' ? 'SALES INVOICE' : 'INVOICE';
            $this->textRight(545, 150, $invoiceType, 19, 'black', true);

            $this->rect(50, 157, 495, 63, [1, 1, 1], [0.12, 0.31, 0.48]);
            $this->line(50, 178, 545, 178, [0.78, 0.81, 0.84]);
            $this->line(50, 199, 545, 199, [0.78, 0.81, 0.84]);
            $this->line(297, 157, 297, 220, [0.78, 0.81, 0.84]);
            $this->text(57, 172, 'Invoice No.', 8, 'black', true);
            $this->text(144, 172, $this->clip((string) $invoice['invoice_number'], 28), 8, 'black');
            $this->text(304, 172, 'Invoice Date', 8, 'black', true);
            $this->text(405, 172, $this->date($invoice['issue_date'] ?? null), 8, 'black');
            $this->text(57, 193, 'Customer PO No.', 8, 'black', true);
            $this->text(144, 193, $this->clip((string) ($invoice['reference_no'] ?: '—'), 25), 8, 'black');
            $this->text(304, 193, 'Due Date', 8, 'black', true);
            $this->text(405, 193, $this->date($invoice['due_date'] ?? null), 8, 'black');
            $this->text(57, 214, 'Payment Terms', 8, 'black', true);
            $this->text(144, 214, $this->clip((string) ($invoice['payment_terms'] ?: '—'), 25), 8, 'black');
            $this->text(304, 214, 'Currency', 8, 'black', true);
            $this->text(405, 214, (string) ($invoice['currency'] ?? 'MYR'), 8, 'black');

            $this->text(57, 244, 'Bill To:', 9, 'black', true);
            $this->text(57, 260, (string) $invoice['party_name'], 9, 'black');
            $address = $this->wrap((string) ($invoice['party_address'] ?? ''), 78);
            foreach (array_slice($address, 0, 3) as $i => $line) $this->text(57, 275 + $i * 12, $line, 8, 'black');
            $contactY = 275 + min(3, count($address)) * 12;
            if (!empty($invoice['party_phone'])) $this->text(57, $contactY, 'Tel: ' . $invoice['party_phone'], 8, 'black');
            $this->y = max(326, $contactY + 18);
        } else {
            $this->text(50, 52, (string) ($invoice['invoice_number'] ?? '') . ' - continued', 10, 'blue', true);
            $this->y = 80;
        }
        $this->tableHeader((string) ($invoice['currency'] ?? 'MYR'));
    }

    private function tableHeader(string $currency): void
    {
        $this->line(50, $this->y, 545, $this->y, self::BLUE, 1.25);
        $top = $this->y + 15;
        $labels = [[56, 'No'], [81, 'SKU'], [128, 'Description'], [280, 'UOM'], [320, 'Qty'], [358, 'Unit Price'], [425, 'Line Disc'], [487, 'Amount (' . $currency . ')']];
        foreach ($labels as [$x, $label]) $this->text((float) $x, $top, $label, 7, 'black', true);
        $this->line(50, $this->y + 20, 545, $this->y + 20, self::BLUE, 1.1);
        $this->y += 20;
    }

    private function itemRow(array $item, array $descriptionLines, float $height, string $currency): void
    {
        $this->rowNo++;
        $top = $this->y + 14;
        $this->text(57, $top, (string) $this->rowNo, 8);
        $this->text(81, $top, $this->clip((string) ($item['sku'] ?? ''), 12) ?: '—', 7);
        foreach ($descriptionLines as $i => $line) $this->text(128, $top + $i * 11, $line, 8);
        $this->text(280, $top, $this->clip((string) ($item['uom'] ?? ''), 8) ?: '—', 7);
        $this->textRight(350, $top, $this->quantity($item['quantity'] ?? null), 8);
        $this->textRight(415, $top, $this->money($item['unit_price'] ?? null, $currency, false), 8);
        $this->textRight(478, $top, $this->money($item['discount'] ?? null, $currency, false), 8);
        $this->textRight(540, $top, $this->money($item['amount'] ?? null, $currency, false), 8);
        $this->y += $height;
        $this->line(50, $this->y, 545, $this->y, [0.82, 0.83, 0.85], 0.45);
    }

    private function summary(array $invoice): void
    {
        if ($this->y + 155 > self::H - 36) {
            $this->finishPage();
            $this->startPage($invoice, false);
        }
        $currency = (string) ($invoice['currency'] ?? 'MYR');
        $subtotal = (float) ($invoice['subtotal'] ?? $invoice['amount'] ?? 0);
        $invoiceDiscount = (float) ($invoice['discount_amount'] ?? 0);
        $taxable = max(0, $subtotal - $invoiceDiscount);
        $sst = (float) ($invoice['sst_amount'] ?? 0);
        $rate = rtrim(rtrim(number_format((float) ($invoice['sst_rate'] ?? 0), 2, '.', ''), '0'), '.');
        $this->y += 27;
        $this->summaryLine('Subtotal (after line discounts)', $this->money($subtotal, $currency), 18);
        $this->summaryLine('Less: Invoice discount', $this->money($invoiceDiscount, $currency, true), 18);
        $this->summaryLine('Taxable Amount', $this->money($taxable, $currency), 18);
        $this->summaryLine('SST ' . ($rate === '' ? '0' : $rate) . '%', $this->money($sst, $currency), 18);
        $this->line(248, $this->y + 2, 540, $this->y + 2, self::BLUE, 0.8);
        $this->summaryLine('GRAND TOTAL (' . $currency . ')', $this->money($invoice['amount'] ?? 0, $currency), 18, true);

        $this->text(57, $this->y + 42, 'Terms: ' . ($invoice['payment_terms'] ?: 'As agreed.'), 7, 'black');
        $status = ['unpaid' => 'Unpaid', 'partial' => 'Partially paid', 'paid' => 'Paid'][$invoice['status'] ?? ''] ?? ucfirst((string) ($invoice['status'] ?? ''));
        $this->text(57, $this->y + 54, 'Status: ' . $status, 7, 'black');
        $this->text(57, $this->y + 66, 'Thank you for your business.', 7, 'black');
        $this->textRight(545, self::H - 23, 'SME Smart  |  ' . $this->date(date('Y-m-d')), 7, 'muted');
    }

    private function summaryLine(string $label, string $amount, float $advance, bool $grand = false): void
    {
        $this->text(255, $this->y + 13, $label, $grand ? 9 : 8, $grand ? 'blue' : 'black', $grand);
        $this->textRight(540, $this->y + 13, $amount, $grand ? 9 : 8, $grand ? 'blue' : 'black', $grand);
        $this->y += $advance;
    }


    private function text(float $x, float $top, string $value, int $size = 9, string $color = 'black', bool $bold = false): void
    {
        $colors = ['black' => [0.10, 0.12, 0.14], 'blue' => self::BLUE, 'muted' => [0.42, 0.45, 0.48], 'white' => [1, 1, 1]];
        [$r, $g, $b] = $colors[$color] ?? $colors['black'];
        $value = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value) ?: '';
        $value = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $value);
        $font = $bold ? 'F2' : 'F1';
        $baseline = self::H - $top;
        $this->content .= sprintf("BT /%s %d Tf %.3f %.3f %.3f rg 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n", $font, $size, $r, $g, $b, $x, $baseline, $value);
    }

    private function textRight(float $right, float $top, string $value, int $size = 9, string $color = 'black', bool $bold = false): void
    {
        $value = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value) ?: '';
        $width = strlen($value) * $size * ($bold ? 0.54 : 0.50);
        $this->text($right - $width, $top, $value, $size, $color, $bold);
    }

    private function rect(float $x, float $top, float $w, float $h, array $fill, ?array $stroke = null): void
    {
        $y = self::H - $top - $h;
        $this->content .= sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n", $fill[0], $fill[1], $fill[2], $x, $y, $w, $h);
        if ($stroke !== null) {
            $this->content .= sprintf("%.3f %.3f %.3f RG 0.65 w %.2f %.2f %.2f %.2f re S\n", $stroke[0], $stroke[1], $stroke[2], $x, $y, $w, $h);
        }
    }

    private function line(float $x1, float $top1, float $x2, float $top2, array $rgb, float $width = 0.6): void
    {
        $this->content .= sprintf("%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S\n", $rgb[0], $rgb[1], $rgb[2], $width, $x1, self::H - $top1, $x2, self::H - $top2);
    }

    private function wrap(string $text, int $chars): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') return ['—'];
        return preg_split('/\R/', wordwrap($text, $chars, "\n", true)) ?: [$text];
    }

    private function clip(string $text, int $chars): string { return mb_strlen($text) > $chars ? mb_substr($text, 0, $chars - 1) . '…' : $text; }
    private function date(mixed $value): string { return $value ? (\fmt_datetime((string) $value, 'd/m/Y')) : '—'; }
    private function quantity(mixed $value): string { return ($value === null || $value === '') ? '—' : rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.'); }
    private function money(mixed $value, string $currency, bool $parens = false): string
    {
        $amount = number_format((float) $value, 2, '.', ',');
        return $parens ? '(' . $amount . ')' : $amount;
    }
    private function finishPage(): void { $this->pages[] = $this->content; }

    private function pdf(): string
    {
        $objects = [];
        $add = static function (string $body) use (&$objects): int { $objects[] = $body; return count($objects); };
        $catalog = $add(''); $pagesObject = $add('');
        $font = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Times-Roman /Encoding /WinAnsiEncoding >>');
        $boldFont = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>');
        $imageResource = '';
        $pageRefs = [];
        foreach ($this->pages as $page) {
            $stream = $add('<< /Length ' . strlen($page) . ">>\nstream\n" . $page . "endstream");
            $pageRefs[] = $add('<< /Type /Page /Parent ' . $pagesObject . ' 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Resources << /Font << /F1 ' . $font . ' 0 R /F2 ' . $boldFont . ' 0 R >>' . $imageResource . ' >> /Contents ' . $stream . ' 0 R >>');
        }
        $kids = implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageRefs));
        $objects[$pagesObject - 1] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageRefs) . ' >>';
        $objects[$catalog - 1] = '<< /Type /Catalog /Pages ' . $pagesObject . ' 0 R >>';
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [0];
        foreach ($objects as $i => $body) { $offsets[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n"; }
        $xref = strlen($pdf); $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
        return $pdf . 'trailer << /Size ' . (count($objects) + 1) . ' /Root ' . $catalog . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    }
}
