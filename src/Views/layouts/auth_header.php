<?php
/**
 * layouts/auth_header.php - the register / login / forgot / reset shell, also
 * used by the Privacy Policy and Terms ('wide' => true for a reading width).
 * Close with layouts/auth_footer.php.
 */
require __DIR__ . '/head.php';
?>
<body>

<main class="auth-page">
    <div class="auth-wrap<?= !empty($wide) ? ' auth-wrap--wide' : '' ?>">

        <a class="brand" href="<?= e(url('auth', ['action' => 'login'])) ?>" title="<?= e(APP_NAME) ?>">
            <span class="auth-brand-mark"><img class="brand__logo" src="<?= e(asset(brand_logo())) ?>" alt="<?= e(APP_NAME) ?>" width="80" height="80"></span>
            <?= brand_wordmark() ?>
        </a>

        <?php view('partials/flashes', ['flashes' => flashes()]); ?>
