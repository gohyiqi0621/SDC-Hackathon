<?php
/**
 * AuthService.php - sign-in, sign-out, session lifetime and login throttling.
 *
 * Notes on the security choices:
 *   - password_hash() / password_verify() are used everywhere; plain passwords
 *     are never stored or logged.
 *   - the session id is regenerated on sign-in and sign-out (fixation).
 *   - a missing account still runs a password_verify() against a dummy hash so
 *     response timing does not reveal whether an email is registered.
 *   - five failed attempts in a session start a 15-minute cool-down.
 */
declare(strict_types=1);

namespace Src\Services;

use Src\Models\User;

final class AuthService
{
    private const MAX_ATTEMPTS   = 5;
    private const WINDOW_SECONDS = 900;

    /** bcrypt hash of "password"; only used to equalize failed-login timing. */
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    private static ?array $cachedUser = null;
    private static bool $loaded = false;

    /** The signed-in user for this request, or null. */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$cachedUser;
        }

        self::$loaded = true;

        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $user = (new User())->find($id);

        if ($user === null || $user['status'] !== 'active') {
            self::logout();
            return null;
        }

        self::$cachedUser = $user;

        return $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * The auth guard used by AuthMiddleware and require_login().
     * Redirects rather than returning null.
     */
    public static function requireUser(): array
    {
        $user = self::user();

        if ($user === null) {
            flash('error', 'Please sign in to continue.', 'Sign in to continue');
            redirect(url('auth', ['action' => 'login']));
        }

        $reason = self::expiryReason();

        if ($reason !== null) {
            self::logout();
            flash(
                'error',
                $reason === 'idle'
                    ? 'You were signed out after a period of inactivity. Please sign in again.'
                    : 'Your session has ended. Please sign in again.',
                'Session expired'
            );
            redirect(url('auth', ['action' => 'login']));
        }

        self::touch();

        return $user;
    }

    /**
     * Check the credentials.
     *
     * @return array{ok:bool, user?:array, message?:string, code?:int}
     */
    public static function attempt(string $email, string $password): array
    {
        $throttle = self::throttleMessage();
        if ($throttle !== null) {
            return ['ok' => false, 'message' => $throttle, 'code' => 429];
        }

        $email = User::normalizeEmail($email);
        $user  = (new User())->findByEmail($email);
        $hash  = is_array($user) ? (string) $user['password_hash'] : self::DUMMY_HASH;

        $valid = password_verify($password, $hash);

        if ($user === null || !$valid) {
            self::recordFailure();
            return [
                'ok'      => false,
                'message' => 'Email or password is incorrect.',
                'code'    => 401,
            ];
        }

        if ($user['status'] !== 'active') {
            self::recordFailure();
            return [
                'ok'      => false,
                'message' => 'This account has been disabled. Contact the person who created it.',
                'code'    => 403,
            ];
        }

        // Re-hash transparently if PHP's default algorithm has moved on.
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            (new User())->updatePassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        self::login($user);
        (new User())->touchLogin((int) $user['id']);

        unset($user['password_hash']);

        return ['ok' => true, 'user' => $user];
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true); // stop session fixation on privilege change

        $_SESSION['user_id']      = (int) $user['id'];
        $_SESSION['login_at']     = time();
        $_SESSION['last_activity'] = time();

        csrf_rotate();

        unset($_SESSION['login_attempts']);

        self::$cachedUser = null;
        self::$loaded     = false;
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true); // the old id must not stay valid
        }

        self::$cachedUser = null;
        self::$loaded     = true;
    }

    /** Update the idle timer on an authenticated request. */
    public static function touch(): void
    {
        $_SESSION['last_activity'] = time();
    }

    /** null, 'idle' or 'absolute'. */
    public static function expiryReason(): ?string
    {
        if (empty($_SESSION['login_at'])) {
            $_SESSION['login_at'] = time();
        }
        if (empty($_SESSION['last_activity'])) {
            $_SESSION['last_activity'] = time();
        }

        $now = time();

        if ($now - (int) $_SESSION['last_activity'] > SESSION_IDLE_MINUTES * 60) {
            return 'idle';
        }
        if ($now - (int) $_SESSION['login_at'] > SESSION_LIFETIME_MINUTES * 60) {
            return 'absolute';
        }

        return null;
    }

    /** Current throttle message, or null when attempts are still allowed. */
    private static function throttleMessage(): ?string
    {
        $now      = time();
        $attempts = self::attempts();
        $recent   = array_values(array_filter(
            $attempts,
            static fn (int $at): bool => $at > $now - self::WINDOW_SECONDS
        ));

        $_SESSION['login_attempts'] = $recent;

        if (count($recent) < self::MAX_ATTEMPTS) {
            return null;
        }

        $oldest = min($recent);
        $wait   = max(1, ($oldest + self::WINDOW_SECONDS) - $now);
        $mins   = (int) ceil($wait / 60);

        return 'Too many failed sign-in attempts. Try again in ' . $mins
            . ($mins === 1 ? ' minute.' : ' minutes.');
    }

    private static function recordFailure(): void
    {
        $attempts = self::attempts();
        $attempts[] = time();

        // Keep the array bounded even during a long attack.
        if (count($attempts) > self::MAX_ATTEMPTS + 5) {
            $attempts = array_slice($attempts, -self::MAX_ATTEMPTS);
        }

        $_SESSION['login_attempts'] = $attempts;
    }

    /** @return array<int, int> */
    private static function attempts(): array
    {
        $attempts = $_SESSION['login_attempts'] ?? [];

        if (!is_array($attempts)) {
            return [];
        }

        return array_values(array_map('intval', $attempts));
    }
}
