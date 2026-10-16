<?php
/**
 * AdminController.php - account management. Admins only.
 *
 * require_admin() is called at the top of every method: hiding the sidebar link
 * is cosmetic, this is the actual guard.
 */
declare(strict_types=1);

class AdminController
{
    private const PER_PAGE = 10;

    /** GET ?page=admin */
    public function index(): void
    {
        // An admin is unscoped, so these counts are app-wide.
        $admin  = require_admin();
        $search = trim((string) ($_GET['q'] ?? ''));
        $page   = max(1, (int) ($_GET['p'] ?? 1));
        $users  = new User();

        view('admin/index', [
            'title'  => 'Admin',
            'user'   => $admin,
            'search' => $search,
            'result' => $users->paginate($search, $page, self::PER_PAGE),
            'stats'  => [
                'users'    => $users->count(),
                'admins'   => $users->countByRole('admin'),
                'disabled' => $users->countByStatus('disabled'),
                'items'    => (new Item())->count($admin),
                'ai'       => (new AiLog())->count($admin),
            ],
        ]);
    }

    /** POST ?page=admin&action=update - change one account's role and status. */
    public function update(): void
    {
        $admin  = require_admin();
        $id     = (int) ($_POST['id'] ?? 0);
        $role   = (string) ($_POST['role'] ?? '');
        $status = (string) ($_POST['status'] ?? '');
        $target = $this->findOrFail($id);

        if (!in_array($role, User::ROLES, true) || !in_array($status, User::STATUSES, true)) {
            $this->fail('Pick a valid role and status.');
        }

        // Without this an admin could lock themselves out of their own app.
        if ($id === (int) $admin['id'] && ($role !== 'admin' || $status !== 'active')) {
            $this->fail('You cannot remove your own admin access or disable your own account.');
        }

        $users = new User();
        $users->setRole($id, $role);
        $users->setStatus($id, $status);

        respond_ok($target['name'] . ' is now ' . $role . ' / ' . $status . '.', url('admin'));
    }

    /** POST ?page=admin&action=create - add a user and assign a role. */
    public function create(): void
    {
        require_admin();

        $name     = trim((string) ($_POST['name'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role     = (string) ($_POST['role'] ?? 'user');
        $errors   = [];

        if (mb_strlen($name) < 2) {
            $errors['name'] = 'Enter a name (at least 2 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif ((new User())->emailExists($email)) {
            $errors['email'] = 'That email is already registered.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Use at least 8 characters.';
        }
        if (!in_array($role, User::ROLES, true)) {
            $errors['role'] = 'Pick a valid role.';
        }

        if ($errors !== []) {
            if (wants_json()) {
                json_response(false, ['errors' => $errors], 'Please fix the highlighted fields.', 422);
            }
            flash('error', implode(' ', $errors));
            redirect(url('admin'));
        }

        $id = (new User())->create($name, $email, password_hash($password, PASSWORD_DEFAULT), $role);
        if (wants_json()) {
            json_response(true, ['redirect' => url('admin'), 'id' => $id], $name . ' created as ' . $role . '.');
        }
        flash('success', $name . ' created as ' . $role . '.');
        redirect(url('admin'));
    }

    /* ---------------------------------------------------------- private ---- */

    private function findOrFail(int $id): array
    {
        $target = $id > 0 ? (new User())->find($id) : null;
        if ($target === null) {
            abort(404, 'No account with that id.');
        }
        return $target;
    }

    private function fail(string $message): void
    {
        if (wants_json()) {
            json_response(false, [], $message, 422);
        }
        flash('error', $message);
        redirect(url('admin'));
    }
}
