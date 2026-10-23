<?php
/**
 * ProductController.php - AI Chatbot > Customer > Product & Service.
 *
 * List (search, filter, sort, paginate), add, edit and remove the signed-in
 * user's own products and services. Every query is scoped to that user.
 */
declare(strict_types=1);

namespace Src\Controllers;

use PDOException;
use Src\Models\Category;
use Src\Models\Product;
use Src\Services\ProductImportService;
use Src\Services\ProductService;
use Throwable;

final class ProductController
{
    private const PER_PAGE = 10;

    /** GET /?page=products */
    public function index(): void
    {
        $user    = require_login();
        $userId  = (int) $user['id'];
        $filters = $this->filters();
        $page    = max(1, (int) ($_GET['p'] ?? 1));

        $result = (new Product())->search($userId, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $pages  = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        // Asked for a page past the end (e.g. after a delete): show the last one.
        if ($page > $pages && $result['total'] > 0) {
            $page   = $pages;
            $result = (new Product())->search($userId, $filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        }

        view('products/index', [
            'user'       => $user,
            'products'   => $result['items'],
            'total'      => $result['total'],
            'allCount'   => (new Product())->countForUser($userId),
            'categories' => (new Category())->allForUser($userId),
            'filters'    => $filters,
            'page'       => $page,
            'pages'      => $pages,
            'perPage'    => self::PER_PAGE,
        ]);
    }

    /** GET /?page=products&action=create */
    public function create(): void
    {
        $user = require_login();

        $this->renderForm($user, null, [
            'currency'    => 'MYR',
            'status'      => 'active',
            'category_id' => (int) ($_GET['category'] ?? 0),
        ]);
    }

    /** POST /?page=products&action=store */
    public function store(): void
    {
        $user    = require_login();
        $userId  = (int) $user['id'];
        $service = new ProductService();

        [$data, $errors] = $service->validateProduct($userId, $_POST);
        $imageError = $service->imageError($_FILES['image'] ?? null);
        if ($imageError !== null) {
            $errors['image'] = $imageError;
        }

        if ($errors !== []) {
            $this->fail($user, null, $_POST, $errors);
            return;
        }

        $imageFile   = $_FILES['image'] ?? null;
        $hasImage    = $imageFile !== null && ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $data['image_url'] = $hasImage ? $service->storeImage($userId, $imageFile) : null;
        if ($hasImage && $data['image_url'] === null) {
            $this->fail($user, null, $_POST, ['image' => 'Image storage is temporarily unavailable. Please try again later.']);
            return;
        }

        try {
            (new Product())->create($userId, $data);
        } catch (PDOException $e) {
            $service->deleteImage($userId, $data['image_url']);
            $this->fail($user, null, $_POST, $this->dbErrors($e));
            return;
        }

        respond_ok('"' . $data['name'] . '" was added.', url('products'));
    }

    /** GET /?page=products&action=edit&id=N */
    public function edit(): void
    {
        $user    = require_login();
        $product = $this->ownedOr404($user, (int) ($_GET['id'] ?? 0));

        $this->renderForm($user, $product, $product);
    }

    /** POST /?page=products&action=update */
    public function update(): void
    {
        $user    = require_login();
        $userId  = (int) $user['id'];
        $product = $this->ownedOr404($user, (int) ($_POST['id'] ?? 0));
        $service = new ProductService();

        [$data, $errors] = $service->validateProduct($userId, $_POST, (int) $product['id']);
        $imageError = $service->imageError($_FILES['image'] ?? null);
        if ($imageError !== null) {
            $errors['image'] = $imageError;
        }

        if ($errors !== []) {
            $this->fail($user, $product, $_POST + ['image_url' => $product['image_url']], $errors);
            return;
        }

        $oldImage = $product['image_url'];
        $imageFile = $_FILES['image'] ?? null;
        $hasImage  = $imageFile !== null && ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $newImage  = $hasImage ? $service->storeImage($userId, $imageFile) : null;
        if ($hasImage && $newImage === null) {
            $this->fail($user, $product, $_POST + ['image_url' => $product['image_url']], [
                'image' => 'Image storage is temporarily unavailable. Please try again later.',
            ]);
            return;
        }
        $remove   = !empty($_POST['remove_image']);

        $data['image_url'] = $newImage ?? ($remove ? null : $oldImage);

        try {
            (new Product())->update($userId, (int) $product['id'], $data);
        } catch (PDOException $e) {
            $service->deleteImage($userId, $newImage);
            $this->fail($user, $product, $_POST + ['image_url' => $oldImage], $this->dbErrors($e));
            return;
        }

        // The old file is only removed once the database no longer points at it.
        if ($oldImage !== null && $oldImage !== $data['image_url']) {
            $service->deleteImage($userId, $oldImage);
        }

        respond_ok('"' . $data['name'] . '" was updated.', url('products'));
    }

    /** POST /?page=products&action=delete */
    public function delete(): void
    {
        $user    = require_login();
        $userId  = (int) $user['id'];
        $product = (new Product())->find($userId, (int) ($_POST['id'] ?? 0));

        if ($product === null) {
            flash('error', 'That product was not found. It may already have been removed.', 'Not removed');
            redirect($this->backUrl());
        }

        (new Product())->delete($userId, (int) $product['id']);
        (new ProductService())->deleteImage($userId, $product['image_url']);

        flash('success', '"' . $product['name'] . '" was removed.', 'Product removed');
        redirect($this->backUrl());
    }

    /* ------------------------------------------------------------ import --- */

    /**
     * POST /?page=products&action=import_preview  (fetch, multipart "file")
     *
     * Reads the file, detects the columns and checks every row. Nothing is saved yet:
     * the checked rows wait in the session until import_commit.
     */
    public function importPreview(): void
    {
        $user    = require_login();
        $userId  = (int) $user['id'];
        $service = new ProductImportService();
        $file    = $_FILES['file'] ?? null;

        $error = $service->fileError($file);
        if ($error !== null) {
            json_response(false, ['errors' => ['file' => $error]], $error, 422);
        }

        try {
            $analysis = $service->analyse($userId, (string) $file['tmp_name'], $service->extension($file));
        } catch (\RuntimeException $e) {
            json_response(false, ['errors' => ['file' => $e->getMessage()]], $e->getMessage(), 422);
        } catch (Throwable $e) {
            error_log('[products import] ' . $e->getMessage());
            json_response(false, null, 'The file could not be read. Save it as .csv or .xlsx and try again.', 422);
        }

        $token = bin2hex(random_bytes(16));
        $_SESSION['product_import'] = [
            'token'    => $token,
            'user_id'  => $userId,
            'analysis' => $analysis,
        ];

        $fields = [];
        foreach (ProductImportService::DISPLAY_ORDER as $field) {
            $fields[] = [
                'field'    => $field,
                'label'    => ProductImportService::FIELDS[$field]['label'],
                'required' => ProductImportService::FIELDS[$field]['required'],
                'column'   => $analysis['columns'][$field],
            ];
        }

        $sample = array_map(static fn (array $row): array => $row['input'] + ['line' => $row['line']], array_slice($analysis['rows'], 0, 5));

        json_response(true, [
            'token'          => $token,
            'file'           => (string) ($file['name'] ?? ''),
            'fields'         => $fields,
            'missing'        => $analysis['missing'],
            'total'          => $analysis['total'],
            'valid'          => count($analysis['rows']),
            'skipped'        => array_slice($analysis['skipped'], 0, 100),
            'skipped_count'  => count($analysis['skipped']),
            'new_categories' => $analysis['new_categories'],
            'sample'         => $sample,
        ], 'File checked.');
    }

    /** POST /?page=products&action=import_commit  (token, create_categories=1) */
    public function importCommit(): void
    {
        $user    = require_login();
        $userId  = (int) $user['id'];
        $pending = $_SESSION['product_import'] ?? null;
        $token   = (string) ($_POST['token'] ?? '');

        if (!is_array($pending) || (int) $pending['user_id'] !== $userId || !hash_equals((string) $pending['token'], $token)) {
            json_response(false, null, 'This import has expired. Please upload the file again.', 409);
        }

        unset($_SESSION['product_import']);
        $analysis = $pending['analysis'];

        if ($analysis['rows'] === []) {
            json_response(false, null, 'There are no valid rows to import.', 422);
        }

        try {
            $result = (new ProductImportService())->commit($userId, $analysis, ($_POST['create_categories'] ?? '') === '1');
        } catch (\RuntimeException $e) {
            json_response(false, null, $e->getMessage(), 422);
        } catch (Throwable $e) {
            error_log('[products import] ' . $e->getMessage());
            json_response(false, null, 'The import failed and nothing was saved. Please try again.', 500);
        }

        $message = $result['imported'] . ' product' . ($result['imported'] === 1 ? '' : 's') . ' imported';
        if ($result['categories'] > 0) {
            $message .= ', ' . $result['categories'] . ' new categor' . ($result['categories'] === 1 ? 'y' : 'ies') . ' added';
        }
        $skipped = count($analysis['skipped']) + count($result['skipped']);
        if ($skipped > 0) {
            $message .= '. ' . $skipped . ' row' . ($skipped === 1 ? ' was' : 's were') . ' skipped';
        }
        $message .= '.';

        flash('success', $message, 'Import complete');
        json_response(true, ['redirect' => url('products'), 'result' => $result], $message);
    }

    /** POST /?page=products&action=import_cancel - drop a checked file that will not be imported. */
    public function importCancel(): void
    {
        require_login();
        unset($_SESSION['product_import']);

        json_response(true, null, 'Import cancelled. No products were added.');
    }

    /** GET /?page=products&action=import_template - a CSV with the expected columns. */
    public function importTemplate(): void
    {
        require_login();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="product-import-template.csv"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // so Excel opens it as UTF-8
        foreach ((new ProductImportService())->templateRows() as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        fclose($out);
        exit;
    }

    /* ---------------------------------------------------------- private ---- */

    /** Search / filter / sort values from the query string, cleaned. */
    private function filters(): array
    {
        $sort   = (string) ($_GET['sort'] ?? 'newest');
        $status = (string) ($_GET['status'] ?? '');

        return [
            'q'        => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
            'category' => max(0, (int) ($_GET['category'] ?? 0)),
            'status'   => in_array($status, Product::STATUSES, true) ? $status : '',
            'sort'     => isset(Product::SORTS[$sort]) ? $sort : 'newest',
        ];
    }

    private function ownedOr404(array $user, int $id): array
    {
        $product = $id > 0 ? (new Product())->find((int) $user['id'], $id) : null;

        if ($product === null) {
            abort(404, 'That product was not found.');
        }

        return $product;
    }

    private function renderForm(array $user, ?array $product, array $values, array $errors = []): void
    {
        view('products/form', [
            'user'       => $user,
            'product'    => $product,
            'values'     => $values,
            'errors'     => $errors,
            'categories' => (new Category())->allForUser((int) $user['id']),
        ]);
    }

    private function fail(array $user, ?array $product, array $values, array $errors): void
    {
        if (wants_json()) {
            json_response(false, ['errors' => $errors], $errors['form'] ?? 'Please fix the highlighted fields.', 422);
        }

        set_status(422);
        $this->renderForm($user, $product, $values, $errors);
    }

    /** Turn a constraint error (e.g. a duplicate SKU from a double submit) into a field error. */
    private function dbErrors(PDOException $e): array
    {
        $message = $e->getMessage();

        if ($e->getCode() === '23505' && str_contains($message, 'products_user_sku_uniq')) {
            return ['sku' => 'You already have a product with this SKU.'];
        }
        if ($e->getCode() === '23503') {
            return ['category_id' => 'That category was not found. Choose another one.'];
        }
        if ($e->getCode() === '23514') {
            return ['form' => 'Some values are not allowed. Check the prices and status.'];
        }

        error_log('[products] ' . $message);

        return ['form' => 'The product could not be saved. Please try again.'];
    }

    /** Return to the list the user came from (keeps filters and page), never off-site. */
    private function backUrl(): string
    {
        $back = (string) ($_POST['return'] ?? '');

        return str_starts_with($back, APP_URL . '/') ? $back : url('products');
    }
}
