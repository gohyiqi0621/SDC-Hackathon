<?php
/**
 * CategoryController.php - categories for Product & Service.
 *
 * Add, rename/describe and delete the signed-in user's own categories.
 * A category that still has products cannot be deleted: move or remove
 * those products first (the database enforces this too).
 */
declare(strict_types=1);

namespace Src\Controllers;

use PDOException;
use Src\Models\Category;
use Src\Services\ProductService;

final class CategoryController
{
    /** GET /?page=categories  (?edit=N opens that category in the form) */
    public function index(): void
    {
        $user    = require_login();
        $editing = null;

        if (!empty($_GET['edit'])) {
            $editing = (new Category())->find((int) $user['id'], (int) $_GET['edit']);

            if ($editing === null) {
                abort(404, 'That category was not found.');
            }
        }

        $this->render($user, $editing, $editing ?? [], []);
    }

    /** POST /?page=categories&action=store */
    public function store(): void
    {
        $user   = require_login();
        $userId = (int) $user['id'];

        [$data, $errors] = (new ProductService())->validateCategory($userId, $_POST);

        if ($errors === []) {
            try {
                (new Category())->create($userId, $data['name'], $data['description']);
                respond_ok('Category "' . $data['name'] . '" was added.', url('categories'));
            } catch (PDOException $e) {
                $errors = $this->dbErrors($e);
            }
        }

        $this->fail($user, null, $_POST, $errors);
    }

    /** POST /?page=categories&action=update */
    public function update(): void
    {
        $user     = require_login();
        $userId   = (int) $user['id'];
        $category = (new Category())->find($userId, (int) ($_POST['id'] ?? 0));

        if ($category === null) {
            abort(404, 'That category was not found.');
        }

        [$data, $errors] = (new ProductService())->validateCategory($userId, $_POST, (int) $category['id']);

        if ($errors === []) {
            try {
                (new Category())->update($userId, (int) $category['id'], $data['name'], $data['description']);
                respond_ok('Category "' . $data['name'] . '" was updated.', url('categories'));
            } catch (PDOException $e) {
                $errors = $this->dbErrors($e);
            }
        }

        $this->fail($user, $category, $_POST, $errors);
    }

    /** POST /?page=categories&action=delete */
    public function delete(): void
    {
        $user     = require_login();
        $userId   = (int) $user['id'];
        $model    = new Category();
        $category = $model->find($userId, (int) ($_POST['id'] ?? 0));

        if ($category === null) {
            flash('error', 'That category was not found. It may already have been deleted.', 'Not deleted');
            redirect(url('categories'));
        }

        $count = $model->productCount($userId, (int) $category['id']);

        if ($count > 0) {
            flash(
                'error',
                '"' . $category['name'] . '" still has ' . $count . ' product' . ($count === 1 ? '' : 's')
                    . '. Move them to another category or remove them first.',
                'Category is in use'
            );
            redirect(url('categories'));
        }

        try {
            $model->delete($userId, (int) $category['id']);
        } catch (PDOException $e) {
            // A product was added to it between the check and the delete.
            flash('error', '"' . $category['name'] . '" is in use by a product and was not deleted.', 'Category is in use');
            redirect(url('categories'));
        }

        flash('success', 'Category "' . $category['name'] . '" was deleted.', 'Category deleted');
        redirect(url('categories'));
    }

    /* ---------------------------------------------------------- private ---- */

    private function render(array $user, ?array $editing, array $values, array $errors): void
    {
        view('products/categories', [
            'user'       => $user,
            'categories' => (new Category())->allForUser((int) $user['id']),
            'editing'    => $editing,
            'values'     => $values,
            'errors'     => $errors,
        ]);
    }

    private function fail(array $user, ?array $editing, array $values, array $errors): void
    {
        if (wants_json()) {
            json_response(false, ['errors' => $errors], $errors['form'] ?? 'Please fix the highlighted fields.', 422);
        }

        set_status(422);
        $this->render($user, $editing, $values, $errors);
    }

    private function dbErrors(PDOException $e): array
    {
        if ($e->getCode() === '23505') {
            return ['name' => 'You already have a category with this name.'];
        }

        error_log('[categories] ' . $e->getMessage());

        return ['form' => 'The category could not be saved. Please try again.'];
    }
}
