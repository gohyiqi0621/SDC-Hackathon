<?php
/**
 * layouts/auth_header.php - the register / login / forgot / reset shell.
 * Close with layouts/auth_footer.php.
 */
require __DIR__ . '/head.php';
?>
<body>

<main class="auth-page">
    <div class="auth-wrap">

        <a class="brand" href="<?= e(url('auth', ['action' => 'login'])) ?>" title="<?= e(APP_NAME) ?>">
            <span class="auth-brand-mark"><img class="brand__logo" src="<?= e(asset(brand_logo())) ?>" alt="<?= e(APP_NAME) ?>" width="80" height="80"></span>
            <?= brand_wordmark() ?>
        </a>

        <?php view('partials/flashes', ['flashes' => flashes()]); ?>
