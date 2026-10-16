<?php /** auth/set_password.php - new Google user: choose a password before first sign-in. */
view('layouts/header', ['title' => 'Set your password', 'bare' => true]);
?>
<div class="auth__card card liquid-glass-strong">
    <div class="auth__icon"><?= icon('lock') ?></div>
    <h1 class="auth__title">Set your password</h1>
    <p class="auth__sub">
        Welcome, <?= e($user['name'] ?? $user['email'] ?? 'there') ?>. Your Google account is verified &mdash;
        choose a password to finish setting up, then sign in.
    </p>

    <form method="post" action="<?= e(url('auth', ['action' => 'set_password'])) ?>" data-ajax novalidate>
        <?= csrf_field() ?>

        <div class="field">
            <label for="password">New password</label>
            <div class="input-wrap">
                <input id="password" name="password" type="password" autocomplete="new-password" required autofocus
                       <?= isset($errors['password']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="password"><?= icon('eye', 'icon--sm') ?></button>
            </div>
            <p class="field__hint">At least 8 characters.</p>
            <p class="field__error" data-error-for="password"><?= e($errors['password'] ?? '') ?></p>
        </div>

        <div class="field">
            <label for="password_confirm">Confirm password</label>
            <div class="input-wrap">
                <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required
                       <?= isset($errors['password_confirm']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="password_confirm"><?= icon('eye', 'icon--sm') ?></button>
            </div>
            <p class="field__error" data-error-for="password_confirm"><?= e($errors['password_confirm'] ?? '') ?></p>
        </div>

        <button class="btn btn--primary btn--lg btn--block" type="submit">Save password</button>
    </form>

    <p class="auth__switch">Already have a password? <a href="<?= e(url('auth', ['action' => 'login'])) ?>">Sign in</a></p>
</div>
<?php view('layouts/footer', ['bare' => true]); ?>
