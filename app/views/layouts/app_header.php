<?php
/**
 * layouts/app_header.php — opens the app shell (side nav, top bar, <main>).
 *
 * nav_links() drives both the side nav and the tab bar, so the two can never
 * drift apart. The notifications bell from the v2 reference is intentionally
 * absent: this project has no notifications feature.
 */
$pageTitle = $pageTitle ?? 'Dashboard';
$pageKey   = $pageKey ?? current_page_key();
$user      = current_user() ?? ['name' => 'User', 'email' => '', 'role' => 'user'];
$isAdmin   = ($user['role'] ?? '') === 'admin';
$initial   = initial_of($user);
$nav       = nav_links($user);
$home      = url('dashboard');
$profileUrl = url('profile');

require __DIR__ . '/head.php';

// Side-nav group labels, shown when the group changes while walking $nav.
$groupLabels = ['main' => 'Main', 'admin' => 'Admin', 'account' => 'Account'];
?>
<body data-kind="app">
<?php require __DIR__ . '/../partials/icons.php'; ?>
<div class="shell">

  <aside class="sidenav liquid-glass" id="sidenav">
    <a class="brand" href="<?= e($home) ?>" aria-label="<?= e(APP_NAME) ?> home">
      <svg class="brand__mark" aria-hidden="true"><use href="#brand-mark"/></svg>
      <span class="brand__name">SDC <em>App</em></span>
    </a>
    <nav class="sidenav__nav" aria-label="Main navigation">
      <?php $prevGroup = null; ?>
      <?php foreach ($nav as $link): ?>
        <?php if ($link['group'] !== $prevGroup): ?>
          <p class="sidenav__label"><?= e($groupLabels[$link['group']] ?? '') ?></p>
          <?php $prevGroup = $link['group']; ?>
        <?php endif; ?>
        <a class="sidenav__item" href="<?= e($link['url']) ?>"<?= $link['page'] === $pageKey ? ' aria-current="page"' : '' ?>><?= icon($link['icon']) ?><span><?= e($link['label']) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="sidenav__foot">
      <a class="sidenav__user" href="<?= e($profileUrl) ?>">
        <span class="avatar" aria-hidden="true"><?= e($initial) ?></span>
        <span><strong><?= e($user['name']) ?></strong><span><?= $isAdmin ? 'Administrator' : 'Member' ?></span></span>
      </a>
    </div>
  </aside>

  <div class="shell__main">
    <header class="topbar liquid-glass-strong">
      <div class="topbar__left">
        <span class="topbar__brand">
          <a class="brand" href="<?= e($home) ?>" aria-label="<?= e(APP_NAME) ?> home"><svg class="brand__mark" aria-hidden="true"><use href="#brand-mark"/></svg><span class="brand__name">SDC <em>App</em></span></a>
        </span>
        <h1 class="topbar__title"><?= e($pageTitle) ?></h1>
      </div>
      <div class="topbar__actions">
        <button class="icon-btn" type="button" data-theme-toggle aria-label="Switch theme" aria-pressed="false">
          <span class="only-light"><?= icon('moon', 'icon--sm') ?></span><span class="only-dark"><?= icon('sun', 'icon--sm') ?></span>
        </button>
<?php if (ONESIGNAL_APP_ID !== ''): ?>
        <div class="notif-bell" data-notif-bell
             data-feed-url="<?= e(url('notification', ['action' => 'feed'])) ?>"
             data-read-url="<?= e(url('notification', ['action' => 'read'])) ?>">
          <button class="icon-btn" type="button" data-notif-toggle aria-label="Notifications" aria-expanded="false" aria-haspopup="true">
            <?= icon('bell', 'icon--sm') ?>
            <span class="notif-bell__count" data-notif-count hidden>0</span>
          </button>
          <div class="notif-panel liquid-glass-strong" data-notif-panel role="menu" hidden>
            <div class="notif-panel__head"><strong>Notifications</strong></div>
            <div class="notif-panel__list" data-notif-list></div>
            <a class="notif-panel__all" href="<?= e(url('notification')) ?>">View all</a>
          </div>
        </div>
<?php endif; ?>
        <div class="user-menu" data-user-menu>
          <button class="user-btn" type="button" data-user-menu-toggle aria-expanded="false" aria-haspopup="true">
            <span class="avatar" aria-hidden="true"><?= e($initial) ?></span>
            <span class="user-name"><?= e($user['name']) ?></span><?= icon('chevron-down', 'icon--sm') ?>
          </button>
          <div class="user-dropdown liquid-glass-strong" role="menu" hidden>
            <div class="user-dropdown-head"><strong><?= e($user['name']) ?></strong><span class="muted"><?= e($user['email']) ?></span></div>
            <a role="menuitem" href="<?= e($profileUrl) ?>"><?= icon('user', 'icon--sm') ?> Profile</a>
            <form method="post" action="<?= e(url('auth', ['action' => 'logout'])) ?>" data-confirm-submit data-confirm-title="Log out?" data-confirm-text="You will need to sign in again.">
              <?= csrf_field() ?>
              <button type="submit" role="menuitem" class="is-danger"><?= icon('logout', 'icon--sm') ?> Log out</button>
            </form>
          </div>
        </div>
      </div>
    </header>

    <main class="content" id="content">
      <nav class="crumbs" aria-label="Breadcrumb"><a href="<?= e($home) ?>">Home</a><span aria-hidden="true">/</span><span aria-current="page"><?= e($pageTitle) ?></span></nav>
      <?php view('layouts/flashes', ['flashes' => flashes()]); ?>
