<?php
/**
 * profile/index.php - the signed-in user's details and password.
 *
 * Expects: $user, $errors, $pwErrors, $values, $lastLogin.
 * Email is read-only: changing it safely needs a verification flow.
 */
view('layouts/header', ['title' => 'Profile']);

$joined    = fmt_datetime($user['created_at'] ?? null, 'j M Y');
$lastLogin = fmt_datetime($lastLogin ?? null, 'j M Y, H:i');
$status    = ucfirst((string) ($user['status'] ?? 'active'));
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Profile</h1>
        <p>Manage your details and the password you use to sign in.</p>
    </div>
</section>

<div class="page-body">

    <section class="panel profile-card">
        <span class="avatar avatar--lg"><?= e(initials_of($user)) ?></span>
        <div>
            <h2><?= e($user['name']) ?></h2>
            <p><?= e($user['email']) ?></p>
        </div>
        <span class="badge badge--success"><i class="fa-solid fa-circle-check"></i> <?= e($status) ?></span>
    </section>

    <div class="grid-2">

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Your details</h2>
                    <p>This is the name shown around the app.</p>
                </div>
            </div>

            <form class="panel-body" method="post" action="<?= e(url('profile', ['action' => 'update'])) ?>" data-ajax>
                <?= csrf_field() ?>

                <div class="field">
                    <label for="name">Display name</label>
                    <input id="name" name="name" type="text" maxlength="100" required
                           value="<?= e($values['name'] ?? '') ?>"
                           <?= isset($errors['name']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                    <p class="field-error" data-error-for="name"><?= e($errors['name'] ?? '') ?></p>
                </div>

                <div class="field">
                    <label for="email-readonly">Email</label>
                    <input id="email-readonly" type="email" value="<?= e($user['email']) ?>" disabled>
                    <p class="field-hint">Email cannot be changed in this portal.</p>
                </div>

                <div class="form-actions">
                    <button class="primary-btn" type="submit"><i class="fa-regular fa-floppy-disk"></i> Save changes</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Change password</h2>
                    <p>You will stay signed in on this device.</p>
                </div>
            </div>

            <form class="panel-body" method="post" action="<?= e(url('profile', ['action' => 'password'])) ?>" data-ajax>
                <?= csrf_field() ?>

                <div class="field">
                    <label for="current_password">Current password</label>
                    <div class="input-wrap">
                        <input id="current_password" name="current_password" type="password" autocomplete="current-password" required
                               <?= isset($pwErrors['current_password']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                        <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="current_password"><i class="fa-regular fa-eye"></i></button>
                    </div>
                    <p class="field-error" data-error-for="current_password"><?= e($pwErrors['current_password'] ?? '') ?></p>
                </div>

                <div class="field">
                    <label for="password">New password</label>
                    <div class="input-wrap">
                        <input id="password" name="password" type="password" autocomplete="new-password" required
                               <?= isset($pwErrors['password']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                        <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="password"><i class="fa-regular fa-eye"></i></button>
                    </div>
                    <p class="field-hint"><?= e(password_policy_hint()) ?></p>
                    <p class="field-error" data-error-for="password"><?= e($pwErrors['password'] ?? '') ?></p>
                </div>

                <div class="field">
                    <label for="password_confirm">Confirm new password</label>
                    <div class="input-wrap">
                        <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required
                               <?= isset($pwErrors['password_confirm']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                        <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="password_confirm"><i class="fa-regular fa-eye"></i></button>
                    </div>
                    <p class="field-error" data-error-for="password_confirm"><?= e($pwErrors['password_confirm'] ?? '') ?></p>
                </div>

                <div class="form-actions">
                    <button class="primary-btn" type="submit"><i class="fa-solid fa-key"></i> Update password</button>
                </div>
            </form>
        </section>

    </div>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>Account</h2>
                <p>Details about your sign-in.</p>
            </div>
        </div>
        <div class="settings-row">
            <span>Status<small>Whether you can sign in</small></span>
            <span class="badge badge--success"><?= e($status) ?></span>
        </div>
        <div class="settings-row">
            <span>Member since<small>The day this account was created</small></span>
            <span class="badge badge--muted"><?= e($joined) ?></span>
        </div>
        <div class="settings-row">
            <span>Last sign-in<small>Most recent successful login</small></span>
            <span class="badge badge--muted"><?= e($lastLogin) ?></span>
        </div>
    </section>

</div>
<?php view('layouts/footer'); ?>
