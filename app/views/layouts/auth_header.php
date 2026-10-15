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
<main class="screen--auth">
  <a class="brand" href="<?= e(url('auth', ['action' => 'login'])) ?>" aria-label="<?= e(APP_NAME) ?> home">
    <svg class="brand__mark" aria-hidden="true"><use href="#brand-mark"/></svg>
    <span class="brand__name">SDC <em>App</em></span>
  </a>
  <?php view('layouts/flashes', ['flashes' => flashes()]); ?>
