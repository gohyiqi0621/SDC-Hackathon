<?php /** auth/forgot.php - step 1: ask for the address and mail a code. */
view('layouts/header', ['title' => 'Forgot password', 'bare' => true]);
?>
<div class="auth__card card liquid-glass-strong">
    <div class="auth__icon"><?= icon('mail') ?></div>
    <h1 class="auth__title">Forgot your password?</h1>
    <p class="auth__sub">
        Enter the email address on your account and we will send you a 6-digit code
        to choose a new password.
    </p>

    <?php if (!$ready): ?>
        <div class="notice notice--warn" role="status">
            <?= icon('alert') ?>
            <div>
                <strong>Email delivery is not set up</strong>
                <p>Password reset is unavailable until an administrator adds the Brevo credentials on the Key page.</p>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('auth', ['action' => 'forgot'])) ?>" data-ajax novalidate>
        <?= csrf_field() ?>

        <?php if (!empty($errors['form'])): ?>
            <div class="notice notice--warn" role="alert">
                <?= icon('alert') ?>
                <div><strong>Could not send the code</strong><p><?= e($errors['form']) ?></p></div>
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

        <button class="btn btn--primary btn--lg btn--block" type="submit">Send code</button>
    </form>

    <p class="auth__switch"><a href="<?= e(url('auth', ['action' => 'login'])) ?>">Back to sign in</a></p>
</div>
<?php view('layouts/footer', ['bare' => true]); ?>
