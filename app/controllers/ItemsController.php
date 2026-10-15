<?php
/**
 * ItemsController.php - list, create, edit and delete items.
 *
 * Thin on purpose: it validates, asks the model, then redirects or returns JSON.
 * The model scopes every row by the acting user, so a user can never reach
 * another account's item even if this controller is wrong about the rules.
 */
declare(strict_types=1);

class ItemsController
{
    private const PER_PAGE = 10;

    /** GET ?page=items - filtered, paginated list. */
    public function index(): void
    {
        $user   = require_login();
        $search = trim((string) ($_GET['q'] ?? ''));
        $status = (string) ($_GET['status'] ?? '');
        $page   = max(1, (int) ($_GET['p'] ?? 1));

        view('items/index', [
            'title'  => 'Items',
            'user'   => $user,
            'result' => (new Item())->paginate($user, $search, $status, $page, self::PER_PAGE),
            'search' => $search,
            'status' => $status,
            'scoped' => !is_admin(),
        ]);
    }

    /** GET ?page=items&action=create */
    public function create(): void
    {
        $this->renderForm(require_login(), null, ['title' => '', 'description' => '', 'status' => 'draft'], []);
    }

    /** POST ?page=items&action=store */
    public function store(): void
    {
        $user            = require_login();
        [$data, $errors] = $this->validate($_POST);

        if ($errors !== []) {
            $this->fail($user, null, $data, $errors);
            return;
        }

        (new Item())->create($user, $data['title'], $data['description'], $data['status']);
        respond_ok('Item created.', url('items'));
    }

    /** GET ?page=items&action=edit&id=N */
    public function edit(): void
    {
        $user = require_login();
        $item = $this->findOrFail($user, (int) ($_GET['id'] ?? 0));
        $this->renderForm($user, $item, $item, []);
    }

    /** POST ?page=items&action=update */
    public function update(): void
    {
        $user            = require_login();
        $item            = $this->findOrFail($user, (int) ($_POST['id'] ?? 0));
        [$data, $errors] = $this->validate($_POST);

        if ($errors !== []) {
            $this->fail($user, $item, $data, $errors);
            return;
        }

        (new Item())->update((int) $item['id'], $user, $data['title'], $data['description'], $data['status']);
        respond_ok('Item updated.', url('items'));
    }

    /** POST ?page=items&action=destroy */
    public function destroy(): void
    {
        $user = require_login();
        $item = $this->findOrFail($user, (int) ($_POST['id'] ?? 0));

        (new Item())->delete((int) $item['id'], $user);
        respond_ok('Item deleted.', url('items'));
    }

    /* ---------------------------------------------------------- private ---- */

    /**
     * Scoped lookup that tells "not yours" (403) from "gone" (404).
     * Hiding the edit button is cosmetic - this is the real guard.
     */
    private function findOrFail(array $user, int $id): array
    {
        $items = new Item();
        $item  = $id > 0 ? $items->find($id, $user) : null;

        if ($item === null) {
            if ($id > 0 && $items->exists($id)) {
                abort(403, 'That item belongs to another account.');
            }
            abort(404, 'Item not found.');
        }
        return $item;
    }

    /**
     * @return array{0:array{title:string,description:string,status:string},1:array<string,string>}
     */
    private function validate(array $input): array
    {
        $title       = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $status      = (string) ($input['status'] ?? 'draft');

        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Give the item a title.';
        } elseif (mb_strlen($title) > 190) {
            $errors['title'] = 'Keep the title under 190 characters.';
        }
        if (mb_strlen($description) > 2000) {
            $errors['description'] = 'Keep the description under 2000 characters.';
        }
        if (!in_array($status, Item::STATUSES, true)) {
            $errors['status'] = 'Pick one of: ' . implode(', ', Item::STATUSES) . '.';
            $status = 'draft';
        }

        return [
            ['title' => $title, 'description' => $description, 'status' => $status],
            $errors,
        ];
    }

    private function renderForm(array $user, ?array $item, array $values, array $errors): void
    {
        view('items/form', [
            'title'  => $item === null ? 'New item' : 'Edit item',
            'user'   => $user,
            'item'   => $item,
            'values' => $values,
            'errors' => $errors,
        ]);
    }

    /** Validation failed: re-render the form for a browser, return 422 for fetch(). */
    private function fail(array $user, ?array $item, array $values, array $errors): void
    {
        if (wants_json()) {
            json_response(false, ['errors' => $errors], 'Please fix the highlighted fields.', 422);
        }
        $this->renderForm($user, $item, $values, $errors);
    }
}
