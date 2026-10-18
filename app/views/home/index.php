<?php
/**
 * home/index.php - single-viewport landing page over a looping background video.
 * Standalone document: it does not use the app shell or base.css.
 * Expects: $stats, $navLinks, $signInUrl, $startUrl
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Intelligence Designed To Evolve</title>
<meta name="theme-color" content="#000000">
<link rel="icon" href="<?= e(asset('favicon.ico')) ?>" sizes="any">
<link rel="icon" href="<?= e(asset('icons/icon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('icons/apple-touch-icon.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://db.onlinewebfonts.com/c/8cb707a9b8a73f8a7403336b861c3074?family=BubbledotICG-FinePos" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
      integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A=="
      crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="<?= e(asset_versioned('css/landing.css')) ?>">
</head>
<body class="landing">

<div class="bg" aria-hidden="true">
  <video class="bg-video" autoplay muted loop playsinline>
    <source
      src="https://d8j0ntlcm91z4.cloudfront.net/user_38xzZboKViGWJOttwIXH07lWA1P/hf_20260809_012548_ef22562c-c0ae-4816-ad9d-f8922af4e6a7.mp4"
      type="video/mp4"
    />
  </video>
</div>

<div class="page">
  <header class="header">
    <a class="logo" href="<?= e(url('home')) ?>" aria-label="<?= e(APP_NAME) ?> home">
      <img src="<?= e(asset('assets/logo.webp')) ?>" alt="" width="52" height="52">
    </a>

    <nav class="nav" aria-label="Primary">
      <?php foreach ($navLinks as $link): ?>
        <a class="nav__link<?= $link['active'] ? ' is-active' : '' ?>" href="<?= e($link['href']) ?>"<?= $link['active'] ? ' aria-current="page"' : '' ?>><?= e($link['label']) ?></a>
      <?php endforeach; ?>
    </nav>

    <a class="sign-in" href="<?= e($signInUrl) ?>">Sign in</a>

    <button class="burger" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="mobile-menu">
      <span></span><span></span><span></span>
    </button>
  </header>

  <main class="hero">
    <div class="trust anim" style="--d: 0.05s">
      <span class="trust__avatar trust__avatar--1"><span><i class="fa-brands fa-microsoft" aria-hidden="true"></i></span></span>
      <span class="trust__avatar trust__avatar--2"><span><i class="fa-brands fa-amazon" aria-hidden="true"></i></span></span>
      <span class="trust__avatar trust__avatar--3"><span><i class="fa-brands fa-google" aria-hidden="true"></i></span></span>
      <span class="trust__pill">Trusted by 2000+ Enterprises</span>
    </div>

    <h1 class="headline anim">
      <span>Intelligence</span>
      <span>Designed To Evolve</span>
    </h1>

    <p class="subhead anim" style="--d: 0.28s">
      Build applications that reason, adapt and collaborate using a modular
      AI platform designed for production.
    </p>

    <a class="cta anim" style="--d: 0.4s" href="<?= e($startUrl) ?>">Get Started</a>
  </main>

  <footer class="stats">
    <?php foreach ($stats as $i => $s): ?>
      <div class="stat anim" style="--d: <?= e(number_format(0.5 + $i * 0.08, 2)) ?>s">
        <span class="stat__icon" aria-hidden="true"><?= e($s['icon']) ?></span>
        <span class="stat__value" data-target="<?= e($s['target']) ?>" data-suffix="<?= e($s['suffix']) ?>" data-decimals="<?= e($s['decimals']) ?>"><?= e(number_format((float) $s['target'], $s['decimals'], '.', '') . $s['suffix']) ?></span>
        <span class="stat__label"><?= e($s['label']) ?></span>
      </div>
    <?php endforeach; ?>
  </footer>
</div>

<div class="menu-overlay" hidden></div>
<nav class="mobile-menu" id="mobile-menu" aria-label="Mobile" hidden>
  <?php foreach ($navLinks as $i => $link): ?>
    <a class="mobile-menu__link<?= $link['active'] ? ' is-active' : '' ?>" style="--i: <?= (int) $i ?>" href="<?= e($link['href']) ?>"<?= $link['active'] ? ' aria-current="page"' : '' ?>><?= e($link['label']) ?></a>
  <?php endforeach; ?>
  <a class="mobile-menu__sign-in" style="--i: <?= count($navLinks) ?>" href="<?= e($signInUrl) ?>">Sign in</a>
</nav>

<script src="<?= e(asset_versioned('js/landing.js')) ?>"></script>
</body>
</html>
