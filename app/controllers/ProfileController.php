<?php
/**
 * ProfileController.php - the signed-in user's own account.
 * Two independent forms: display name, and password change.
 */
declare(strict_types=1);

class ProfileController
{
    /** GET ?page=profile */
    public function index(): void
    {
        $this->render(require_login());
    }

    /** POST ?page=profile&action=update - change the display name. */
    public function update(): void
    {
        $user   = require_login();
        $name   = trim((string) ($_POST['name'] ?? ''));
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Enter your name.';
        } elseif (mb_strlen($name) > 100) {
            $errors['name'] = 'Keep your name under 100 characters.';
        }

        if ($errors !== []) {
            $this->fail($user, $errors, [], ['name' => $name]);
            return;
        }

        (new User())->updateName((int) $user['id'], $name);
        respond_ok('Your name has been updated.', url('profile'));
    }

    /** POST ?page=profile&action=password - change the password. */
    public function password(): void
    {
        $user    = require_login();
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $hash    = (new User())->passwordHash((int) $user['id']);

        $errors = [];
        if ($hash === null || $current === '' || !password_verify($current, $hash)) {
            $errors['current_password'] = 'That is not your current password.';
        }
        if (validate_password($new) !== '') {
            $errors['password'] = validate_password($new);
        }
        if ($new !== $confirm) {
            $errors['password_confirm'] = 'The two passwords do not match.';
        }

        if ($errors !== []) {
            $this->fail($user, [], $errors);
            return;
        }

        (new User())->updatePassword((int) $user['id'], password_hash($new, PASSWORD_DEFAULT));
        respond_ok('Your password has been changed.', url('profile'));
    }

    /* ---------------------------------------------------------- private ---- */

    private function render(array $user, array $errors = [], array $passwordErrors = [], array $values = []): void
    {
        view('profile/index', [
            'title'    => 'Profile',
            'user'     => $user,
            'errors'   => $errors,
            'pwErrors' => $passwordErrors,
            'values'   => $values + ['name' => $user['name']],
            'stats'    => [
                'items' => (new Item())->count($user),
                'ai'    => (new AiLog())->count($user),
            ],
        ]);
    }

    private function fail(array $user, array $errors, array $passwordErrors, array $values = []): void
    {
        if (wants_json()) {
            json_response(
                false,
                ['errors' => array_merge($errors, $passwordErrors)],
                'Please fix the highlighted fields.',
                422
            );
        }
        $this->render($user, $errors, $passwordErrors, $values);
    }
}
