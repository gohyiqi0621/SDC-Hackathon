<?php
/**
 * ProductService.php - validation and image handling for products/categories.
 *
 * Controllers pass raw $_POST / $_FILES in and get back either clean data or
 * field errors. Images are stored under public/uploads/products/<user id>/
 * with a random name; only real JPG, PNG, WebP or GIF files are accepted.
 */
declare(strict_types=1);

namespace Src\Services;

use Src\Models\Category;
use Src\Models\Product;

final class ProductService
{
    public const IMAGE_MAX_BYTES = 2 * 1024 * 1024;
    private const IMAGE_DIR      = 'uploads/products';
    private const IMAGE_TYPES    = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    private const MAX_PRICE = 9999999999.99; // NUMERIC(12,2)

    /* ----------------------------------------------------------- products --- */

    /**
     * Validate the product form.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>} [clean data, errors]
     */
    public function validateProduct(int $userId, array $input, ?int $productId = null): array
    {
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Enter the product or service name.';
        } elseif (mb_strlen($name) > 200) {
            $errors['name'] = 'Keep the name under 200 characters.';
        }

        $sku = trim((string) ($input['sku'] ?? ''));
        if (mb_strlen($sku) > 100) {
            $errors['sku'] = 'Keep the SKU under 100 characters.';
        } elseif ($sku !== '' && (new Product())->skuExists($userId, $sku, $productId)) {
            $errors['sku'] = 'You already have a product with this SKU.';
        }

        $categoryId = (int) ($input['category_id'] ?? 0);
        if ($categoryId <= 0) {
            $errors['category_id'] = 'Choose a category.';
        } elseif ((new Category())->find($userId, $categoryId) === null) {
            $errors['category_id'] = 'That category was not found. Choose another one.';
        }

        [$price, $priceError] = $this->money($input['price'] ?? '', true);
        if ($priceError !== null) {
            $errors['price'] = $priceError;
        }

        // Promotional prices are no longer used: saving a product clears any old one.
        $promo = null;

        $currency = strtoupper(trim((string) ($input['currency'] ?? 'MYR'))) ?: 'MYR';
        if (!in_array($currency, Product::CURRENCIES, true)) {
            $errors['currency'] = 'Choose a currency from the list.';
        }

        $status = (string) ($input['status'] ?? 'active');
        if (!in_array($status, Product::STATUSES, true)) {
            $errors['status'] = 'Choose Active or Inactive.';
        }

        $description     = $this->text($input['description'] ?? '');
        $characteristics = $this->text($input['characteristics'] ?? '');

        if ($description !== null && mb_strlen($description) > 5000) {
            $errors['description'] = 'Keep the description under 5,000 characters.';
        }
        if ($characteristics !== null && mb_strlen($characteristics) > 10000) {
            $errors['characteristics'] = 'Keep the characteristics under 10,000 characters.';
        }

        $data = [
            'name'            => $name,
            'sku'             => $sku !== '' ? $sku : null,
            'category_id'     => $categoryId,
            'description'     => $description,
            'characteristics' => $characteristics,
            'price'           => $price,
            'promotion_price' => $promo,
            'currency'        => $currency,
            'status'          => $status,
        ];

        return [$data, $errors];
    }

    /* ------------------------------------------------------------ images --- */

    /**
     * Check an uploaded image without saving it.
     *
     * @return string|null an error message, or null when there is no file or it is fine
     */
    public function imageError(?array $file): ?string
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE
            || (int) ($file['size'] ?? 0) > self::IMAGE_MAX_BYTES) {
            return 'The image must be 2 MB or smaller.';
        }

        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return 'The image could not be uploaded. Please try again.';
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);

        if (!isset(self::IMAGE_TYPES[$mime]) || @getimagesize((string) $file['tmp_name']) === false) {
            return 'Upload a JPG, PNG, WebP or GIF image.';
        }

        return null;
    }

    /** Save a checked upload and return its path inside public/, e.g. uploads/products/3/ab12.jpg */
    public function storeImage(int $userId, array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        $ext  = self::IMAGE_TYPES[$mime] ?? null;

        if ($ext === null) {
            return null;
        }

        $relativeDir = self::IMAGE_DIR . '/' . $userId;
        $absoluteDir = PUBLIC_ROOT . '/' . $relativeDir;

        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            error_log('[products] cannot create ' . $absoluteDir);
            return null;
        }

        $relative = $relativeDir . '/' . bin2hex(random_bytes(16)) . '.' . $ext;

        if (!move_uploaded_file((string) $file['tmp_name'], PUBLIC_ROOT . '/' . $relative)) {
            error_log('[products] could not move upload to ' . $relative);
            return null;
        }

        return $relative;
    }

    /** Remove an image this app stored for this user. Anything else is left alone. */
    public function deleteImage(int $userId, ?string $relative): void
    {
        $relative = (string) $relative;
        $prefix   = self::IMAGE_DIR . '/' . $userId . '/';

        if ($relative === '' || !str_starts_with($relative, $prefix) || str_contains($relative, '..')) {
            return;
        }

        $path = PUBLIC_ROOT . '/' . $relative;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /* ---------------------------------------------------------- categories --- */

    /** @return array{0: array{name: string, description: ?string}, 1: array<string, string>} */
    public function validateCategory(int $userId, array $input, ?int $categoryId = null): array
    {
        $errors = [];
        $name   = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            $errors['name'] = 'Enter a category name.';
        } elseif (mb_strlen($name) > 100) {
            $errors['name'] = 'Keep the category name under 100 characters.';
        } elseif ((new Category())->nameExists($userId, $name, $categoryId)) {
            $errors['name'] = 'You already have a category with this name.';
        }

        $description = $this->text($input['description'] ?? '');
        if ($description !== null && mb_strlen($description) > 2000) {
            $errors['description'] = 'Keep the description under 2,000 characters.';
        }

        return [['name' => $name, 'description' => $description], $errors];
    }

    /* ----------------------------------------------------------- helpers --- */

    /** @return array{0: ?string, 1: ?string} [amount as "123.45" or null, error] */
    private function money(mixed $raw, bool $required): array
    {
        $value = str_replace([',', ' '], '', trim((string) $raw));

        if ($value === '') {
            return [null, $required ? 'Enter the selling price.' : null];
        }

        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value)) {
            return [null, 'Enter an amount like 25 or 25.90 (no negative numbers).'];
        }

        if ((float) $value > self::MAX_PRICE) {
            return [null, 'That amount is too large.'];
        }

        // Format as text (no float rounding): "25" -> "25.00", "007.5" -> "7.50".
        [$whole, $cents] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';

        return [$whole . '.' . str_pad($cents, 2, '0'), null];
    }

    /** Trimmed text, or null when empty. Line breaks are kept. */
    private function text(mixed $raw): ?string
    {
        $value = trim(str_replace("\r\n", "\n", (string) $raw));

        return $value === '' ? null : $value;
    }
}
