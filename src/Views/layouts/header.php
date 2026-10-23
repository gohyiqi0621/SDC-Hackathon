<?php
/**
 * layouts/header.php - opens the right page shell.
 *
 *   view('layouts/header', ['title' => 'Sign in', 'bare' => true]);   // auth screen
 *   view('layouts/header', ['title' => 'Invoice']);                   // app (sidebar + topbar)
 *
 * Optional: 'styles' => ['assets/css/x.css'], 'search' => true (topbar search box).
 * Close it with layouts/footer.php using the same 'bare' value.
 */


$bare      = $bare ?? false;
$pageTitle = $title ?? $pageTitle ?? 'Dashboard';

require __DIR__ . '/' . ($bare ? 'auth_header' : 'app_header') . '.php';
