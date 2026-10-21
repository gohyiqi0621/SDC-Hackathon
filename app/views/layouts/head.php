<?php
/**
 * layouts/head.php — shared <head>. Included by app_header.php and auth_header.php.
 * Expects: $pageTitle
 */
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?> &middot; <?= e(APP_NAME) ?></title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="theme-color" content="#000000">
<link rel="manifest" href="<?= e(asset('manifest.webmanifest')) ?>">
<link rel="icon" href="<?= e(asset('favicon.ico')) ?>" sizes="any">
<link rel="icon" href="<?= e(asset('icons/icon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('icons/apple-touch-icon.png')) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="SME Smart">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="format-detection" content="telephone=no">
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
  /* One theme (the landing look), so data-theme is fixed; this only flags JS. */
  document.documentElement.classList.add('js');
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap">
<link rel="stylesheet" href="https://db.onlinewebfonts.com/c/8cb707a9b8a73f8a7403336b861c3074?family=BubbledotICG-FinePos">
<link rel="stylesheet" href="<?= e(asset_versioned('css/base.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_versioned('css/layout.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_versioned('css/components.css')) ?>">
</head>
