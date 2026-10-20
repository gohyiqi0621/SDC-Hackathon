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

        <a class="brand" href="<?= e(url('auth', ['action' => 'login'])) ?>">
            <img src="<?= e(asset('assets/logo.webp')) ?>" alt="" width="34" height="34">
            <span><?= e(APP_NAME) ?></span>
        </a>

        <?php view('partials/flashes', ['flashes' => flashes()]); ?>
