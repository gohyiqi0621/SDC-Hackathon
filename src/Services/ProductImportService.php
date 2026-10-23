<?php
/**
 * ProductImportService.php - bulk import products from a .csv or .xlsx file.
 *
 * Two steps:
 *   1. analyse()  reads the file, finds which column holds which field by its
 *                 header (name, SKU, category, description, characteristics,
 *                 selling price, promo price, currency, status), checks every
 *                 row and lists categories the user does not have yet.
 *   2. commit()   creates the new categories (only when the user agreed) and
 *                 inserts the valid rows in one transaction.
 *
 * Every row goes through ProductService::validateProduct(), the same rules as
 * the "Add product / service" form.
 */
declare(strict_types=1);

namespace Src\Services;

use RuntimeException;
use Src\Models\Category;
use Src\Models\Product;
use Src\Support\Database;
use Src\Support\SpreadsheetReader;

final class ProductImportService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS  = 1000;
    public const EXTENSIONS = ['csv', 'xlsx'];

    /**
     * Fields that can be detected, with the header names that mean them.
     * Order matters for loose matching: the more specific fields come first
     * ("Promo price" must not be taken as "Price", "Product code" not as "Product").
     */
    public const FIELDS = [
        'promotion_price' => ['label' => 'Promotional price', 'required' => false, 'aliases' => [
            'promotion price', 'promotional price', 'promo price', 'promo', 'sale price', 'discount price',
            'discounted price', 'special price', 'offer price',
        ]],
        'sku' => ['label' => 'SKU / product code', 'required' => false, 'aliases' => [
            'sku', 'sku product code', 'product code', 'item code', 'code', 'product id', 'item id', 'barcode',
            'part number', 'model number', 'ref', 'reference',
        ]],
        'category' => ['label' => 'Category', 'required' => true, 'aliases' => [
            'category', 'categories', 'category name', 'product category', 'type', 'product type', 'group', 'collection',
        ]],
        'characteristics' => ['label' => 'Characteristics', 'required' => false, 'aliases' => [
            'characteristics', 'characteristic', 'features', 'feature', 'specs', 'specifications', 'specification',
            'attributes', 'details', 'variants', 'options',
        ]],
        'description' => ['label' => 'Description', 'required' => false, 'aliases' => [
            'description', 'desc', 'product description', 'summary', 'about', 'notes',
        ]],
        'currency' => ['label' => 'Currency', 'required' => false, 'aliases' => [
            'currency', 'curr', 'ccy', 'currency code',
        ]],
        'status' => ['label' => 'Status', 'required' => false, 'aliases' => [
            'status', 'pricing status', 'active', 'availability', 'available', 'state', 'enabled',
        ]],
        'price' => ['label' => 'Selling price', 'required' => true, 'aliases' => [
            'selling price', 'price', 'unit price', 'retail price', 'sell price', 'sale amount', 'amount', 'rate',
            'cost', 'price myr', 'pricing',
        ]],
        'name' => ['label' => 'Product / service name', 'required' => true, 'aliases' => [
            'product service name', 'product name', 'service name', 'name', 'product', 'service', 'item',
            'item name', 'title', 'product title',
        ]],
    ];

    /** Order shown to the user (same as the add form). */
    public const DISPLAY_ORDER = [
        'name', 'sku', 'category', 'description', 'characteristics', 'status', 'currency', 'price',
    ];

    private const CURRENCY_ALIASES = [
        'RM' => 'MYR', 'RINGGIT' => 'MYR', 'S$' => 'SGD', 'SG$' => 'SGD', 'US$' => 'USD', '$' => 'USD',
        '€' => 'EUR', 'EURO' => 'EUR', '£' => 'GBP', 'RP' => 'IDR', 'RUPIAH' => 'IDR', '฿' => 'THB',
        'BAHT' => 'THB', 'RMB' => 'CNY', '¥' => 'CNY', 'YUAN' => 'CNY',
    ];

    /* ----------------------------------------------------------- upload --- */

    public function fileError(?array $file): ?string
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'Choose a .csv or .xlsx file to import.';
        }

        $error = (int) ($file['error'] ?? 0);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return 'The file must be 5 MB or smaller.';
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return 'The file could not be uploaded. Please try again.';
        }
        if (!in_array($this->extension($file), self::EXTENSIONS, true)) {
            return 'Upload a .csv or .xlsx file. (Older .xls files: open in Excel and "Save as" .xlsx.)';
        }

        return null;
    }

    public function extension(array $file): string
    {
        return strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    }

    /* ---------------------------------------------------------- analyse --- */

    /**
     * Read and check a file.
     *
     * @return array{
     *   columns: array<string, ?string>, missing: array<int, string>,
     *   rows: array<int, array{line:int, category:string, input:array<string,string>}>,
     *   skipped: array<int, array{line:int, name:string, reason:string}>,
     *   new_categories: array<int, string>, total: int
     * }
     * @throws RuntimeException with a message for the user
     */
    public function analyse(int $userId, string $path, string $extension): array
    {
        $sheet = SpreadsheetReader::read($path, $extension, self::MAX_ROWS + 20);

        if ($sheet === []) {
            throw new RuntimeException('The file has no rows.');
        }

        [$headerIndex, $map] = $this->detectHeader($sheet);

        $columns = [];
        foreach (self::DISPLAY_ORDER as $field) {
            $columns[$field] = isset($map[$field]) ? trim($sheet[$headerIndex][$map[$field]]) : null;
        }

        $missing = [];
        foreach (self::FIELDS as $field => $def) {
            if ($def['required'] && !isset($map[$field])) {
                $missing[] = $def['label'];
            }
        }

        $position = (int) array_search($headerIndex, array_keys($sheet), true);
        $dataRows = array_slice($sheet, $position + 1, null, true);

        if (count($dataRows) > self::MAX_ROWS) {
            throw new RuntimeException('Import up to ' . number_format(self::MAX_ROWS) . ' rows at a time. Split the file and try again.');
        }

        $result = [
            'columns'        => $columns,
            'missing'        => $missing,
            'rows'           => [],
            'skipped'        => [],
            'new_categories' => [],
            'total'          => count($dataRows),
        ];

        if ($missing !== [] || $dataRows === []) {
            return $result;
        }

        $existing = [];
        foreach ((new Category())->allForUser($userId) as $category) {
            $existing[mb_strtolower($category['name'])] = (int) $category['id'];
        }

        $service  = new ProductService();
        $newCats  = [];
        $seenSkus = [];

        foreach ($dataRows as $index => $row) {
            $line  = (int) $index; // line number in the file, as Excel shows it
            $input = $this->rowInput($row, $map);
            $cat   = $this->cleanName($input['category'] ?? '');
            $key   = mb_strtolower($cat);

            $input['category_id'] = (string) ($existing[$key] ?? 0);
            [, $errors] = $service->validateProduct($userId, $input);

            if ($cat === '') {
                $errors['category_id'] = 'Category is empty.';
            } elseif (mb_strlen($cat) > 100) {
                $errors['category_id'] = 'Category name is longer than 100 characters.';
            } elseif (!isset($existing[$key])) {
                unset($errors['category_id']); // a new category - asked about before importing
            }

            $sku = mb_strtolower(trim($input['sku'] ?? ''));
            if ($sku !== '' && !isset($errors['sku'])) {
                if (isset($seenSkus[$sku])) {
                    $errors['sku'] = 'Same SKU as line ' . $seenSkus[$sku] . ' in this file.';
                } else {
                    $seenSkus[$sku] = $line;
                }
            }

            if ($errors !== []) {
                $result['skipped'][] = [
                    'line'   => $line,
                    'name'   => trim($input['name'] ?? '') ?: '(no name)',
                    'reason' => implode(' ', array_values($errors)),
                ];
                continue;
            }

            if (!isset($existing[$key]) && !isset($newCats[$key])) {
                $newCats[$key] = $cat;
            }

            unset($input['category_id']);
            $result['rows'][] = ['line' => $line, 'category' => $cat, 'input' => $input];
        }

        $result['new_categories'] = array_values($newCats);

        return $result;
    }

    /* ----------------------------------------------------------- commit --- */

    /**
     * Insert the checked rows. New categories are created only when $createCategories is true;
     * otherwise nothing is imported.
     *
     * @param array{rows: array, new_categories: array} $analysis from analyse()
     * @return array{imported:int, categories:int, skipped: array<int, array{line:int, name:string, reason:string}>}
     */
    public function commit(int $userId, array $analysis, bool $createCategories): array
    {
        if ($analysis['new_categories'] !== [] && !$createCategories) {
            throw new RuntimeException('Import cancelled. No products were added.');
        }

        return Database::transaction(function () use ($userId, $analysis): array {
            $categories = new Category();
            $products   = new Product();
            $service    = new ProductService();

            $ids = [];
            foreach ($categories->allForUser($userId) as $category) {
                $ids[mb_strtolower($category['name'])] = (int) $category['id'];
            }

            $created = 0;
            foreach ($analysis['new_categories'] as $name) {
                $key = mb_strtolower($name);
                if (!isset($ids[$key])) {
                    $ids[$key] = $categories->create($userId, $name, null);
                    $created++;
                }
            }

            $imported = 0;
            $skipped  = [];

            foreach ($analysis['rows'] as $row) {
                $input = $row['input'];
                $input['category_id'] = (string) ($ids[mb_strtolower($row['category'])] ?? 0);

                // Checked again: something may have changed since the preview (e.g. a SKU added meanwhile).
                [$data, $errors] = $service->validateProduct($userId, $input);
                if ($errors !== []) {
                    $skipped[] = ['line' => $row['line'], 'name' => $data['name'] ?: '(no name)', 'reason' => implode(' ', $errors)];
                    continue;
                }

                $data['image_url'] = null;
                $products->create($userId, $data);
                $imported++;
            }

            return ['imported' => $imported, 'categories' => $created, 'skipped' => $skipped];
        });
    }

    /* ---------------------------------------------------------- template --- */

    /** @return array<int, array<int, string>> header + example rows for the downloadable template */
    public function templateRows(): array
    {
        return [
            ['Product / service name', 'SKU / product code', 'Category', 'Description', 'Characteristics',
             'Status', 'Currency', 'Selling price'],
            ['Iced Latte', 'DRK-001', 'Drinks', 'Double shot espresso with cold milk.', 'Size: 16oz; Sugar: adjustable',
             'Active', 'MYR', '12.90'],
            ['Basic Haircut', 'SRV-010', 'Services', '30-minute cut and style.', 'Duration: 30 min',
             'Active', 'MYR', '35.00'],
        ];
    }

    /* ---------------------------------------------------------- private --- */

    /**
     * Find the header row (within the first 10 rows) and map fields to column indexes.
     *
     * @return array{0:int, 1: array<string,int>}
     */
    private function detectHeader(array $sheet): array
    {
        $bestIndex = (int) array_key_first($sheet);
        $bestMap   = [];

        foreach (array_slice($sheet, 0, 10, true) as $index => $row) {
            $map = $this->mapHeaders($row);
            if (count($map) > count($bestMap)) {
                $bestIndex = $index;
                $bestMap   = $map;
            }
            if (count($map) >= 3) {
                break;
            }
        }

        return [$bestIndex, $bestMap];
    }

    /** @return array<string, int> field => column index */
    private function mapHeaders(array $row): array
    {
        $headers = [];
        foreach ($row as $i => $cell) {
            $h = $this->normaliseHeader($cell);
            if ($h !== '') {
                $headers[$i] = $h;
            }
        }

        $map  = [];
        $used = [];

        // Pass 1: exact header names. Pass 2: header contains a known name ("Selling price (MYR)").
        foreach ([true, false] as $exact) {
            foreach (self::FIELDS as $field => $def) {
                if (isset($map[$field])) {
                    continue;
                }
                foreach ($headers as $i => $h) {
                    if (isset($used[$i])) {
                        continue;
                    }
                    foreach ($def['aliases'] as $alias) {
                        $hit = $exact
                            ? $h === $alias
                            : (strlen($alias) >= 4 && preg_match('/\b' . preg_quote($alias, '/') . '\b/', $h));
                        if ($hit) {
                            $map[$field] = $i;
                            $used[$i]    = true;
                            continue 3;
                        }
                    }
                }
            }
        }

        return $map;
    }

    private function normaliseHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^\p{L}\p{N}$]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private function cleanName(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** Turn one spreadsheet row into the same fields the add form posts. */
    private function rowInput(array $row, array $map): array
    {
        $get = static fn (string $field): string => isset($map[$field]) ? trim((string) ($row[$map[$field]] ?? '')) : '';

        $currency = $this->currency($get('currency'));
        [$price, $priceCurrency] = $this->money($get('price'));

        if ($currency === '') {
            $currency = $priceCurrency ?: 'MYR';
        }

        return [
            'name'            => $this->cleanName($get('name')),
            'sku'             => $get('sku'),
            'category'        => $get('category'),
            'description'     => $get('description'),
            'characteristics' => $get('characteristics'),
            'price'           => $price,
            'promotion_price' => '',   // promotional prices are not imported; set them per product
            'currency'        => $currency,
            'status'          => $this->status($get('status')),
        ];
    }

    /** "RM 1,250.50" -> ["1,250.50", "MYR"]. Unknown text is left for the validator to reject. */
    private function money(string $value): array
    {
        if ($value === '') {
            return ['', ''];
        }

        $currency = '';
        $upper    = mb_strtoupper($value);

        foreach (Product::CURRENCIES as $code) {
            if (str_contains($upper, $code)) {
                $currency = $code;
                break;
            }
        }
        if ($currency === '') {
            foreach (self::CURRENCY_ALIASES as $alias => $code) {
                if (str_contains($upper, mb_strtoupper($alias))) {
                    $currency = $code;
                    break;
                }
            }
        }

        $number = (string) preg_replace('/[^\d.,\-]/', '', $value);

        // "12,50" or "1.250,50" (comma as decimal point) -> "12.50" / "1250.50"
        if (preg_match('/^\d+,\d{1,2}$/', $number) || preg_match('/^\d{1,3}(\.\d{3})+,\d{1,2}$/', $number)) {
            $number = str_replace(['.', ','], ['', '.'], $number);
        }

        return [$number !== '' ? $number : $value, $currency];
    }

    private function currency(string $value): string
    {
        $upper = mb_strtoupper(trim($value));

        if ($upper === '') {
            return '';
        }

        return self::CURRENCY_ALIASES[$upper] ?? $upper;
    }

    private function status(string $value): string
    {
        $v = mb_strtolower(trim($value));

        if ($v === '' || in_array($v, ['active', 'yes', 'y', 'true', '1', 'enabled', 'enable', 'on', 'available', 'in stock', 'published', 'show'], true)) {
            return 'active';
        }
        if (in_array($v, ['inactive', 'no', 'n', 'false', '0', 'disabled', 'disable', 'off', 'unavailable', 'out of stock', 'hidden', 'hide', 'draft'], true)) {
            return 'inactive';
        }

        return $v; // rejected by the validator with a clear message
    }
}
