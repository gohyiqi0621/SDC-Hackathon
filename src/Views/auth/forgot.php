<?php
/** auth/forgot.php - ask for the email address and send a reset link through Brevo. */
view('layouts/header', ['title' => 'Forgot password', 'bare' => true]);
?>
<section class="auth-card">
    <div class="auth-icon"><i class="fa-regular fa-envelope"></i></div>
    <h1>Forgot your password?</h1>
    <p class="auth-sub">
        Enter the email address on your account and we will send you a reset link.
        The link works once and expires in <?= (int) \Src\Models\PasswordReset::TTL_MINUTES ?> minutes.
    </p>

    <?php if (!$ready): ?>
        <div class="flashes">
            <div class="flash flash--warning" role="status">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <strong>Email delivery is not set up</strong>
                    <span>Password reset is unavailable until the Brevo credentials are added to the app's <code>.env</code> file.</span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('auth', ['action' => 'forgot'])) ?>" data-ajax novalidate>
        <?= csrf_field() ?>

        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" required autofocus
                   placeholder="you@example.com" value="<?= e($old['email'] ?? '') ?>"
                   <?= isset($errors['email']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
            <p class="field-error" data-error-for="email"><?= e($errors['email'] ?? '') ?></p>
        </div>

        <button class="primary-btn btn-lg btn-block" type="submit"<?= !$ready ? ' disabled' : '' ?>>
            <i class="fa-regular fa-paper-plane"></i> Send reset link
        </button>
    </form>

    <p class="auth-switch"><a href="<?= e(url('auth', ['action' => 'login'])) ?>">Back to sign in</a></p>
</section>
<?php view('layouts/footer', ['bare' => true]); ?>
