<?php
/**
 * layouts/app_header.php
 * Main application layout: sidebar + topbar + page content.
 */

$user = current_user() ?? ['name' => 'User', 'email' => ''];
$search = $search ?? false;

require __DIR__ . '/head.php';
?>

<body>
<div class="app">

    <?php require __DIR__ . '/../partials/sidebar.php'; ?>

    <div class="main">

        <?php require __DIR__ . '/../partials/topbar.php'; ?>

        <main class="page" id="content">

            <?php view('partials/flashes', [
                'flashes' => flashes()
            ]); ?>