<?php
/** auth/reset.php - choose a new password with a one-time link token. */
view('layouts/header', ['title' => 'Reset your password', 'bare' => true]);
?>
<section class="auth-card">
    <div class="auth-icon"><i class="fa-solid fa-key"></i></div>
    <h1>Choose a new password</h1>

    <?php if ($valid): ?>
        <p class="auth-sub">
            This link is for <strong><?= e($email) ?></strong>.
            It works once and expires <?= (int) $ttl ?> minutes after it was sent.
        </p>

        <form method="post" action="<?= e(url('auth', ['action' => 'reset'])) ?>" data-ajax novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <?php if (!empty($errors['form'])): ?>
                <div class="flashes">
                    <div class="flash flash--error" role="alert">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <div><strong>Could not reset your password</strong><span><?= e($errors['form']) ?></span></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="field">
                <label for="password">New password</label>
                <div class="input-wrap">
                    <input id="password" name="password" type="password" autocomplete="new-password" required autofocus
                           <?= isset($errors['password']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                    <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="password"><i class="fa-regular fa-eye"></i></button>
                </div>
                <p class="field-hint"><?= e(password_policy_hint()) ?></p>
                <p class="field-error" data-error-for="password"><?= e($errors['password'] ?? '') ?></p>
            </div>

            <div class="field">
                <label for="password_confirm">Confirm new password</label>
                <div class="input-wrap">
                    <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required
                           <?= isset($errors['password_confirm']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                    <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="password_confirm"><i class="fa-regular fa-eye"></i></button>
                </div>
                <p class="field-error" data-error-for="password_confirm"><?= e($errors['password_confirm'] ?? '') ?></p>
            </div>

            <button class="primary-btn btn-lg btn-block" type="submit">
                <i class="fa-solid fa-check"></i> Set new password
            </button>
        </form>
    <?php else: ?>
        <p class="auth-sub">
            This reset link cannot be used. It may have expired, already been used,
            or been copied incorrectly. Reset links work once and expire after <?= (int) $ttl ?> minutes.
        </p>

        <a class="primary-btn btn-lg btn-block" href="<?= e(url('auth', ['action' => 'forgot'])) ?>">
            <i class="fa-regular fa-paper-plane"></i> Request a new link
        </a>
    <?php endif; ?>

    <p class="auth-switch"><a href="<?= e(url('auth', ['action' => 'login'])) ?>">Back to sign in</a></p>
</section>
<?php view('layouts/footer', ['bare' => true]); ?>
