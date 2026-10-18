<?php /** auth/reset.php - step 2: enter the 6-digit code and choose a new password. */
view('layouts/header', ['title' => 'Reset your password', 'bare' => true]);
?>
<div class="auth__card card liquid-glass-strong">
    <div class="auth__icon"><?= icon('key') ?></div>
    <h1 class="auth__title">Choose a new password</h1>
    <p class="auth__sub">
        We sent a 6-digit code to <strong><?= e($email) ?></strong>.
        It works once and expires in <?= (int) $ttl ?> minutes.
    </p>

    <?php if ($expired): ?>
        <div class="notice notice--warn" role="status">
            <?= icon('alert') ?>
            <div>
                <strong>That code has expired</strong>
                <p>Codes are only valid for <?= (int) $ttl ?> minutes.
                   <a href="<?= e(url('auth', ['action' => 'forgot', 'email' => $email])) ?>">Send a new one</a>.</p>
            </div>
        </div>
    <?php elseif (!$ready): ?>
        <div class="notice notice--warn" role="status">
            <?= icon('alert') ?>
            <div>
                <strong>Email delivery is not set up</strong>
                <p>No code can be sent until an administrator adds the Brevo credentials on the Key page.</p>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('auth', ['action' => 'reset'])) ?>" data-ajax novalidate>
        <?= csrf_field() ?>

        <?php if (!empty($errors['form'])): ?>
            <div class="notice notice--warn" role="alert">
                <?= icon('alert') ?>
                <div><strong>Could not reset your password</strong><p><?= e($errors['form']) ?></p></div>
            </div>
        <?php endif; ?>

        <p class="field__error" data-error-for="form"></p>

        <div class="field">
            <label for="otp">Verification code</label>
            <input id="otp" name="otp" type="text" inputmode="numeric" pattern="[0-9]*"
                   maxlength="6" autocomplete="one-time-code" required autofocus
                   placeholder="000000"
                   class="otp-input<?= isset($errors['otp']) ? ' is-invalid' : '' ?>"
                   <?= isset($errors['otp']) ? 'aria-invalid="true"' : '' ?>>
            <p class="field__error" data-error-for="otp"><?= e($errors['otp'] ?? '') ?></p>
        </div>

        <div class="field">
            <label for="password">New password</label>
            <div class="input-wrap">
                <input id="password" name="password" type="password" autocomplete="new-password" required
                       <?= isset($errors['password']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="password"><?= icon('eye', 'icon--sm') ?></button>
            </div>
            <p class="field__hint"><?= e(password_policy_hint()) ?></p>
            <p class="field__error" data-error-for="password"><?= e($errors['password'] ?? '') ?></p>
        </div>

        <div class="field">
            <label for="password_confirm">Confirm new password</label>
            <div class="input-wrap">
                <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required
                       <?= isset($errors['password_confirm']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="password_confirm"><?= icon('eye', 'icon--sm') ?></button>
            </div>
            <p class="field__error" data-error-for="password_confirm"><?= e($errors['password_confirm'] ?? '') ?></p>
        </div>

        <button class="btn btn--primary btn--lg btn--block" type="submit">Set new password</button>
    </form>

    <p class="auth__switch">
        Did not get the code?
        <a href="<?= e(url('auth', ['action' => 'forgot', 'email' => $email])) ?>">Send a new one</a>
    </p>
    <p class="auth__switch" style="margin-top:6px">
        <a href="<?= e(url('auth', ['action' => 'login'])) ?>">Back to sign in</a>
    </p>
</div>
<?php view('layouts/footer', ['bare' => true]); ?>
