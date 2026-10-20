<?php
/**
 * layouts/app_header.php - opens the signed-in shell: sidebar, topbar, page.
 * Close with layouts/app_footer.php.
 */
$user    = current_user() ?? ['name' => 'User', 'email' => ''];
$search  = $search ?? false;

require __DIR__ . '/head.php';
?>
<body>

<div class="app">

    <?php require __DIR__ . '/../partials/sidebar.php'; ?>

    <div class="main">

        <?php require __DIR__ . '/../partials/topbar.php'; ?>

        <main class="page" id="content">

            <?php view('partials/flashes', ['flashes' => flashes()]); ?>
