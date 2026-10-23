<?php
/**
 * PdfImage.php - read a PNG or JPEG into the pieces a PDF needs, without GD.
 *
 * XAMPP often ships without the GD extension, so images cannot be converted.
 * tFPDF (src/Lib/tfpdf) already knows how to read PNG (incl. transparency)
 * and JPEG with only zlib; this exposes its parser so the hand-written
 * invoice PDF (InvoicePdfService) can embed the user's company logo too.
 *
 *   $img = PdfImage::load($path);   // null when missing / not PNG or JPEG / unreadable
 *   // ['w','h','cs','bpc','f','dp'?,'pal'?,'trns'?,'smask'?,'data']
 */
declare(strict_types=1);

namespace Src\Services;

if (!class_exists('tFPDF', false)) {
    $dir = SRC_ROOT . '/Lib/tfpdf/';
    if (!defined('FPDF_FONTPATH')) {
        define('FPDF_FONTPATH', $dir . 'font/');
    }
    require_once $dir . 'font/unifont/ttfonts.php';
    require_once $dir . 'tfpdf.php';
}

final class PdfImage extends \tFPDF
{
    /** @return array<string, mixed>|null */
    public static function load(?string $path): ?array
    {
        if ($path === null || $path === '' || !is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            return null;
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        try {
            $parser = new self();
            if ($mime === 'image/png') {
                return $parser->_parsepng($path);
            }
            if ($mime === 'image/jpeg') {
                return $parser->_parsejpg($path);
            }
        } catch (\Throwable $e) {
            error_log('[pdf-image] ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Width and height (points) of the image fitted inside a box, keeping its shape.
     *
     * @return array{0: float, 1: float}
     */
    public static function fit(array $img, float $maxW, float $maxH): array
    {
        $ratio = min($maxW / max(1, (int) $img['w']), $maxH / max(1, (int) $img['h']));

        return [round($img['w'] * $ratio, 2), round($img['h'] * $ratio, 2)];
    }
}
