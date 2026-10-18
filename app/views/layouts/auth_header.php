<?php
/**
 * layouts/auth_header.php — login / register shell (no side nav, no tab bar).
 * Expects $pageTitle. Close with layouts/auth_footer.php.
 */
$pageTitle = $pageTitle ?? 'Sign in';

require __DIR__ . '/head.php';
?>
<body data-kind="auth">
<?php require __DIR__ . '/../partials/icons.php'; ?>
<?php /* Same looping video as the landing page, behind a scrim so forms stay readable. */ ?>
<div class="auth-bg" aria-hidden="true">
  <video class="auth-bg__video" autoplay muted loop playsinline>
    <source src="https://d8j0ntlcm91z4.cloudfront.net/user_38xzZboKViGWJOttwIXH07lWA1P/hf_20260809_012548_ef22562c-c0ae-4816-ad9d-f8922af4e6a7.mp4" type="video/mp4">
  </video>
</div>
<main class="screen--auth">
  <a class="brand" href="<?= e(url('home')) ?>" aria-label="<?= e(APP_NAME) ?> home">
    <span class="brand__mark" aria-hidden="true"><img src="<?= e(asset('assets/logo.webp')) ?>" alt="" width="52" height="52"></span>
    <span class="brand__name">SDC <em>App</em></span>
  </a>
  <?php view('layouts/flashes', ['flashes' => flashes()]); ?>
