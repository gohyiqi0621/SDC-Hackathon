<?php
/**
 * AuthController.php - register, login, logout.
 */
declare(strict_types=1);

class AuthController
{
    private User $users;

    public function __construct()
    {
        $this->users = new User();
    }

    /** /?page=auth - send people where they belong. */
    public function index(): void
    {
        redirect(current_user() !== null
            ? url('dashboard')
            : url('auth', ['action' => 'login']));
    }

    public function login(): void
    {
        if (current_user() !== null) {
            redirect(url('dashboard'));
        }

        $errors = [];
        $old    = ['email' => ''];

        if (is_post()) {
            $email    = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $old['email'] = $email;

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Enter a valid email address.';
            }
            if ($password === '') {
                $errors['password'] = 'Enter your password.';
            }

            if ($errors === []) {
                $user = $this->users->findByEmail($email);

                // One generic message - never reveal which half was wrong.
                if ($user === null || !password_verify($password, $user['password_hash'])) {
                    $errors['form'] = 'Email or password is incorrect.';
                } elseif ($user['status'] !== 'active') {
                    $errors['form'] = 'This account has been disabled. Contact an administrator.';
                } else {
                    unset($user['password_hash']);
                    login_user($user);
                    respond_ok('Welcome back, ' . $user['name'] . '.', url('dashboard'));
                }
            }

            // fetch() callers get per-field errors instead of a full re-render.
            if (wants_json()) {
                $code = isset($errors['form']) ? 401 : 422;
                json_response(false, ['errors' => $errors], $errors['form'] ?? 'Please fix the errors below.', $code);
            }
        }

        view('auth/login', ['title' => 'Sign in', 'errors' => $errors, 'old' => $old]);
    }

    public function register(): void
    {
        if (current_user() !== null) {
            redirect(url('dashboard'));
        }

        $errors = [];
        $old    = ['name' => '', 'email' => ''];

        if (is_post()) {
            $name     = trim((string) ($_POST['name'] ?? ''));
            $email    = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $confirm  = (string) ($_POST['password_confirm'] ?? '');
            $old      = ['name' => $name, 'email' => $email];

            if (mb_strlen($name) < 2) {
                $errors['name'] = 'Enter your full name (at least 2 characters).';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Enter a valid email address.';
            } elseif ($this->users->emailExists($email)) {
                $errors['email'] = 'That email is already registered.';
            }
            if (strlen($password) < 8) {
                $errors['password'] = 'Use at least 8 characters.';
            }
            if ($confirm !== $password) {
                $errors['password_confirm'] = 'The two passwords do not match.';
            }

            if ($errors === []) {
                $id = $this->users->create($name, $email, password_hash($password, PASSWORD_DEFAULT));
                login_user(['id' => $id]);
                respond_ok('Account created. Welcome, ' . $name . '.', url('dashboard'));
            }

            // fetch() callers get per-field errors instead of a full re-render.
            if (wants_json()) {
                json_response(false, ['errors' => $errors], 'Please fix the errors below.', 422);
            }
        }

        view('auth/register', ['title' => 'Create account', 'errors' => $errors, 'old' => $old]);
    }

    /** Logout is POST-only so a stray link or image cannot sign you out. */
    public function logout(): void
    {
        if (!is_post()) {
            abort(405, 'Log out by submitting the form in the user menu.');
        }

        logout_user();
        flash('success', 'You have been signed out.');
        redirect(url('auth', ['action' => 'login']));
    }

    /* ----------------------------------------------------------- Google ---- */

    /** GET ?page=auth&action=google - start the OAuth authorization-code flow. */
    public function google(): void
    {
        if (!google_configured()) {
            google_log('flow start refused: not configured', google_config_state());
            flash('error',
                'Google sign-in is not available yet. This service is not configured. Please contact your administrator.',
                'Sign-in unavailable');
            redirect(url('auth', ['action' => 'login']));
        }

        // CSRF for the OAuth dance: a random state echoed back by Google.
        $state = bin2hex(random_bytes(16));
        $_SESSION['google_state'] = $state;

        google_log('flow start', [
            'state'      => $state,
            'session_id' => session_id(),
            'redirect_uri' => GOOGLE_REDIRECT_URI,
        ]);

        $query = http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);

        redirect('https://accounts.google.com/o/oauth2/v2/auth?' . $query);
    }

    /** GET ?page=auth&action=google_callback - Google sends the user back here. */
    public function googleCallback(): void
    {
        $state = (string) ($_GET['state'] ?? '');
        $code  = (string) ($_GET['code'] ?? '');
        $err   = (string) ($_GET['error'] ?? '');

        google_log('callback hit', [
            'has_state'    => $state !== '',
            'has_code'     => $code !== '',
            'google_error' => $err,
            'session_state' => $_SESSION['google_state'] ?? '(none)',
            'session_id'   => session_id(),
            'cookie_sent'  => $_COOKIE[session_name()] ?? '(none)',
        ]);

        // Google can bounce the user back with ?error=... (denied consent, etc).
        if ($err !== '') {
            google_log('google returned an error', ['error' => $err]);
            flash('error', 'Google sign-in was cancelled or denied. Please try again.');
            redirect(url('auth', ['action' => 'login']));
        }

        // The state must match the one we minted; otherwise it is someone else's flow.
        if (empty($_SESSION['google_state']) || !hash_equals((string) $_SESSION['google_state'], $state)) {
            google_log('STATE MISMATCH', [
                'expected'   => $_SESSION['google_state'] ?? '(none)',
                'received'   => $state,
                'session_id' => session_id(),
                'cookie_sent' => $_COOKIE[session_name()] ?? '(none)',
                'note'       => 'empty session_state usually means the session cookie was not sent back '
                    . '(cookie path / SameSite / domain mismatch), or the session was not persisted.',
            ]);
            unset($_SESSION['google_state']);
            flash('error', 'That sign-in attempt could not be verified. Please try again.');
            redirect(url('auth', ['action' => 'login']));
        }
        unset($_SESSION['google_state']);

        if ($code === '' || !google_configured()) {
            google_log('missing code or not configured', [
                'code'       => $code,
                'configured' => google_configured(),
            ]);
            flash('error', 'Google sign-in did not complete. Please try again.');
            redirect(url('auth', ['action' => 'login']));
        }

        $exchange = google_exchange_code($code);
        if ($exchange['token'] === null) {
            // `invalid_client` means the stored client secret is wrong, which
            // retrying can never fix - point at the configuration instead.
            flash('error', $exchange['error'] === 'invalid_client'
                ? 'Google sign-in is not configured correctly. Please contact your administrator.'
                : 'Google rejected the sign-in. Please try again.');
            redirect(url('auth', ['action' => 'login']));
        }
        $token = $exchange['token'];
        google_log('token exchange ok', ['token_len' => strlen($token)]);

        $profile = google_userinfo($token);
        if ($profile === null || empty($profile['sub']) || empty($profile['email'])) {
            google_log('userinfo failed', ['profile' => $profile]);
            flash('error', 'Could not read your Google profile.');
            redirect(url('auth', ['action' => 'login']));
        }
        google_log('userinfo ok', ['email' => $profile['email'], 'sub' => $profile['sub']]);

        /*
         * Two distinct paths after Google authentication:
         *
         *   NEW USER      - no account exists yet. We provision one (no local
         *                   password) and send them to set a password. They are
         *                   NOT logged in until they do, then must sign in again.
         *   REGISTERED    - an account exists and has already completed setup
         *                   (it has a local password). Log them straight in.
         */
        $googleId = (string) $profile['sub'];
        $email    = (string) $profile['email'];

        $user    = $this->users->findByGoogleId($googleId)
            ?? $this->users->findByEmail($email);
        $isNew   = $user === null;

        // Provision a brand-new account, or link google_id onto an existing
        // email that predates Google sign-in (that one already has a password,
        // so it is "registered" and skips straight to a session).
        if ($isNew) {
            $name = trim((string) ($profile['name'] ?? '')) ?: $email;
            $id   = $this->users->createFromGoogle($name, $email, $googleId);
            $user = $this->users->findByGoogleId($googleId) ?? $this->users->find($id);
            google_log('new user provisioned', ['id' => $id, 'email' => $email]);
        } elseif (empty($user['google_id'])) {
            $this->users->createFromGoogle((string) $user['name'], (string) $user['email'], $googleId);
            $user = $this->users->findByGoogleId($googleId) ?? $this->users->find((int) $user['id']);
            google_log('existing account linked to google', ['id' => $user['id'] ?? null]);
        }

        if ($user === null) {
            flash('error', 'Could not complete the sign-in.');
            redirect(url('auth', ['action' => 'login']));
        }
        if (($user['status'] ?? '') !== 'active') {
            flash('error', 'This account has been disabled. Contact an administrator.');
            redirect(url('auth', ['action' => 'login']));
        }

        $needsPassword = !$this->users->hasPassword((int) $user['id']);

        if ($needsPassword) {
            // --- NEW USER: collect a password first, do not start a session ---
            $this->startPasswordSetup((int) $user['id']);
            google_log('route -> set_password', ['id' => $user['id']]);
            flash('info', 'One more step: set a password for your new account.');
            redirect(url('auth', ['action' => 'set_password']));
        }

        // --- REGISTERED USER: session straight away ---
        google_log('route -> auto login', ['id' => $user['id']]);
        login_user($user);
        flash('success', 'Welcome back, ' . $user['name'] . '.');
        redirect(url('dashboard'));
    }

    /**
     * NEW-USER path, step 1. Google proved who they are, but they are not signed
     * in: we stash only their id behind a random token, and require it back.
     */
    private function startPasswordSetup(int $userId): void
    {
        $token = bin2hex(random_bytes(16));
        $_SESSION['pending_password_user']  = $userId;
        $_SESSION['pending_password_token'] = $token;
    }

    /**
     * NEW-USER path, step 2: the "set a password" screen.
     *
     * Reachable only with a valid pending token from Google. On success the
     * account gains a password, the pending state is cleared and - per the
     * requirement - the user has to sign in again rather than being logged in.
     */
    public function setPassword(): void
    {
        $userId = (int) ($_SESSION['pending_password_user'] ?? 0);
        $token  = (string) ($_SESSION['pending_password_token'] ?? '');

        // No pending Google sign-in (or it was already consumed) -> back to login.
        if ($userId <= 0 || $token === '') {
            flash('error', 'Your password setup has expired. Please sign in with Google again.');
            redirect(url('auth', ['action' => 'login']));
        }

        $user = $this->users->find($userId);
        if ($user === null) {
            unset($_SESSION['pending_password_user'], $_SESSION['pending_password_token']);
            flash('error', 'Could not find that account. Please sign in with Google again.');
            redirect(url('auth', ['action' => 'login']));
        }

        // Already has a password (e.g. a stale tab) -> just go to login.
        if ($this->users->hasPassword($userId)) {
            unset($_SESSION['pending_password_user'], $_SESSION['pending_password_token']);
            flash('info', 'Your password is already set. Please sign in.');
            redirect(url('auth', ['action' => 'login']));
        }

        $errors = [];

        if (is_post()) {
            $password = (string) ($_POST['password'] ?? '');
            $confirm  = (string) ($_POST['password_confirm'] ?? '');

            if (strlen($password) < 8) {
                $errors['password'] = 'Use at least 8 characters.';
            }
            if ($confirm !== $password) {
                $errors['password_confirm'] = 'The two passwords do not match.';
            }

            if ($errors === []) {
                $this->users->setInitialPassword($userId, password_hash($password, PASSWORD_DEFAULT));

                // Clear the pending state and force a fresh sign-in.
                unset($_SESSION['pending_password_user'], $_SESSION['pending_password_token']);
                google_log('password set - require re-login', ['id' => $userId]);

                if (wants_json()) {
                    json_response(true, ['redirect' => url('auth', ['action' => 'login'])],
                        'Password set. Please sign in with your new password.');
                }

                flash('success', 'Password set. Please sign in with your new password.');
                redirect(url('auth', ['action' => 'login']));
            }

            if (wants_json()) {
                json_response(false, ['errors' => $errors], 'Please fix the errors below.', 422);
            }
        }

        view('auth/set_password', [
            'title'  => 'Set your password',
            'user'   => $user,
            'errors' => $errors,
        ]);
    }
}
