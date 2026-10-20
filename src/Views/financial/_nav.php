<?php
/**
 * financial/_nav.php - section switcher for the Financial area.
 *
 * The desktop side navigation already lists these four pages; this row keeps
 * them reachable on phones, where the side navigation becomes a tab bar with
 * only the top-level destinations.
 */
$financialLinks = [
    ['key' => 'financial/invoice',    'label' => 'Invoice',        'icon' => 'invoice', 'url' => url('financial', ['action' => 'invoice'])],
    ['key' => 'financial/upload',     'label' => 'Upload Invoice', 'icon' => 'upload',  'url' => url('financial', ['action' => 'upload'])],
    ['key' => 'financial/claims',     'label' => 'Claims',         'icon' => 'claim',   'url' => url('financial', ['action' => 'claims'])],
    ['key' => 'financial/settlement', 'label' => 'Settlement',     'icon' => 'settle',  'url' => url('financial', ['action' => 'settlement'])],
];
$financialCurrent = page_key();
?>
<nav class="subnav" aria-label="Financial sections">
    <?php foreach ($financialLinks as $link): ?>
        <a class="chip<?= $financialCurrent === $link['key'] ? ' is-active' : '' ?>"
           href="<?= e($link['url']) ?>"<?= $financialCurrent === $link['key'] ? ' aria-current="page"' : '' ?>>
            <?= icon($link['icon'], 'icon--sm') ?><?= e($link['label']) ?>
        </a>
    <?php endforeach; ?>
</nav>
