<?php
/**
 * partials/sidebar.php - the left navigation, built from nav_items().
 *
 * Expects: $user. Groups (Financial, AI Chatbot) open automatically when one
 * of their pages is active. AI Chatbot is split into Customer and Staff. Logout is a POST form so a link cannot sign out.
 */
$current = page_key();
$prevSection = null;
?>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">

    <a class="brand" href="<?= e(url('dashboard')) ?>">
        <img src="<?= e(asset('assets/logo.webp')) ?>" alt="" width="34" height="34">
        <span><?= e(APP_NAME) ?></span>
    </a>

    <nav class="sidebar-nav">
        <?php foreach (nav_items() as $item): ?>

            <?php if ($item['group'] !== $prevSection): ?>
                <p class="nav-label"><?= e($item['group']) ?></p>
                <?php $prevSection = $item['group']; ?>
            <?php endif; ?>

            <?php if ($item['kind'] === 'link'): ?>
                <a class="nav-link<?= $current === $item['key'] ? ' active' : '' ?>"
                   href="<?= e($item['url']) ?>"<?= $current === $item['key'] ? ' aria-current="page"' : '' ?>>
                    <i class="<?= e($item['icon']) ?>"></i>
                    <span><?= e($item['label']) ?></span>
                </a>

            <?php elseif ($item['kind'] === 'group'): ?>
                <?php $open = nav_is_active($item, $current); ?>
                <div class="nav-group<?= $open ? ' open has-active' : '' ?>">
                    <button class="nav-link" type="button" data-nav-group aria-expanded="<?= $open ? 'true' : 'false' ?>">
                        <i class="<?= e($item['icon']) ?>"></i>
                        <span><?= e($item['label']) ?></span>
                        <i class="fa-solid fa-chevron-down nav-caret"></i>
                    </button>
                    <div class="nav-sub">
                        <?php foreach ($item['children'] as $child): ?>
                            <?php if (isset($child['heading'])): ?>
                                <p class="nav-sub-label"><?= e($child['heading']) ?></p>
                                <?php continue; ?>
                            <?php endif; ?>
                            <?php $active = nav_is_active($child, $current); ?>
                            <a class="nav-link<?= $active ? ' active' : '' ?>"
                               href="<?= e($child['url']) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                                <i class="<?= e($child['icon']) ?>"></i>
                                <span><?= e($child['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php elseif ($item['kind'] === 'logout'): ?>
                <form method="post" action="<?= e(url('auth', ['action' => 'logout'])) ?>">
                    <?= csrf_field() ?>
                    <button class="nav-link nav-link--logout" type="submit">
                        <i class="<?= e($item['icon']) ?>"></i>
                        <span><?= e($item['label']) ?></span>
                    </button>
                </form>
            <?php endif; ?>

        <?php endforeach; ?>
    </nav>

    <a class="sidebar-user" href="<?= e(url('profile')) ?>">
        <span class="avatar"><?= e(initials_of($user)) ?></span>
        <span>
            <strong><?= e($user['name']) ?></strong>
            <small>Member</small>
        </span>
    </a>

</aside>

<div class="sidebar-backdrop" data-nav-close></div>
