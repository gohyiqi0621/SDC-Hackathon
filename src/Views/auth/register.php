<?php
/** auth/register.php - create an account; the new user is signed in and sent to the dashboard. */
view('layouts/header', ['title' => 'Create account', 'bare' => true]);
?>
<section class="auth-card">
    <div class="auth-icon"><i class="fa-solid fa-user-plus"></i></div>
    <h1>Create your account</h1>
    <p class="auth-sub">Join <?= e(APP_NAME) ?> in a few seconds.</p>

    <form method="post" action="<?= e(url('auth', ['action' => 'register'])) ?>" data-ajax novalidate>
        <?= csrf_field() ?>

        <div class="field">
            <label for="name">Full name</label>
            <input id="name" name="name" type="text" autocomplete="name" required autofocus maxlength="100"
                   placeholder="Your name" value="<?= e($old['name'] ?? '') ?>"
                   <?= isset($errors['name']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
            <p class="field-error" data-error-for="name"><?= e($errors['name'] ?? '') ?></p>
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" required maxlength="190"
                   placeholder="you@example.com" value="<?= e($old['email'] ?? '') ?>"
                   <?= isset($errors['email']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
            <p class="field-error" data-error-for="email"><?= e($errors['email'] ?? '') ?></p>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <div class="input-wrap">
                <input id="password" name="password" type="password" autocomplete="new-password" required
                       placeholder="Create a password"
                       <?= isset($errors['password']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="password"><i class="fa-regular fa-eye"></i></button>
            </div>
            <p class="field-hint"><?= e(password_policy_hint()) ?></p>
            <p class="field-error" data-error-for="password"><?= e($errors['password'] ?? '') ?></p>
        </div>

        <div class="field">
            <label for="password_confirm">Confirm password</label>
            <div class="input-wrap">
                <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required
                       placeholder="Type it again"
                       <?= isset($errors['password_confirm']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="password_confirm"><i class="fa-regular fa-eye"></i></button>
            </div>
            <p class="field-error" data-error-for="password_confirm"><?= e($errors['password_confirm'] ?? '') ?></p>
        </div>

        <button class="primary-btn btn-lg btn-block" type="submit">
            <i class="fa-solid fa-user-plus"></i> Create account
        </button>

        <p class="auth-consent">
            By creating an account you agree to the <a href="<?= e(url('terms')) ?>">Terms of Service</a>
            and the <a href="<?= e(url('privacy')) ?>">Privacy Policy</a>.
        </p>
    </form>

    <p class="auth-switch">
        Already have an account? <a href="<?= e(url('auth', ['action' => 'login'])) ?>">Sign in</a>
    </p>
</section>
<?php view('layouts/footer', ['bare' => true]); ?>
