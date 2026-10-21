<?php
/**
 * SpreadsheetReader.php - read the first sheet of a .csv or .xlsx file into rows of text.
 *
 * No Composer packages needed. XLSX files are ZIP archives of XML: when the
 * PHP zip extension is missing (it is off in some XAMPP installs) a small
 * built-in ZIP reader is used instead, which only needs zlib.
 *
 *   $rows = SpreadsheetReader::read($tmpPath, 'xlsx');   // [[ 'Name', 'Price' ], [ 'Tea', '5.5' ], ...]
 */
declare(strict_types=1);

namespace Src\Support;

use RuntimeException;
use SimpleXMLElement;

final class SpreadsheetReader
{
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * @return array<int, array<int, string>> rows keyed by their line number in the file (1 = first line),
     *                                         blank rows removed; each row is a list of cell text
     * @throws RuntimeException with a message that can be shown to the user
     */
    public static function read(string $path, string $extension, int $maxRows = 5000): array
    {
        $rows = match (strtolower($extension)) {
            'csv', 'txt' => self::readCsv($path, $maxRows),
            'xlsx'       => self::readXlsx($path, $maxRows),
            default      => throw new RuntimeException('Upload a .csv or .xlsx file.'),
        };

        return array_filter($rows, static function (array $row): bool {
            foreach ($row as $cell) {
                if (trim($cell) !== '') {
                    return true;
                }
            }
            return false;
        });
    }

    /* ---------------------------------------------------------------- CSV --- */

    private static function readCsv(string $path, int $maxRows): array
    {
        $raw = (string) file_get_contents($path);

        // Excel "CSV UTF-8" adds a BOM; "Unicode text" is UTF-16; plain "CSV" is Windows-1252.
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        } elseif (str_starts_with($raw, "\xFF\xFE")) {
            $raw = (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($raw, "\xFE\xFF")) {
            $raw = (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        if (trim($raw) === '') {
            throw new RuntimeException('The file is empty.');
        }

        $delimiter = self::guessDelimiter($raw);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);

        $rows = [];
        $line = 0;
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($row === [null]) {
                continue; // blank line
            }
            $rows[$line] = array_map(static fn ($v) => (string) $v, $row);

            if (count($rows) > $maxRows) {
                break;
            }
        }
        fclose($handle);

        return $rows;
    }

    /** Pick , ; tab or | - whichever appears most in the first line (outside quotes). */
    private static function guessDelimiter(string $raw): string
    {
        $firstLine = strtok($raw, "\r\n") ?: '';
        $firstLine = (string) preg_replace('/"[^"]*"/', '', $firstLine);

        $best  = ',';
        $count = 0;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $n = substr_count($firstLine, $candidate);
            if ($n > $count) {
                $best  = $candidate;
                $count = $n;
            }
        }

        return $best;
    }

    /* --------------------------------------------------------------- XLSX --- */

    private static function readXlsx(string $path, int $maxRows): array
    {
        $files = self::unzip($path, [
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/sharedStrings.xml',
        ]);

        if (!isset($files['xl/workbook.xml'])) {
            throw new RuntimeException('This does not look like an Excel (.xlsx) file. Save it as .xlsx or .csv and try again.');
        }

        $sheetPath = self::firstSheetPath($files['xl/workbook.xml'], $files['xl/_rels/workbook.xml.rels'] ?? null);
        $sheetXml  = self::unzip($path, [$sheetPath])[$sheetPath] ?? null;

        if ($sheetXml === null) {
            throw new RuntimeException('The first sheet of the workbook could not be read.');
        }

        $shared = isset($files['xl/sharedStrings.xml']) ? self::sharedStrings($files['xl/sharedStrings.xml']) : [];

        return self::sheetRows($sheetXml, $shared, $maxRows);
    }

    private static function xml(string $content): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new RuntimeException('The Excel file is damaged and could not be read.');
        }

        return $xml;
    }

    private static function firstSheetPath(string $workbookXml, ?string $relsXml): string
    {
        $default  = 'xl/worksheets/sheet1.xml';
        $workbook = self::xml($workbookXml);

        if (!isset($workbook->sheets->sheet[0]) || $relsXml === null) {
            return $default;
        }

        $relId = (string) ($workbook->sheets->sheet[0]->attributes(self::NS_REL)['id'] ?? '');

        foreach (self::xml($relsXml)->Relationship as $rel) {
            if ((string) $rel['Id'] !== $relId) {
                continue;
            }

            $target = (string) $rel['Target'];

            return str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        }

        return $default;
    }

    /** @return array<int, string> */
    private static function sharedStrings(string $content): array
    {
        $strings = [];

        foreach (self::xml($content)->si as $si) {
            $strings[] = self::richText($si);
        }

        return $strings;
    }

    /** Text of an <si> or <is> node: a plain <t>, or rich-text runs <r><t>. */
    private static function richText(SimpleXMLElement $node): string
    {
        if (isset($node->t)) {
            return (string) $node->t;
        }

        $text = '';
        foreach ($node->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /** @param array<int, string> $shared */
    private static function sheetRows(string $content, array $shared, int $maxRows): array
    {
        $sheet = self::xml($content);
        $rows  = [];

        if (!isset($sheet->sheetData)) {
            return [];
        }

        $line = 0;
        foreach ($sheet->sheetData->row as $row) {
            $line  = (int) $row['r'] > 0 ? (int) $row['r'] : $line + 1;
            $cells = [];

            foreach ($row->c as $c) {
                $ref   = (string) $c['r'];
                $index = $ref !== '' ? self::columnIndex($ref) : count($cells);
                $type  = (string) $c['t'];
                $value = (string) ($c->v ?? '');

                $cells[$index] = match ($type) {
                    's'         => $shared[(int) $value] ?? '',
                    'inlineStr' => isset($c->is) ? self::richText($c->is) : '',
                    'b'         => $value === '1' ? 'TRUE' : 'FALSE',
                    'str', 'e'  => $value,
                    default     => self::number($value),
                };
            }

            if ($cells === []) {
                continue;
            }

            $width = max(array_keys($cells)) + 1;
            $values = array_fill(0, $width, '');
            foreach ($cells as $i => $v) {
                $values[$i] = $v;
            }
            $rows[$line] = $values;

            if (count($rows) > $maxRows) {
                break;
            }
        }

        return $rows;
    }

    /** "AB12" -> 27 (zero based). */
    private static function columnIndex(string $ref): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $ref));
        $index   = 0;

        for ($i = 0, $n = strlen($letters); $i < $n; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return max(0, $index - 1);
    }

    /** Excel stores 25.9 as "25.899999999999999" - tidy it back to "25.9". */
    private static function number(string $value): string
    {
        if ($value === '' || !is_numeric($value)) {
            return $value;
        }

        $float = (float) $value;
        if (abs($float - round($float)) < 1e-9 && abs($float) < 1e15) {
            return number_format($float, 0, '.', '');
        }

        return rtrim(rtrim(sprintf('%.6F', $float), '0'), '.');
    }

    /* ---------------------------------------------------------------- ZIP --- */

    /**
     * Read some entries of a ZIP archive.
     *
     * @param array<int, string> $names
     * @return array<string, string> name => content, for the names that exist
     */
    private static function unzip(string $path, array $names): array
    {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw new RuntimeException('This does not look like an Excel (.xlsx) file. Save it as .xlsx or .csv and try again.');
            }

            $out = [];
            foreach ($names as $name) {
                $content = $zip->getFromName($name);
                if ($content !== false) {
                    $out[$name] = $content;
                }
            }
            $zip->close();

            return $out;
        }

        return self::unzipFallback($path, $names);
    }

    /** Minimal ZIP reader (stored + deflate entries) for servers without ext-zip. */
    private static function unzipFallback(string $path, array $names): array
    {
        $data = (string) file_get_contents($path);
        $eocd = strrpos($data, "PK\x05\x06");

        if ($eocd === false || strlen($data) < $eocd + 22) {
            throw new RuntimeException('This does not look like an Excel (.xlsx) file. Save it as .xlsx or .csv and try again.');
        }

        $end     = unpack('ventries/Vsize/Voffset', substr($data, $eocd + 10, 10));
        $pos     = (int) $end['offset'];
        $wanted  = array_flip($names);
        $out     = [];

        for ($i = 0; $i < (int) $end['entries']; $i++) {
            if (substr($data, $pos, 4) !== "PK\x01\x02") {
                break;
            }

            $h = unpack(
                'vmethod/Vtime/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/viattr/Veattr/Vlocal',
                substr($data, $pos + 10, 36)
            );
            $name = substr($data, $pos + 46, (int) $h['nlen']);
            $pos += 46 + (int) $h['nlen'] + (int) $h['xlen'] + (int) $h['clen'];

            if (!isset($wanted[$name])) {
                continue;
            }

            $local = (int) $h['local'];
            $l     = unpack('vnlen/vxlen', substr($data, $local + 26, 4));
            $raw   = substr($data, $local + 30 + (int) $l['nlen'] + (int) $l['xlen'], (int) $h['csize']);

            $content = match ((int) $h['method']) {
                0       => $raw,
                8       => @gzinflate($raw),
                default => false,
            };

            if ($content === false) {
                throw new RuntimeException('The Excel file uses a compression this server cannot read. Save it as .csv and try again.');
            }

            $out[$name] = $content;
        }

        return $out;
    }
}
