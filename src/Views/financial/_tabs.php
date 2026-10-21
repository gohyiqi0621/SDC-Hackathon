<?php
/**
 * financial/_tabs.php - Invoice / Claims switcher, plus the role switch.
 *
 * The sidebar lists both pages too; this keeps them one tap away on phones.
 * The role switch (User / Supervisor) only exists on the Financial pages:
 * a Supervisor can edit, approve, reject, mark paid and delete; a User can
 * view and submit.
 */
$current = page_key();
$role    = finance_role();
$tabs    = [
    'financial/invoice' => ['Invoice', 'fa-solid fa-file-invoice', url('financial', ['action' => 'invoice'])],
    'financial/claims'  => ['Claims',  'fa-solid fa-receipt',      url('financial', ['action' => 'claims'])],
];
?>
<div class="page-tabs-row">
    <nav class="page-tabs" aria-label="Financial sections">
        <?php foreach ($tabs as $key => [$label, $icon, $href]): ?>
            <a class="page-tab<?= $current === $key ? ' active' : '' ?>" href="<?= e($href) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>>
                <i class="<?= e($icon) ?>"></i> <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <form class="role-switch" method="post" action="<?= e(url('financial', ['action' => 'role'])) ?>" aria-label="Viewing as">
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e(url('financial', ['action' => $current === 'financial/claims' ? 'claims' : 'invoice'])) ?>">
        <span class="role-switch-label">Viewing as</span>
        <button type="submit" name="role" value="user" class="<?= $role === 'user' ? 'active' : '' ?>"<?= $role === 'user' ? ' aria-pressed="true"' : ' aria-pressed="false"' ?>>
            <i class="fa-solid fa-user"></i> User
        </button>
        <button type="submit" name="role" value="supervisor" class="<?= $role === 'supervisor' ? 'active' : '' ?>"<?= $role === 'supervisor' ? ' aria-pressed="true"' : ' aria-pressed="false"' ?>>
            <i class="fa-solid fa-user-shield"></i> Supervisor
        </button>
    </form>
</div>
