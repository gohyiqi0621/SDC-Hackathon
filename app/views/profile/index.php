<?php
/**
 * profile/index.php - edit your own name and password.
 * Every form is data-ajax, so api.js shows the validation messages in place.
 * Markup follows the v2 design system exactly (profile-card + split + settings groups).
 */
view('layouts/header', ['title' => 'Profile']);

$isGoogleOnly = ($user['google_id'] ?? null) !== null && ($user['password_hash'] ?? '') === '';
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title">Profile</h2>
        <p class="page-head__sub">Manage your details, password and appearance.</p>
    </div>
</div>

<section class="profile-card">
    <span class="avatar avatar--lg" aria-hidden="true"><?= e(initial_of($user)) ?></span>
    <div class="profile-card__info">
        <h2><?= e($user['name']) ?></h2>
        <p><?= e($user['email']) ?></p>
    </div>
    <span class="badge badge--accent badge--dot"><?= e(ucfirst((string) $user['role'])) ?></span>
</section>

<div class="split">
    <section class="card">
        <div class="card__head">
            <div>
                <h3>Your details</h3>
                <p>This is the name shown around the app.</p>
            </div>
        </div>

        <form method="post" action="<?= e(url('profile', ['action' => 'update'])) ?>" data-ajax>
            <?= csrf_field() ?>

            <div class="field">
                <label for="name">Display name</label>
                <input id="name" name="name" type="text" maxlength="100" required
                       value="<?= e($values['name'] ?? '') ?>"
                       <?= isset($errors['name']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                <p class="field__error" data-error-for="name"><?= e($errors['name'] ?? '') ?></p>
            </div>

            <div class="field">
                <label for="email-readonly">Email</label>
                <input id="email-readonly" type="email" value="<?= e($user['email']) ?>" disabled>
                <p class="field__hint">Email cannot be changed in this frame.</p>
            </div>

            <div class="form-actions">
                <button class="btn btn--primary" type="submit">Save changes</button>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="card__head">
            <div>
                <h3>Change password</h3>
                <p>
                    <?php if ($isGoogleOnly): ?>
                        You signed in with Google. Set a password below if you also want to sign in with email.
                    <?php else: ?>
                        You will stay signed in on this device.
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <form method="post" action="<?= e(url('profile', ['action' => 'password'])) ?>" data-ajax>
            <?= csrf_field() ?>

            <div class="field">
                <label for="current_password">Current password</label>
                <input id="current_password" name="current_password" type="password"
                       autocomplete="current-password" required
                       <?= isset($pwErrors['current_password']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                <p class="field__error" data-error-for="current_password"><?= e($pwErrors['current_password'] ?? '') ?></p>
            </div>

            <div class="field">
                <label for="password">New password</label>
                <div class="input-wrap">
                    <input id="password" name="password" type="password"
                           autocomplete="new-password" required
                           <?= isset($pwErrors['password']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                    <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="password"><?= icon('eye', 'icon--sm') ?></button>
                </div>
                <p class="field__hint">At least 8 characters.</p>
                <p class="field__error" data-error-for="password"><?= e($pwErrors['password'] ?? '') ?></p>
            </div>

            <div class="field">
                <label for="password_confirm">Confirm new password</label>
                <div class="input-wrap">
                    <input id="password_confirm" name="password_confirm" type="password"
                           autocomplete="new-password" required
                           <?= isset($pwErrors['password_confirm']) ? 'class="is-invalid" aria-invalid="true"' : '' ?>>
                    <button class="input-btn" type="button" aria-label="Show password" data-toggle-password="password_confirm"><?= icon('eye', 'icon--sm') ?></button>
                </div>
                <p class="field__error" data-error-for="password_confirm"><?= e($pwErrors['password_confirm'] ?? '') ?></p>
            </div>

            <div class="form-actions">
                <button class="btn btn--primary" type="submit">Update password</button>
            </div>
        </form>
    </section>
</div>

<section class="settings-group">
    <h3 class="settings-group__title">Appearance</h3>
    <label class="settings-row">
        <span>Dark mode<small>Follows your device until you change it here.</small></span>
        <input class="switch" type="checkbox" role="switch" data-theme-switch aria-label="Dark mode">
    </label>
</section>

<?php if (ONESIGNAL_APP_ID !== ''): ?>
<section class="settings-group">
    <h3 class="settings-group__title">Notifications</h3>
    <label class="settings-row">
        <span>Push notifications<small>Get notified on this device when something is sent to you.</small></span>
        <input class="switch" type="checkbox" role="switch" data-push-switch aria-label="Push notifications">
    </label>
    <p class="field__hint" data-push-hint>Turn this on to let your browser ask for permission.</p>
</section>
<?php endif; ?>

<section class="settings-group">
    <h3 class="settings-group__title">Your activity</h3>
    <div class="settings-row">
        <span>Items<small>Everything you have created</small></span>
        <span class="badge"><?= e($stats['items']) ?></span>
    </div>
    <div class="settings-row">
        <span>AI calls<small>Messages sent to the assistant</small></span>
        <span class="badge"><?= e($stats['ai']) ?></span>
    </div>
    <div class="settings-row">
        <span>Role<small>Determines what you can reach</small></span>
        <span class="badge badge--accent"><?= e(ucfirst((string) $user['role'])) ?></span>
    </div>
    <div class="settings-row">
        <span>Joined<small>Member since</small></span>
        <span class="badge"><?= e(date('j M Y', strtotime((string) $user['created_at']))) ?></span>
    </div>
</section>

<?php view('layouts/footer', ['scripts' => [asset('js/api.js')]]); ?>
