<?php
/**
 * admin/index.php - user list with role and status control. Admins only:
 * the controller calls require_admin() before this view is ever reached.
 */
view('layouts/header', ['title' => 'Admin']);

$policy = password_policy();
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title">Users</h2>
        <p class="page-head__sub">Create accounts and choose who is an admin.</p>
    </div>
    <div class="page-head__actions">
        <div class="seg" role="navigation" aria-label="Admin sections">
            <a class="seg__btn is-active" href="<?= e(url('admin')) ?>">Users</a>
            <a class="seg__btn" href="<?= e(url('key')) ?>">API keys</a>
        </div>
        <button class="btn btn--primary" type="button" data-open-modal="#user-dialog">
            <?= icon('plus', 'icon--sm') ?> Add user
        </button>
    </div>
</div>

<section class="stat-grid" aria-label="Summary">
    <article class="stat">
        <span class="stat__icon"><?= icon('users') ?></span>
        <p class="stat__label">Accounts</p>
        <p class="stat__value" data-countup><?= e($stats['users']) ?></p>
        <p class="stat__hint"><?= e($stats['admins']) ?> administrator<?= $stats['admins'] === 1 ? '' : 's' ?></p>
    </article>
    <article class="stat">
        <span class="stat__icon"><?= icon('alert') ?></span>
        <p class="stat__label">Disabled</p>
        <p class="stat__value" data-countup><?= e($stats['disabled']) ?></p>
        <p class="stat__hint">Cannot sign in</p>
    </article>
    <article class="stat">
        <span class="stat__icon"><?= icon('items') ?></span>
        <p class="stat__label">Items</p>
        <p class="stat__value" data-countup><?= e($stats['items']) ?></p>
        <p class="stat__hint">Across all accounts</p>
    </article>
    <article class="stat">
        <span class="stat__icon"><?= icon('sparkle') ?></span>
        <p class="stat__label">AI calls</p>
        <p class="stat__value" data-countup><?= e($stats['ai']) ?></p>
        <p class="stat__hint">Across all accounts</p>
    </article>
</section>

<section class="card card--flush">
    <div class="toolbar" style="padding:var(--sp-5) var(--sp-5) 0">
        <form class="search" method="get" action="<?= e(APP_URL . '/') ?>" role="search">
            <input type="hidden" name="page" value="admin">
            <label class="sr-only" for="q">Search users</label>
            <?= icon('search') ?>
            <input id="q" name="q" type="search" value="<?= e($search) ?>"
                   placeholder="Search name or email&hellip;" aria-label="Search users" autocomplete="off">
        </form>
        <?php if ($search !== ''): ?>
            <a class="chip" href="<?= e(url('admin')) ?>"><?= icon('x', 'icon--sm') ?> Clear search</a>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">User</th>
                    <th scope="col">Items</th>
                    <th scope="col">AI calls</th>
                    <th scope="col">Role</th>
                    <th scope="col">Status</th>
                    <th scope="col">Joined</th>
                    <th scope="col"><span class="sr-only">Save</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result['rows'] === []): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty">
                                <span class="empty__icon"><?= icon('users') ?></span>
                                <h3>No accounts found</h3>
                                <p>No accounts match that search.</p>
                                <a class="btn" href="<?= e(url('admin')) ?>">Clear</a>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($result['rows'] as $row): ?>
                        <?php $isSelf = (int) $row['id'] === (int) $user['id']; ?>
                        <tr>
                            <td>
                                <div class="table__title">
                                    <span class="avatar" aria-hidden="true"><?= e(strtoupper(mb_substr((string) $row['name'], 0, 1))) ?></span>
                                    <span>
                                        <span><?= e($row['name']) ?></span>
                                        <small><?= e($row['email']) ?></small>
                                    </span>
                                </div>
                            </td>
                            <td class="muted"><?= e($row['items_count']) ?></td>
                            <td class="muted"><?= e($row['ai_count']) ?></td>
                            <td>
                                <span class="badge <?= $row['role'] === 'admin' ? 'badge--accent' : '' ?>"><?= e($row['role']) ?></span>
                            </td>
                            <td>
                                <span class="badge <?= $row['status'] === 'active' ? 'badge--ok' : 'badge--danger' ?> badge--dot"><?= e($row['status']) ?></span>
                            </td>
                            <td class="muted"><?= e(date('j M Y', strtotime((string) $row['created_at']))) ?></td>
                            <td>
                                <?php if ($isSelf): ?>
                                    <span class="badge badge--accent">This is you</span>
                                <?php else: ?>
                                    <form class="row-actions" method="post" action="<?= e(url('admin', ['action' => 'update'])) ?>" data-ajax>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">

                                        <label class="sr-only" for="role-<?= (int) $row['id'] ?>">Role</label>
                                        <select id="role-<?= (int) $row['id'] ?>" name="role">
                                            <?php foreach (User::ROLES as $role): ?>
                                                <option value="<?= e($role) ?>"<?= $row['role'] === $role ? ' selected' : '' ?>>
                                                    <?= e(ucfirst($role)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <label class="sr-only" for="status-<?= (int) $row['id'] ?>">Status</label>
                                        <select id="status-<?= (int) $row['id'] ?>" name="status">
                                            <?php foreach (User::STATUSES as $status): ?>
                                                <option value="<?= e($status) ?>"<?= $row['status'] === $status ? ' selected' : '' ?>>
                                                    <?= e(ucfirst($status)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button class="btn btn--sm btn--primary" type="submit">Save</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php view('partials/pagination', [
        'page'  => $result['page'],
        'pages' => $result['pages'],
        'total' => $result['total'],
        'query' => array_filter(['q' => $search], static fn($v) => $v !== '') + ['page' => 'admin'],
        'label' => 'accounts',
    ]); ?>
</section>

<section class="card" aria-labelledby="policy-title">
    <div class="card__head">
        <div>
            <h3 id="policy-title">Password policy</h3>
            <p>Applies wherever a password is created: sign-up, invited first sign-in and password changes.</p>
        </div>
    </div>

    <form method="post" action="<?= e(url('admin', ['action' => 'policy'])) ?>" data-ajax>
        <?= csrf_field() ?>

        <div class="field">
            <label for="password_min_chars">Minimum characters</label>
            <input id="password_min_chars" name="password_min_chars" type="number" inputmode="numeric"
                   min="4" max="64" value="<?= (int) $policy['min'] ?>" required>
            <p class="field__hint">Between 4 and 64.</p>
            <p class="field__error" data-error-for="password_min_chars"></p>
        </div>

        <label class="check">
            <input type="checkbox" name="password_require_special" value="1"<?= $policy['special'] ? ' checked' : '' ?>>
            <span>Require a special symbol<small>At least one character that is not a letter or digit.</small></span>
        </label>

        <label class="check">
            <input type="checkbox" name="password_require_case" value="1"<?= $policy['case'] ? ' checked' : '' ?>>
            <span>Require upper and lower case<small>At least one lowercase and one uppercase letter.</small></span>
        </label>

        <div class="form-actions">
            <button class="btn btn--primary" type="submit">Save policy</button>
        </div>
    </form>
</section>

<dialog class="modal" id="user-dialog" aria-labelledby="user-dialog-title">
    <form class="modal__panel" method="post" action="<?= e(url('admin', ['action' => 'create'])) ?>" data-ajax>
        <?= csrf_field() ?>
        <div class="modal__head">
            <h3 id="user-dialog-title">Add user</h3>
            <p>They can sign in straight away with this password.</p>
        </div>
        <div class="field">
            <label for="u-name">Name</label>
            <input id="u-name" name="name" type="text" maxlength="100" required>
            <p class="field__error" data-error-for="name"></p>
        </div>
        <div class="field">
            <label for="u-email">Email</label>
            <input id="u-email" name="email" type="email" required>
            <p class="field__error" data-error-for="email"></p>
        </div>
        <div class="field-row">
            <div class="field">
                <label for="u-pass">Password</label>
                <input id="u-pass" name="password" type="password" autocomplete="new-password" required
                       minlength="<?= (int) $policy['min'] ?>">
                <p class="field__hint"><?= e(password_hint()) ?></p>
                <p class="field__error" data-error-for="password"></p>
            </div>
            <div class="field">
                <label for="u-role">Role</label>
                <select id="u-role" name="role">
                    <?php foreach (User::ROLES as $role): ?>
                        <option value="<?= e($role) ?>"<?= $role === 'user' ? ' selected' : '' ?>><?= e(ucfirst($role)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="modal__foot">
            <button class="btn" type="button" data-close-modal>Cancel</button>
            <button class="btn btn--primary" type="submit">Create user</button>
        </div>
    </form>
</dialog>

<?php view('layouts/footer', ['scripts' => [asset('js/api.js')]]); ?>
