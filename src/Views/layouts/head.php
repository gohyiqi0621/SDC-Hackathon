<?php
/**
 * layouts/head.php - the shared <head> for every page.
 *
 * Expects: $pageTitle. Optional: $styles (extra stylesheet paths inside public/).
 */
$pageTitle = $pageTitle ?? 'Dashboard';
$styles    = $styles ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="description" content="<?= e(APP_NAME) ?> - finance and AI assistant portal.">
    <link rel="icon" href="<?= e(asset('favicon.ico')) ?>" sizes="any">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- CSS -->
    <link rel="stylesheet" href="<?= e(asset_versioned('assets/css/app.css')) ?>">
    <?php foreach ($styles as $style): ?>
    <link rel="stylesheet" href="<?= e(asset_versioned($style)) ?>">
    <?php endforeach; ?>
</head>
