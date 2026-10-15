<?php
/**
 * layouts/header.php - dispatches to the v2 shell.
 *
 * ['bare' => true]  → layouts/auth_header.php  (centered auth panel)
 * otherwise         → layouts/app_header.php   (side nav + top bar + tab bar)
 *
 * Always pass ['title' => '...']. The v2 partials live in app_header.php /
 * auth_header.php; this file only picks one so existing call sites and
 * helpers.php's abort() keep working unchanged.
 */
$bare      = $bare ?? false;
$pageTitle = $title ?? $pageTitle ?? 'Dashboard';

if ($bare) {
    $__sdclayout = 'auth_header';
} else {
    $__sdclayout = 'app_header';
}
require __DIR__ . '/' . $__sdclayout . '.php';
