<?php
/**
 * ProfileController.php - the signed-in user's own account.
 *
 * Two independent forms: display name, and password change. Email is shown
 * read-only, matching the existing design; changing the address would need a
 * verification flow, which this portal does not have.
 */
declare(strict_types=1);

namespace Src\Controllers;

use Src\Models\User;

final class ProfileController
{
    /** GET /?page=profile */
    public function index(): void
    {
        $this->render(require_login());
    }

    /** POST /?page=profile&action=update */
    public function update(): void
    {
        $user = require_login();
        $name = trim((string) ($_POST['name'] ?? ''));
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Enter your name.';
        } elseif (mb_strlen($name) < 2) {
            $errors['name'] = 'Enter your full name (at least 2 characters).';
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

    /** POST /?page=profile&action=password */
    public function password(): void
    {
        $user    = require_login();
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $hash    = (new User())->passwordHash((int) $user['id']);
        $errors  = [];

        if ($hash === null || $current === '' || !password_verify($current, $hash)) {
            $errors['current_password'] = 'That is not your current password.';
        }

        $problem = password_problem($new);
        if ($problem !== null) {
            $errors['password'] = $problem;
        }
        if ($new !== $confirm) {
            $errors['password_confirm'] = 'The two passwords do not match.';
        }

        if ($errors !== []) {
            $this->fail($user, [], $errors);
            return;
        }

        (new User())->updatePassword((int) $user['id'], password_hash($new, PASSWORD_DEFAULT));

        // A password change rotates both the session id and the CSRF token.
        session_regenerate_id(true);
        csrf_rotate();

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
            'lastLogin'=> $user['last_login_at'] ?? null,
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

        set_status(422);
        $this->render($user, $errors, $passwordErrors, $values);
    }
}
