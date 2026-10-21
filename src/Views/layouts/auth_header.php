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
            <img class="brand__logo" src="<?= e(asset(brand_logo())) ?>" alt="<?= e(APP_NAME) ?>" width="34" height="34">
            <?= brand_wordmark() ?>
        </a>

        <?php view('partials/flashes', ['flashes' => flashes()]); ?>
