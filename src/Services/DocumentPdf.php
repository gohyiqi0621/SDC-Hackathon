<?php
/**
 * DocumentPdf.php - the one A4 layout shared by the Invoice and the Official
 * Receipt, so both documents look the same: formal, black and white only.
 *
 *   ┌───────────────────────────────────────────────────────────┐
 *   │ COMPANY NAME                                   [logo]     │
 *   │ address · Reg. No. · Tel · Email                          │
 *   │━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━│
 *   │                                     INVOICE / RECEIPT     │
 *   │ BILL TO / RECEIVED FROM             No.     ......        │
 *   │ Customer name                       Date    ......        │
 *   │ ┌────┬──────────────────┬──────┬──────┐  (black header)   │
 *   │ └────┴──────────────────┴──────┴──────┘                   │
 *   │                              Subtotal       0.00          │
 *   │                              TOTAL (MYR)    0.00 (boxed)  │
 *   │ Amount in words / payment details / notes                 │
 *   │ ───────────────────────────────────────────────────────── │
 *   │ computer-generated note                     Page 1 of 1   │
 *   └───────────────────────────────────────────────────────────┘
 *
 * The company logo is printed in grey tones, so the page stays black and white.
 *
 * Uses tFPDF (src/Lib/tfpdf, LGPL) with DejaVu Sans, so Malay names and other
 * Latin scripts print correctly.
 */
declare(strict_types=1);

namespace Src\Services;

final class DocumentPdf
{
    public const L = 18.0;    // left margin (mm)
    public const R = 192.0;   // right edge
    public const W = 174.0;   // printable width

    private const BLACK = [0, 0, 0];
    private const TEXT  = [34, 34, 34];
    private const GREY  = [96, 96, 96];
    private const RULE  = [185, 185, 185];
    private const SHADE = [240, 240, 240];

    public \tFPDF $pdf;
    private array $company;
    /** Table columns of the current table, so it can repeat its header on a new page. */
    private array $cols = [];

    /**
     * @param array  $company DocumentPdf::company() shape
     * @param string $footer  first footer line (e.g. "This is a computer-generated invoice ...")
     */
    public function __construct(string $title, array $company, string $footer)
    {
        self::loadLibrary();
        $this->company = $company;

        $this->pdf = new class('P', 'mm', 'A4') extends \tFPDF {
            public string $footerText = '';
            public string $footerRef  = '';
            public function Footer(): void
            {
                $this->SetY(-16);
                $this->SetDrawColor(185, 185, 185);
                $this->SetLineWidth(0.2);
                $this->Line(18, $this->GetY(), 192, $this->GetY());
                $this->Ln(1.8);
                $this->SetFont('DejaVu', '', 7.2);
                $this->SetTextColor(96, 96, 96);
                $this->Cell(130, 3.8, $this->footerText, 0, 0, 'L');
                $this->Cell(44, 3.8, 'Page ' . $this->PageNo() . ' of #', 0, 1, 'R');
                if ($this->footerRef !== '') {
                    $this->Cell(174, 3.8, $this->footerRef, 0, 0, 'L');
                }
            }
        };
        $this->pdf->footerText = $footer;
        $this->pdf->AliasNbPages('#');
        $this->pdf->SetMargins(self::L, 18, 210 - self::R);
        $this->pdf->SetAutoPageBreak(true, 24);
        $this->pdf->AddFont('DejaVu', '', 'DejaVuSans.ttf', true);
        $this->pdf->AddFont('DejaVu', 'B', 'DejaVuSans-Bold.ttf', true);
        $this->pdf->SetTitle($title, true);
        $this->pdf->SetAuthor((string) ($company['name'] ?? APP_NAME), true);
        $this->pdf->SetCreator(APP_NAME, true);
        $this->pdf->AddPage();
    }

    /** A second footer line, e.g. "OR-261024-25XV · Generated 24-10-2026, 4:41 pm". */
    public function footerRef(string $text): void
    {
        $this->pdf->footerRef = $text;
    }

    /* ------------------------------------------------------------ data --- */

    /**
     * One company shape from a company_profiles row:
     * name, address, reg_no, tel, email, logo (public path), bank, account_no, account_name.
     */
    public static function company(array $row): array
    {
        return [
            'name'         => trim((string) ($row['company_name'] ?? '')) ?: APP_NAME,
            'address'      => trim((string) ($row['company_address'] ?? '')),
            'reg_no'       => trim((string) ($row['registration_no'] ?? '')),
            'tel'          => trim((string) ($row['company_phone'] ?? '')),
            'email'        => trim((string) ($row['company_email'] ?? '')),
            'logo'         => trim((string) ($row['company_logo_url'] ?? '')),
            'bank'         => trim((string) ($row['payment_bank'] ?? '')),
            'account_no'   => trim((string) ($row['payment_account_no'] ?? '')),
            'account_name' => trim((string) ($row['payment_account_name'] ?? '')),
        ];
    }

    /* ---------------------------------------------------------- blocks --- */

    /** Company details (left), logo (right), a heavy rule, then the document title. */
    public function header(string $title): void
    {
        $pdf = $this->pdf;
        $c   = $this->company;

        $logo = $this->logoFile((string) $c['logo']);
        $logoBottom = 18.0;
        $textW = $logo !== null ? 112.0 : self::W;
        if ($logo !== null) {
            [$w, $h] = $this->fit($logo, 50, 22);
            $pdf->Image($logo, self::R - $w, 18, $w, $h);
            $logoBottom = 18 + $h;
            @unlink($logo);
        }

        $pdf->SetXY(self::L, 18);
        $this->font('B', 14, self::BLACK);
        $pdf->MultiCell($textW, 6.4, mb_strtoupper((string) $c['name']), 0, 'L');
        $pdf->Ln(0.8);
        $this->font('', 8.6, self::TEXT);
        if ($c['address'] !== '') {
            $pdf->SetX(self::L);
            $pdf->MultiCell($textW, 4.3, (string) $c['address'], 0, 'L');
        }
        foreach (array_filter([
            $c['reg_no'] !== '' ? 'Registration No.: ' . $c['reg_no'] : '',
            implode('    ', array_filter([
                $c['tel'] !== '' ? 'Tel: ' . $c['tel'] : '',
                $c['email'] !== '' ? 'Email: ' . $c['email'] : '',
            ])),
        ]) as $line) {
            $pdf->SetX(self::L);
            $pdf->MultiCell($textW, 4.3, $line, 0, 'L');
        }

        $y = max($pdf->GetY(), $logoBottom) + 4;
        $pdf->SetDrawColor(...self::BLACK);
        $pdf->SetLineWidth(0.7);
        $pdf->Line(self::L, $y, self::R, $y);
        $pdf->SetLineWidth(0.2);
        $pdf->Line(self::L, $y + 1.1, self::R, $y + 1.1);

        $pdf->SetXY(self::L, $y + 6);
        $this->font('B', 19, self::BLACK);
        $pdf->Cell(self::W, 9, $this->spaced($title), 0, 1, 'R');
        $pdf->Ln(3);
    }

    /**
     * The party (left: label, name, detail lines) and the document facts
     * (right: label => value rows, in a thin box).
     *
     * @param list<string>               $lines
     * @param list<array{0:string,1:string}> $facts
     */
    public function parties(string $label, string $name, array $lines, array $facts): void
    {
        $pdf = $this->pdf;
        $top = $pdf->GetY();

        // Left: who the document is for.
        $pdf->SetXY(self::L, $top);
        $this->font('B', 7.8, self::GREY);
        $pdf->Cell(90, 4.5, mb_strtoupper($label), 0, 1, 'L');
        $pdf->SetX(self::L);
        $this->font('B', 11, self::BLACK);
        $pdf->MultiCell(90, 5.8, $name, 0, 'L');
        $this->font('', 8.8, self::TEXT);
        foreach (array_filter($lines, static fn ($l) => trim((string) $l) !== '') as $line) {
            $pdf->SetX(self::L);
            $pdf->MultiCell(90, 4.6, (string) $line, 0, 'L');
        }
        $leftEnd = $pdf->GetY();

        // Right: number, dates, terms ... in a thin-ruled box.
        $x = self::R - 78;
        $rowH = 6.2;
        $pdf->SetDrawColor(...self::BLACK);
        $pdf->SetLineWidth(0.25);
        $pdf->Rect($x, $top, 78, $rowH * count($facts));
        $pdf->SetDrawColor(...self::RULE);
        foreach (array_values($facts) as $i => [$k, $v]) {
            $rowY = $top + $i * $rowH;
            if ($i > 0) {
                $pdf->Line($x, $rowY, self::R, $rowY);
            }
            $pdf->SetXY($x + 2.5, $rowY);
            $this->font('', 8.4, self::GREY);
            $pdf->Cell(30, $rowH, $k, 0, 0, 'L');
            $this->font('B', 8.6, self::BLACK);
            $pdf->Cell(43, $rowH, $v, 0, 0, 'R');
        }
        $pdf->SetY(max($leftEnd, $top + $rowH * count($facts)) + 8);
    }

    /**
     * Item table with a black header row. A column marked 'wrap' => true grows
     * the row for long text; the header repeats on every new page.
     *
     * @param list<array{0:string,1:float,2:string,3?:bool}> $cols  label, width, align (L/C/R), wrap
     * @param list<list<string>>                            $rows
     */
    public function table(array $cols, array $rows, string $empty = 'No items.'): void
    {
        $pdf = $this->pdf;
        $this->cols = $cols;
        $this->tableHead();

        $this->font('', 8.6, self::TEXT);
        if ($rows === []) {
            $pdf->SetX(self::L);
            $pdf->Cell(self::W, 8, '  ' . $empty, 'B', 1, 'L');
        }
        foreach ($rows as $cells) {
            $lines = 1;
            foreach ($cols as $i => $col) {
                if (!empty($col[3])) {
                    $lines = max($lines, $this->lineCount((string) ($cells[$i] ?? ''), $col[1] - 5));
                }
            }
            $rowH = max(7.6, 4.5 * $lines + 3.2);
            if ($pdf->GetY() + $rowH > 297 - 30) {
                $pdf->AddPage();
                $this->tableHead();
                $this->font('', 8.6, self::TEXT);
            }
            $rowY = $pdf->GetY();
            $x = self::L;
            foreach ($cols as $i => [$label, $w, $align]) {
                $text = (string) ($cells[$i] ?? '');
                if (!empty($cols[$i][3])) {
                    $pdf->SetXY($x + 2.5, $rowY + 1.6);
                    $pdf->MultiCell($w - 5, 4.5, $text, 0, 'L');
                } else {
                    $pdf->SetXY($x, $rowY);
                    $pdf->Cell($w, $rowH, $this->pad($text, $align), 0, 0, $align);
                }
                $x += $w;
            }
            $pdf->SetDrawColor(...self::RULE);
            $pdf->SetLineWidth(0.2);
            $pdf->Line(self::L, $rowY + $rowH, self::R, $rowY + $rowH);
            $pdf->SetXY(self::L, $rowY + $rowH);
        }
        $pdf->SetDrawColor(...self::BLACK);
        $pdf->SetLineWidth(0.45);
        $pdf->Line(self::L, $pdf->GetY(), self::R, $pdf->GetY());
        $pdf->Ln(2.5);
    }

    /**
     * Totals on the right. The last row is the grand total: shaded, ruled
     * above and double-ruled below, as on a formal statement.
     *
     * @param list<array{0:string,1:string}> $rows
     */
    public function totals(array $rows, string $grandLabel, string $grandValue): void
    {
        $pdf = $this->pdf;
        $x   = self::R - 84;
        if ($pdf->GetY() + 7 * count($rows) + 16 > 297 - 30) {
            $pdf->AddPage();
        }
        foreach ($rows as [$k, $v]) {
            $pdf->SetX($x);
            $this->font('', 8.8, self::TEXT);
            $pdf->Cell(50, 6.6, '  ' . $k, 0, 0, 'L');
            $pdf->Cell(34, 6.6, $v . '  ', 0, 1, 'R');
        }
        $y = $pdf->GetY() + 1;
        $pdf->SetFillColor(...self::SHADE);
        $pdf->SetXY($x, $y);
        $this->font('B', 10, self::BLACK);
        $pdf->Cell(50, 9, '  ' . $grandLabel, 0, 0, 'L', true);
        $pdf->Cell(34, 9, $grandValue . '  ', 0, 1, 'R', true);
        $pdf->SetDrawColor(...self::BLACK);
        $pdf->SetLineWidth(0.45);
        $pdf->Line($x, $y, self::R, $y);
        $pdf->Line($x, $y + 9, self::R, $y + 9);
        $pdf->SetLineWidth(0.2);
        $pdf->Line($x, $y + 10, self::R, $y + 10);
        $pdf->SetY($y + 15);
    }

    /** A labelled line across the page, e.g. "Amount in words: Ringgit Malaysia ...". */
    public function labelled(string $label, string $text): void
    {
        $pdf = $this->pdf;
        $pdf->SetX(self::L);
        $this->font('B', 8.6, self::BLACK);
        $w = $pdf->GetStringWidth($label) + 2.5;
        $pdf->Cell($w, 4.8, $label, 0, 0, 'L');
        $this->font('', 8.6, self::TEXT);
        $pdf->MultiCell(self::W - $w, 4.8, $text, 0, 'L');
        $pdf->Ln(1.2);
    }

    /** A small heading with ruled paragraphs under it (Payment details, Notes, Terms). */
    public function section(string $heading, array $paragraphs): void
    {
        $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), static fn ($p) => $p !== ''));
        if ($paragraphs === []) {
            return;
        }
        $pdf = $this->pdf;
        if ($pdf->GetY() + 8 + 5 * count($paragraphs) > 297 - 28) {
            $pdf->AddPage();
        }
        $pdf->Ln(2);
        $pdf->SetX(self::L);
        $this->font('B', 8.2, self::BLACK);
        $pdf->Cell(self::W, 5, mb_strtoupper($heading), 0, 1, 'L');
        $pdf->SetDrawColor(...self::BLACK);
        $pdf->SetLineWidth(0.25);
        $pdf->Line(self::L, $pdf->GetY(), self::L + 40, $pdf->GetY());
        $pdf->Ln(1.6);
        $this->font('', 8.6, self::TEXT);
        foreach ($paragraphs as $p) {
            $pdf->SetX(self::L);
            $pdf->MultiCell(self::W, 4.7, $p, 0, 'L');
            $pdf->Ln(0.8);
        }
    }

    public function output(): string
    {
        return $this->pdf->Output('S');
    }

    /* --------------------------------------------------------- helpers --- */

    public static function loadLibrary(): void
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

    private function tableHead(): void
    {
        $pdf = $this->pdf;
        $pdf->SetX(self::L);
        $pdf->SetFillColor(...self::BLACK);
        $this->font('B', 8, [255, 255, 255]);
        foreach ($this->cols as [$label, $w, $align]) {
            $pdf->Cell($w, 8, $this->pad(mb_strtoupper($label), $align), 0, 0, $align, true);
        }
        $pdf->Ln();
    }

    private function font(string $style, float $size, array $rgb): void
    {
        $this->pdf->SetFont('DejaVu', $style, $size);
        $this->pdf->SetTextColor(...$rgb);
    }

    private function pad(string $text, string $align): string
    {
        return $align === 'L' ? '  ' . $text : ($align === 'R' ? $text . '  ' : $text);
    }

    /** "INVOICE" -> "I N V O I C E" style letter spacing for the title. */
    private function spaced(string $title): string
    {
        return implode(' ', preg_split('//u', mb_strtoupper($title), -1, PREG_SPLIT_NO_EMPTY) ?: [$title]);
    }

    /** How many lines $text needs in a cell $width mm wide (current font). */
    private function lineCount(string $text, float $width): int
    {
        $lines = 0;
        foreach (explode("\n", $text) as $para) {
            $line = '';
            $lines++;
            foreach (preg_split('/\s+/u', $para) ?: [] as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($this->pdf->GetStringWidth($try) > $width && $line !== '') {
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
    private function logoFile(string $rel): ?string
    {
        if ($rel === '' || str_contains($rel, '..') || preg_match('#^https?://#i', $rel)) {
            return null;
        }
        $path = PUBLIC_ROOT . '/' . ltrim($rel, '/');
        \Src\Support\FileBackup::restore('public', $rel);   // missing after a redeploy
        if (!is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            return null;
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $tmp  = tempnam(sys_get_temp_dir(), 'pdflogo');
        if ($tmp === false) {
            return null;
        }
        if (function_exists('imagecreatefromstring')) {
            $img = @imagecreatefromstring((string) file_get_contents($path));
            if ($img !== false) {
                // Printed in grey tones too, so the whole page is black and white.
                imagealphablending($img, false);
                imagesavealpha($img, true);
                imagefilter($img, IMG_FILTER_GRAYSCALE);
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
}
