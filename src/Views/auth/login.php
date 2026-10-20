<?php
/** auth/login.php - sign in. On success the user goes straight to the dashboard. */
view('layouts/header', ['title' => 'Sign in', 'bare' => true]);
?>
<section class="auth-card">
    <div class="auth-icon"><i class="fa-solid fa-lock"></i></div>
    <h1>Welcome back</h1>
    <p class="auth-sub">Sign in to continue to <?= e(APP_NAME) ?>.</p>

    <form method="post" action="<?= e(url('auth', ['action' => 'login'])) ?>" data-ajax novalidate>
        <?= csrf_field() ?>

        <?php if (!empty($errors['form'])): ?>
            <div class="flashes">
                <div class="flash flash--error" role="alert">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <div><strong>Could not sign in</strong><span><?= e($errors['form']) ?></span></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" required autofocus
                   placeholder="you@example.com" value="<?= e($old['email'] ?? '') ?>"
                   <?= isset($errors['email']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
            <p class="field-error" data-error-for="email"><?= e($errors['email'] ?? '') ?></p>
        </div>

        <div class="field">
            <div class="field-label-row">
                <label for="password">Password</label>
                <a href="<?= e(url('auth', ['action' => 'forgot'])) ?>">Forgot password?</a>
            </div>
            <div class="input-wrap">
                <input id="password" name="password" type="password" autocomplete="current-password" required
                       placeholder="Your password"
                       <?= isset($errors['password']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="password"><i class="fa-regular fa-eye"></i></button>
            </div>
            <p class="field-error" data-error-for="password"><?= e($errors['password'] ?? '') ?></p>
        </div>

        <button class="primary-btn btn-lg btn-block" type="submit">
            <i class="fa-solid fa-arrow-right-to-bracket"></i> Sign in
        </button>
    </form>

    <p class="auth-switch">
        New here? <a href="<?= e(url('auth', ['action' => 'register'])) ?>">Create an account</a>
    </p>
</section>
<?php view('layouts/footer', ['bare' => true]); ?>
