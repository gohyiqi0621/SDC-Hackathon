<?php /** auth/register.php */
view('layouts/header', ['title' => 'Create account', 'bare' => true]);
?>
<div class="auth__card card liquid-glass-strong">
    <div class="auth__icon"><?= icon('user') ?></div>
    <h1 class="auth__title">Create your account</h1>
    <p class="auth__sub">New accounts start with the <code>user</code> role.</p>

    <form method="post" action="<?= e(url('auth', ['action' => 'register'])) ?>" data-ajax novalidate>
        <?= csrf_field() ?>

        <div class="field">
            <label for="name">Full name</label>
            <input id="name" name="name" type="text" autocomplete="name" required autofocus
                   value="<?= e($old['name'] ?? '') ?>"
                   <?= isset($errors['name']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
            <p class="field__error" data-error-for="name"><?= e($errors['name'] ?? '') ?></p>
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" required
                   placeholder="you@example.com" value="<?= e($old['email'] ?? '') ?>"
                   <?= isset($errors['email']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
            <p class="field__error" data-error-for="email"><?= e($errors['email'] ?? '') ?></p>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <div class="input-wrap">
                <input id="password" name="password" type="password" autocomplete="new-password" required
                       minlength="<?= (int) password_policy()['min'] ?>"
                       <?= isset($errors['password']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="password"><?= icon('eye', 'icon--sm') ?></button>
            </div>
            <p class="field__hint"><?= e(password_hint()) ?></p>
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

        <button class="btn btn--primary btn--lg btn--block" type="submit">Create account</button>
    </form>

    <p class="auth__switch">Already registered? <a href="<?= e(url('auth', ['action' => 'login'])) ?>">Sign in</a></p>
</div>
<?php view('layouts/footer', ['bare' => true]); ?>
