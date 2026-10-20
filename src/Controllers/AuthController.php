<?php
/**
 * AuthController.php - register, sign in, sign out, forgot/reset password.
 *
 * The portal is user-only: every account (self-registered or created with
 * bin/create-user.php) is a regular user. There is no administrator sign-in.
 */
declare(strict_types=1);

namespace Src\Controllers;

use Src\Models\User;
use Src\Services\AuthService;
use Src\Services\BrevoMailer;
use Src\Services\PasswordResetService;

final class AuthController
{
    /** GET /?page=auth - send the visitor where they belong. */
    public function index(): void
    {
        redirect(AuthService::check() ? url('dashboard') : url('auth', ['action' => 'login']));
    }

    /** GET /?page=auth&action=login */
    public function showLogin(): void
    {
        $this->renderLogin([], ['email' => (string) ($_GET['email'] ?? '')]);
    }

    /** POST /?page=auth&action=login - verified, then straight to the dashboard. */
    public function login(): void
    {
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $errors   = [];
        $status   = 422;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($password === '') {
            $errors['password'] = 'Enter your password.';
        }

        if ($errors === []) {
            $result = AuthService::attempt($email, $password);

            if ($result['ok']) {
                respond_ok(
                    'Welcome back, ' . (string) $result['user']['name'] . '.',
                    url('dashboard')
                );
            }

            $status = (int) ($result['code'] ?? 401);

            // A throttle is a form-level message, not a field error.
            if ($status === 429) {
                $errors['form'] = (string) $result['message'];
            } elseif (($result['code'] ?? 0) === 403) {
                $errors['form'] = (string) $result['message'];
            } else {
                $errors['form'] = (string) $result['message'];
            }
        }

        $this->renderLogin($errors, ['email' => $email], $status);
    }

    /** POST /?page=auth&action=logout - POST-only + CSRF, so a link cannot sign you out. */
    public function logout(): void
    {
        AuthService::logout();

        if (wants_json()) {
            json_response(true, ['redirect' => url('auth', ['action' => 'login'])], 'You have been signed out.');
        }

        flash('success', 'You have been signed out.');
        redirect(url('auth', ['action' => 'login']));
    }

    /* ------------------------------------------------------ registration --- */

    /** GET /?page=auth&action=register */
    public function showRegister(): void
    {
        $this->renderRegister([], ['name' => '', 'email' => (string) ($_GET['email'] ?? '')]);
    }

    /** POST /?page=auth&action=register - create the account, sign in, go to the dashboard. */
    public function register(): void
    {
        $name     = trim((string) ($_POST['name'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');
        $old      = ['name' => $name, 'email' => $email];
        $errors   = [];
        $users    = new User();

        if ($name === '') {
            $errors['name'] = 'Enter your name.';
        } elseif (mb_strlen($name) < 2) {
            $errors['name'] = 'Enter your full name (at least 2 characters).';
        } elseif (mb_strlen($name) > 100) {
            $errors['name'] = 'Keep your name under 100 characters.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (mb_strlen($email) > 190) {
            $errors['email'] = 'That email address is too long.';
        }

        $problem = password_problem($password);
        if ($problem !== null) {
            $errors['password'] = $problem;
        }
        if ($confirm !== $password) {
            $errors['password_confirm'] = 'The two passwords do not match.';
        }

        if (!isset($errors['email']) && $users->emailExists($email)) {
            $errors['email'] = 'An account with this email already exists. Sign in instead.';
        }

        if ($errors === []) {
            try {
                $id = $users->create($name, $email, password_hash($password, PASSWORD_DEFAULT));
            } catch (\PDOException $e) {
                // Unique index hit by a simultaneous sign-up with the same address.
                if ($e->getCode() === '23505') {
                    $errors['email'] = 'An account with this email already exists. Sign in instead.';
                } else {
                    throw $e;
                }
            }

            if ($errors === []) {
                $user = $users->find($id);

                if ($user !== null) {
                    AuthService::login($user);
                    $users->touchLogin($id);

                    respond_ok('Welcome, ' . $name . '! Your account is ready.', url('dashboard'));
                }

                respond_ok('Your account is ready. Please sign in.', url('auth', ['action' => 'login', 'email' => $email]));
            }
        }

        $this->renderRegister($errors, $old, 422);
    }

    /* ---------------------------------------------------- password reset --- */

    /** GET /?page=auth&action=forgot */
    public function showForgot(): void
    {
        view('auth/forgot', [
            'title'  => 'Forgot password',
            'errors' => [],
            'old'    => ['email' => (string) ($_GET['email'] ?? '')],
            'ready'  => (new BrevoMailer())->isConfigured(),
        ]);
    }

    /**
     * POST /?page=auth&action=forgot
     *
     * The response is identical whether or not the address exists: the same
     * message, the same redirect and the same timing envelope. An unknown
     * address simply never receives a mail.
     */
    public function forgot(): void
    {
        $email  = trim((string) ($_POST['email'] ?? ''));
        $errors = [];

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        if ($errors !== []) {
            if (wants_json()) {
                json_response(false, ['errors' => $errors], 'Please fix the errors below.', 422);
            }

            set_status(422);
            view('auth/forgot', [
                'title'  => 'Forgot password',
                'errors' => $errors,
                'old'    => ['email' => $email],
                'ready'  => (new BrevoMailer())->isConfigured(),
            ]);
            return;
        }

        $mailer = new BrevoMailer();

        if (!$mailer->isConfigured()) {
            $message = 'Email delivery is not set up yet. Ask the person who installed the app to add the Brevo credentials.';

            if (wants_json()) {
                json_response(false, null, $message, 503);
            }

            flash('error', $message, 'Email unavailable');
            redirect(url('auth', ['action' => 'forgot']));
        }

        // Always succeeds from the caller's point of view; a send failure is
        // logged by the mailer and never reveals whether the account exists.
        (new PasswordResetService())->request($email, client_ip());

        $message = 'If that address is registered, a password reset link is on its way. '
            . 'The link works once and expires in ' . PasswordResetService::TTL_MINUTES . ' minutes.';

        if (wants_json()) {
            json_response(true, ['redirect' => url('auth', ['action' => 'login'])], $message);
        }

        flash('success', $message, 'Check your email');
        redirect(url('auth', ['action' => 'login']));
    }

    /** GET /?page=auth&action=reset&token=... */
    public function showReset(): void
    {
        $token = trim((string) ($_GET['token'] ?? ''));
        $row   = (new PasswordResetService())->findValidToken($token);
        $valid = $row !== null;

        view('auth/reset', [
            'title'  => 'Reset your password',
            'token'  => $token,
            'valid'  => $valid,
            'email'  => $valid ? (string) $row['email'] : '',
            'errors' => [],
            'ttl'    => PasswordResetService::TTL_MINUTES,
        ]);
    }

    /** POST /?page=auth&action=reset */
    public function reset(): void
    {
        $token    = trim((string) ($_POST['token'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');
        $service  = new PasswordResetService();
        $row      = $service->findValidToken($token);
        $valid    = $row !== null;
        $errors   = [];

        if (!$valid) {
            $errors['form'] = 'That reset link is not valid or has expired. Request a new one.';
        } else {
            $problem = password_problem($password);
            if ($problem !== null) {
                $errors['password'] = $problem;
            }
            if ($confirm !== $password) {
                $errors['password_confirm'] = 'The two passwords do not match.';
            }
        }

        if ($errors === []) {
            $result = $service->reset($token, $password);

            if ($result['ok']) {
                $message = 'Password updated. Sign in with your new password.';

                if (wants_json()) {
                    json_response(true, ['redirect' => url('auth', ['action' => 'login'])], $message);
                }

                flash('success', $message);
                redirect(url('auth', ['action' => 'login']));
            }

            $errors['form'] = $result['message'];
            $valid = false;
        }

        if (wants_json()) {
            json_response(false, ['errors' => $errors], $errors['form'] ?? 'Please fix the errors below.', 422);
        }

        set_status(422);

        view('auth/reset', [
            'title'  => 'Reset your password',
            'token'  => $token,
            'valid'  => $valid,
            'email'  => $valid ? (string) $row['email'] : '',
            'errors' => $errors,
            'ttl'    => PasswordResetService::TTL_MINUTES,
        ]);
    }

    /* ---------------------------------------------------------- private ---- */

    private function renderLogin(array $errors, array $old, int $status = 200): void
    {
        if (wants_json()) {
            $code = $status >= 400 ? $status : 422;
            json_response(false, ['errors' => $errors], $errors['form'] ?? 'Please fix the errors below.', $code);
        }

        if ($status !== 200) {
            set_status($status);
        }

        view('auth/login', [
            'title'  => 'Sign in',
            'errors' => $errors,
            'old'    => $old,
        ]);
    }

    private function renderRegister(array $errors, array $old, int $status = 200): void
    {
        if (wants_json() && $status >= 400) {
            json_response(false, ['errors' => $errors], $errors['form'] ?? 'Please fix the errors below.', $status);
        }

        if ($status !== 200) {
            set_status($status);
        }

        view('auth/register', [
            'title'  => 'Create account',
            'errors' => $errors,
            'old'    => $old,
        ]);
    }
}
