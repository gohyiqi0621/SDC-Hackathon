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
use Src\Services\ProductService;

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

        $data['image_url'] = isset($_FILES['image']) ? $service->storeImage($userId, $_FILES['image']) : null;

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
        $newImage = isset($_FILES['image']) ? $service->storeImage($userId, $_FILES['image']) : null;
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
