<?php
/**
 * items/index.php - the scoped, filtered, paginated item list.
 * $scoped is true for a normal user (own items only); false for an admin.
 */
view('layouts/header', ['title' => 'Items']);

$statusBadge = static function (string $status): string {
    $map = ['active' => 'badge--ok', 'draft' => 'badge--warn', 'archived' => ''];
    return $map[$status] ?? '';
};

$filtered = $search !== '' || $status !== '';
$columns  = $scoped ? 4 : 5;

// Filters are carried into the pagination links.
$query = array_filter(['q' => $search, 'status' => $status], static fn($v) => $v !== '');
$query += ['page' => 'items'];
?>
<div class="page-head page-head--inline">
    <div>
        <h2 class="page-head__title">Items</h2>
        <p class="page-head__sub">
            <?php if ($scoped): ?>
                Showing the items you own.
            <?php else: ?>
                Showing every item, from all accounts.
            <?php endif; ?>
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn--primary" href="<?= e(url('items', ['action' => 'create'])) ?>">
            <?= icon('plus', 'icon--sm') ?> New item
        </a>
    </div>
</div>

<div class="toolbar">
    <form class="search" method="get" action="<?= e(APP_URL . '/') ?>" role="search">
        <input type="hidden" name="page" value="items">
        <?php if ($status !== ''): ?>
            <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>
        <label class="sr-only" for="q">Search items</label>
        <?= icon('search') ?>
        <input id="q" name="q" type="search" value="<?= e($search) ?>"
               placeholder="Search title or description&hellip;" aria-label="Search items" autocomplete="off">
    </form>

    <div class="seg" role="group" aria-label="Filter by status">
        <a class="seg__btn<?= $status === '' ? ' is-active' : '' ?>" href="<?= e(url('items')) ?>">All</a>
        <?php foreach (Item::STATUSES as $option): ?>
            <a class="seg__btn<?= $status === $option ? ' is-active' : '' ?>"
               href="<?= e(url('items', ['status' => $option])) ?>"><?= e(ucfirst($option)) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($filtered): ?>
        <a class="chip" href="<?= e(url('items')) ?>"><?= icon('x', 'icon--sm') ?> Clear filters</a>
    <?php endif; ?>
</div>

<section class="card card--flush">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">Title</th>
                    <th scope="col">Status</th>
                    <?php if (!$scoped): ?><th scope="col">Owner</th><?php endif; ?>
                    <th scope="col">Updated</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result['rows'] === []): ?>
                    <tr>
                        <td colspan="<?= $columns ?>">
                            <div class="empty">
                                <span class="empty__icon"><?= icon('items') ?></span>
                                <h3><?= $filtered ? 'No matches' : 'No items yet' ?></h3>
                                <p><?= $filtered ? 'No items match those filters.' : 'Create the first one and it will show up here.' ?></p>
                                <?php if ($filtered): ?>
                                    <a class="btn" href="<?= e(url('items')) ?>">Clear filters</a>
                                <?php else: ?>
                                    <a class="btn btn--primary" href="<?= e(url('items', ['action' => 'create'])) ?>">Create the first one</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($result['rows'] as $row): ?>
                        <tr>
                            <td>
                                <div class="table__title">
                                    <span>
                                        <span><?= e($row['title']) ?></span>
                                        <?php if ((string) ($row['description'] ?? '') !== ''): ?>
                                            <small><?= e($row['description']) ?></small>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $statusBadge((string) $row['status']) ?> badge--dot"><?= e($row['status']) ?></span>
                            </td>
                            <?php if (!$scoped): ?>
                                <td class="muted"><?= e($row['owner_name']) ?></td>
                            <?php endif; ?>
                            <td class="muted"><?= e(date('j M Y', strtotime((string) $row['updated_at']))) ?></td>
                            <td>
                                <span class="row-actions">
                                    <a class="icon-btn icon-btn--flat"
                                       href="<?= e(url('items', ['action' => 'edit', 'id' => $row['id']])) ?>"
                                       aria-label="Edit <?= e($row['title']) ?>">
                                        <?= icon('edit', 'icon--sm') ?>
                                    </a>
                                    <form method="post" action="<?= e(url('items', ['action' => 'destroy'])) ?>"
                                          data-confirm-submit
                                          data-confirm-title="Delete item?"
                                          data-confirm-text="&ldquo;<?= e($row['title']) ?>&rdquo; will be removed permanently.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button class="icon-btn icon-btn--flat" type="submit"
                                                aria-label="Delete <?= e($row['title']) ?>">
                                            <?= icon('trash', 'icon--sm') ?>
                                        </button>
                                    </form>
                                </span>
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
        'query' => $query,
        'label' => 'items',
    ]); ?>
</section>

<?php view('layouts/footer'); // api.js is already loaded by app_footer ?>
