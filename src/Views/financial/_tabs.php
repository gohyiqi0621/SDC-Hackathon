<?php
/**
 * financial/_tabs.php - switcher between the four Financial pages.
 * The sidebar lists them too; this keeps them one tap away on phones.
 */
$current = page_key();
$tabs = [
    'financial/invoice'    => ['Invoice',        'fa-solid fa-file-invoice',   url('financial', ['action' => 'invoice'])],
    'financial/claims'     => ['Claims',         'fa-solid fa-receipt',        url('financial', ['action' => 'claims'])],
    'financial/settlement' => ['Settlement',     'fa-solid fa-scale-balanced', url('financial', ['action' => 'settlement'])],
    'financial/upload'     => ['Upload Invoice', 'fa-solid fa-cloud-arrow-up', url('financial', ['action' => 'upload'])],
];
?>
<nav class="page-tabs" aria-label="Financial sections">
    <?php foreach ($tabs as $key => [$label, $icon, $href]): ?>
        <a class="page-tab<?= $current === $key ? ' active' : '' ?>" href="<?= e($href) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>>
            <i class="<?= e($icon) ?>"></i> <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</nav>
