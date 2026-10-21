<?php
/**
 * financial/_tabs.php - switcher between the four Financial pages.
 * The sidebar lists them too; this keeps them one tap away on phones.
 */
$current = page_key();
// Pages reached from a tab keep that tab lit (editing an invoice, the claim form).
$current = [
    'financial/edit'       => 'financial/upload',
    'financial/claim_new'  => 'financial/claims',
    'financial/claim_edit' => 'financial/claims',
][$current] ?? $current;
$tabs = [
    'financial/invoice'    => ['Invoice',        'fa-solid fa-file-invoice',   url('financial', ['action' => 'invoice'])],
    'financial/upload'     => ['Upload Invoice', 'fa-solid fa-cloud-arrow-up', url('financial', ['action' => 'upload'])],
    'financial/claims'     => ['Claims',         'fa-solid fa-receipt',        url('financial', ['action' => 'claims'])],
    'financial/settlement' => ['Settlement',     'fa-solid fa-scale-balanced', url('financial', ['action' => 'settlement'])],
];
?>
<nav class="page-tabs" aria-label="Financial sections">
    <?php foreach ($tabs as $key => [$label, $icon, $href]): ?>
        <a class="page-tab<?= $current === $key ? ' active' : '' ?>" href="<?= e($href) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>>
            <i class="<?= e($icon) ?>"></i> <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</nav>
