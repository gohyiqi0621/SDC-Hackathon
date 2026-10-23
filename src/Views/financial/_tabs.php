<?php
/**
 * financial/_tabs.php - the role switch on the Claims page.
 *
 * Invoice and Claims are separate sidebar items now (Sales > Invoice, Claims),
 * so there is no Invoice / Claims switcher here any more. The role switch
 * (User / Supervisor): a Supervisor can edit, approve, reject, mark paid and
 * delete; a User can view and submit.
 */
$role = finance_role();
?>
<div class="page-tabs-row">
    <form class="role-switch" method="post" action="<?= e(url('financial', ['action' => 'role'])) ?>" aria-label="Viewing as">
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e(url('financial', ['action' => 'claims'])) ?>">
        <span class="role-switch-label">Viewing as</span>
        <button type="submit" name="role" value="user" class="<?= $role === 'user' ? 'active' : '' ?>"<?= $role === 'user' ? ' aria-pressed="true"' : ' aria-pressed="false"' ?>>
            <i class="fa-solid fa-user"></i> User
        </button>
        <button type="submit" name="role" value="supervisor" class="<?= $role === 'supervisor' ? 'active' : '' ?>"<?= $role === 'supervisor' ? ' aria-pressed="true"' : ' aria-pressed="false"' ?>>
            <i class="fa-solid fa-user-shield"></i> Supervisor
        </button>
    </form>
</div>
