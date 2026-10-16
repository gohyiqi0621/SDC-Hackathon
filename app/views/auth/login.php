<?php /** auth/login.php */
view('layouts/header', ['title' => 'Sign in', 'bare' => true]);
?>
<div class="auth__card card liquid-glass-strong">
    <div class="auth__icon"><?= icon('lock') ?></div>
    <h1 class="auth__title">Welcome back</h1>
    <p class="auth__sub">Sign in to continue to <?= e(APP_NAME) ?>.</p>

    <form method="post" action="<?= e(url('auth', ['action' => 'login'])) ?>" data-ajax novalidate>
        <?= csrf_field() ?>

        <?php if (!empty($errors['form'])): ?>
            <div class="notice notice--warn" role="alert">
                <?= icon('alert') ?>
                <div><strong>Could not sign in</strong><p><?= e($errors['form']) ?></p></div>
            </div>
        <?php endif; ?>

        <p class="field__error" data-error-for="form"></p>

        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" required autofocus
                   placeholder="you@example.com" value="<?= e($old['email'] ?? '') ?>"
                   <?= isset($errors['email']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
            <p class="field__error" data-error-for="email"><?= e($errors['email'] ?? '') ?></p>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <div class="input-wrap">
                <input id="password" name="password" type="password" autocomplete="current-password" required
                       placeholder="Your password"
                       <?= isset($errors['password']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="password"><?= icon('eye', 'icon--sm') ?></button>
            </div>
            <p class="field__error" data-error-for="password"><?= e($errors['password'] ?? '') ?></p>
        </div>

        <button class="btn btn--primary btn--lg btn--block" type="submit">Sign in</button>
    </form>

    <div class="auth__divider"><span>or</span></div>

    <?php $googleSvg = '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">'
        . '<path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.5 30.2 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.8 6.1C12.3 13.3 17.7 9.5 24 9.5z"/>'
        . '<path fill="#4285F4" d="M46.1 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.4c-.5 2.9-2.2 5.3-4.6 7l7.6 5.9c4.4-4.1 6.7-10.1 6.7-17.4z"/>'
        . '<path fill="#FBBC05" d="M10.4 28.7c-.5-1.5-.8-3-.8-4.7s.3-3.2.8-4.7l-7.8-6.1C.9 16.2 0 20 0 24s.9 7.8 2.6 10.8l7.8-6.1z"/>'
        . '<path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.6-5.9c-2.1 1.4-4.8 2.3-8.3 2.3-6.3 0-11.7-3.8-13.6-9.2l-7.8 6.1C6.5 42.6 14.6 48 24 48z"/>'
        . '</svg>'; ?>

    <?php if (google_configured()): ?>
        <a class="btn btn--google btn--block" href="<?= e(url('auth', ['action' => 'google'])) ?>">
            <?= $googleSvg ?>Continue with Google
        </a>
    <?php else: ?>
        <button class="btn btn--google btn--block" type="button" disabled
                title="This service is not configured yet. Please contact your administrator.">
            <?= $googleSvg ?>Continue with Google
        </button>
        <p class="field__hint" style="text-align:center">Google sign-in is not set up yet. Please contact your administrator.</p>
    <?php endif; ?>

    <p class="auth__switch">No account yet? <a href="<?= e(url('auth', ['action' => 'register'])) ?>">Create one</a></p>
</div>
<?php view('layouts/footer', ['bare' => true]); ?>
