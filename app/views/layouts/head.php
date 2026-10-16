<?php
/**
 * layouts/head.php — shared <head>. Included by app_header.php and auth_header.php.
 * Expects: $pageTitle
 */
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!doctype html>
<html lang="en" data-theme="light"<?= isset($navIndex) ? ' data-nav-index="' . (int) $navIndex . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?> &middot; <?= e(APP_NAME) ?></title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<!-- Browser chrome follows the OS theme; the in-app toggle repaints the page.
     When launched from a home-screen icon these hide the browser bar. -->
<meta name="theme-color" content="#f5f5f7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<!-- Google Search Console ownership verification. Do not remove, even after
     verification succeeds - Google re-checks it. -->
<meta name="google-site-verification" content="B6dq_TxSaY1iPwUdC36KZTmOsyEcmipegbL6RCHNGiA">
<?php if (ONESIGNAL_APP_ID !== '' && current_user() !== null): ?>
<?php
/**
 * The OneSignal SDK resolves the worker as `path` + '/' + `serviceWorkerPath`,
 * defaulting both to the ORIGIN root. On an app served from a subdirectory
 * (/sdc-project/public) that requests /OneSignalSDKWorker.js, which 404s - and a
 * missing worker means no push subscription is ever created. The SDK ignores
 * `serviceWorkerPath` unless `path` is also given, so emit both pieces split on
 * the app directory, plus the scope (the app directory itself).
 */
$basePath  = rtrim((string) parse_url(APP_URL, PHP_URL_PATH), '/');   // /sdc-project/public
$workerUrl = asset('OneSignalSDKWorker.js');
?>
<meta name="onesignal-app-id" content="<?= e(ONESIGNAL_APP_ID) ?>"
      data-user-id="<?= e(onesignal_external_id((int) current_user()['id'])) ?>"
      data-user-role="<?= e((string) current_user()['role']) ?>"
      data-worker-path="<?= e($basePath) ?>"
      data-worker-file="OneSignalSDKWorker.js"
      data-worker-scope="<?= e($basePath) ?>/">
<?php endif; ?>
<script>
  /* Runs before first paint: saved theme, else the OS preference. No flash.
     Also arms the cross-page slide direction: the previous tab index (set by
     main.js) vs this page's data-nav-index (set by app_header.php). */
  (function(){var t;try{t=localStorage.getItem('theme')}catch(e){}
  if(!t){t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}
  document.documentElement.dataset.theme=t;document.documentElement.classList.add('js');
  var p=null;try{p=sessionStorage.getItem('tabbar-prev')}catch(e){}
  var n=document.documentElement.getAttribute('data-nav-index');
  if(p!==null&&n!==null){document.documentElement.style.setProperty('--push-dir',parseInt(n,10)>parseInt(p,10)?'1':'-1')}})();
</script>
<link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/layout.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/components.css')) ?>">
</head>
