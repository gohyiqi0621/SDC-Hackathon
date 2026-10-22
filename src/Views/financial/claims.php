<?php
/**
 * financial/claims.php - staff expense claims and their review status.
 *
 * Anyone can view and submit; a Supervisor also sees the review, edit and
 * delete buttons.
 *
 * Expects: $claims, $total (matching filters), $allCount, $stats,
 * $filters [q, status, category, sort], $page, $pages, $perPage, $modal, $modalOpen.
 */
use Src\Models\Claim;

view('layouts/header', ['title' => 'Claims']);

$supervisor = is_supervisor();
// While the popup is editing a claim, "New claim" reloads into a blank one.
$newUrl     = url('financial', ['action' => 'claims', 'new' => 1]);
$openNew    = $modal['claim'] === null ? ' data-modal-open="#claimModal"' : '';

$filtered  = $filters['q'] !== '' || $filters['status'] !== '' || $filters['category'] !== '';
$listQuery = array_filter([
    'action'   => 'claims',
    'q'        => $filters['q'],
    'status'   => $filters['status'],
    'category' => $filters['category'],
    'sort'     => $filters['sort'] !== 'newest' ? $filters['sort'] : null,
], static fn ($v) => $v !== null && $v !== '');
$listUrl   = url('financial', $listQuery + ($page > 1 ? ['p' => $page] : []));
$from      = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
$to        = min($total, $page * $perPage);

/** Review buttons offered for each status: [action, label, icon, button class, confirm text]. */
$reviewActions = [
    'submitted'  => [
        ['approve', 'Approve', 'fa-solid fa-check', 'icon-btn--ok', null],
        ['reject',  'Reject',  'fa-solid fa-xmark', 'icon-btn--danger', 'Reject this claim? It can be reopened later.'],
    ],
    'approved'   => [
        ['reimburse', 'Mark reimbursed', 'fa-solid fa-money-bill-wave', 'icon-btn--ok', 'Mark as reimbursed? Do this once the money has been paid back. The claim is then closed.'],
        ['reject',    'Reject',          'fa-solid fa-xmark',           'icon-btn--danger', 'Reject this approved claim?'],
    ],
    'rejected'   => [
        ['reopen', 'Reopen', 'fa-solid fa-rotate-left', '', null],
    ],
    'reimbursed' => [],
];
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Claims</h1>
        <p>Staff expense claims with their receipts: submit, approve, then reimburse.</p>
    </div>
    <div class="welcome-actions">
        <a class="primary-btn" href="<?= e($newUrl) ?>"<?= $openNew ?>>
            <i class="fa-solid fa-plus"></i> New claim
        </a>
    </div>
</section>

<div class="page-body">

    <?php require __DIR__ . '/_tabs.php'; ?>

    <section class="stats-grid stats-grid--3">
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fa-solid fa-receipt"></i></div>
            <div class="stat-details"><h2><?= (int) $stats['submitted'] ?></h2><p>Waiting for review</p></div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-details">
                <h2><?= (int) $stats['approved'] ?></h2>
                <p>Approved, to pay back (<?= e(format_money($stats['owed'], 'RM')) ?>)</p>
            </div>
        </div>
        <div class="stat-card pink">
            <div class="stat-icon"><i class="fa-solid fa-wallet"></i></div>
            <div class="stat-details">
                <h2><?= (int) $stats['reimbursed'] ?></h2>
                <p>Reimbursed (<?= e(format_money($stats['reimbursed_amount'], 'RM')) ?>)</p>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>All claims</h2>
                <p>
                    <?php if ($total > 0): ?>
                        Showing <?= $from ?>–<?= $to ?> of <?= (int) $total ?><?= $filtered ? ' matching' : '' ?>
                    <?php elseif ($allCount > 0): ?>
                        Nothing matches these filters.
                    <?php else: ?>
                        No claim has been submitted yet.
                    <?php endif; ?>
                </p>
            </div>
            <span class="badge"><?= (int) $allCount ?> claim<?= $allCount === 1 ? '' : 's' ?></span>
        </div>

        <?php if ($allCount > 0): ?>
            <form class="toolbar" method="get" action="<?= e(url('financial', ['action' => 'claims'])) ?>" data-auto-submit>
                <input type="hidden" name="page" value="financial">
                <input type="hidden" name="action" value="claims">

                <div class="toolbar-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <label class="sr-only" for="q">Search</label>
                    <input id="q" name="q" type="search" placeholder="Search name, title or receipt..." value="<?= e($filters['q']) ?>">
                </div>

                <label class="sr-only" for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (Claim::STATUS_LABELS as $key => [$label]): ?>
                        <option value="<?= e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="sr-only" for="category">Category</label>
                <select id="category" name="category">
                    <option value="">All categories</option>
                    <?php foreach (Claim::CATEGORIES as $key => [$label]): ?>
                        <option value="<?= e($key) ?>"<?= $filters['category'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="sr-only" for="sort">Sort by</label>
                <select id="sort" name="sort">
                    <?php foreach (Claim::SORTS as $key => [$label]): ?>
                        <option value="<?= e($key) ?>"<?= $filters['sort'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <button class="secondary-btn" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
                <?php if ($filtered || $filters['sort'] !== 'newest'): ?>
                    <a class="toolbar-reset" href="<?= e(url('financial', ['action' => 'claims'])) ?>">Reset</a>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <?php if ($allCount === 0): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-solid fa-receipt"></i></div>
                <h3>No claims yet</h3>
                <p>When staff spend their own money for the business, submit a claim with the receipt here.</p>
                <a class="primary-btn" href="<?= e($newUrl) ?>"<?= $openNew ?>>
                    <i class="fa-solid fa-plus"></i> New claim
                </a>
            </div>

        <?php elseif ($total === 0): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                <h3>No matches</h3>
                <p>Try another search or clear the filters.</p>
                <a class="secondary-btn" href="<?= e(url('financial', ['action' => 'claims'])) ?>">Clear filters</a>
            </div>

        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Claim</th>
                            <th>Claimed by</th>
                            <th>Category</th>
                            <th>Spent on</th>
                            <th class="num">Amount</th>
                            <th>Status</th>
                            <?php if ($supervisor): ?>
                                <th class="actions"><span class="sr-only">Actions</span></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($claims as $row): ?>
                            <?php
                            [$statusLabel, $statusClass] = Claim::STATUS_LABELS[$row['status']] ?? [ucfirst((string) $row['status']), 'badge--muted'];
                            [$categoryLabel, $categoryIcon] = Claim::CATEGORIES[$row['category']] ?? [ucfirst((string) $row['category']), 'fa-solid fa-tag'];
                            ?>
                            <tr>
                                <td data-label="Claim">
                                    <strong><?= e($row['title']) ?></strong>
                                    <?php if (!empty($row['file_path'])): ?>
                                        <br><a class="file-link" href="<?= e(url('financial', ['action' => 'claim_file', 'id' => $row['id']])) ?>" target="_blank" rel="noopener">
                                            <i class="<?= $row['file_mime'] === 'application/pdf' ? 'fa-regular fa-file-pdf' : 'fa-regular fa-file-image' ?>"></i> View receipt
                                        </a>
                                    <?php else: ?>
                                        <br><small class="muted">No receipt</small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Claimed by"><?= e($row['claimant_name']) ?></td>
                                <td data-label="Category"><span class="badge badge--muted"><i class="<?= e($categoryIcon) ?>"></i> <?= e($categoryLabel) ?></span></td>
                                <td data-label="Spent on"><?= e(fmt_datetime($row['expense_date'], 'j M Y')) ?></td>
                                <td data-label="Amount" class="num"><?= e(format_money($row['amount'], $row['currency'])) ?></td>
                                <td data-label="Status">
                                    <span class="badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
                                    <?php if ($row['status'] === 'reimbursed' && $row['reimbursed_at'] !== null): ?>
                                        <br><small class="muted"><?= e(fmt_datetime($row['reimbursed_at'], 'j M Y')) ?></small>
                                    <?php endif; ?>
                                </td>
                                <?php if ($supervisor): ?>
                                <td class="actions">
                                    <?php foreach ($reviewActions[$row['status']] ?? [] as [$do, $label, $icon, $btnClass, $confirm]): ?>
                                        <form method="post" action="<?= e(url('financial', ['action' => 'claim_status'])) ?>"
                                              <?= $confirm !== null ? 'data-confirm="' . e($confirm) . '" data-confirm-title="' . e($label) . '?" data-confirm-ok="' . e($label) . '"' . ($do === 'reject' ? '' : ' data-confirm-tone="primary"') : '' ?>>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <input type="hidden" name="do" value="<?= e($do) ?>">
                                            <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                            <button class="icon-btn <?= e($btnClass) ?>" type="submit" title="<?= e($label) ?>" aria-label="<?= e($label . ' ' . $row['title']) ?>">
                                                <i class="<?= e($icon) ?>"></i>
                                            </button>
                                        </form>
                                    <?php endforeach; ?>

                                    <?php if ($row['status'] !== 'reimbursed'): ?>
                                        <a class="icon-btn" href="<?= e(url('financial', ['action' => 'claims', 'edit' => $row['id']])) ?>" title="Edit" aria-label="Edit <?= e($row['title']) ?>">
                                            <i class="fa-regular fa-pen-to-square"></i>
                                        </a>
                                    <?php endif; ?>

                                    <form method="post" action="<?= e(url('financial', ['action' => 'claim_delete'])) ?>"
                                          data-confirm="Remove the claim &quot;<?= e($row['title']) ?>&quot; and its receipt? This cannot be undone."
                                          data-confirm-title="Remove claim?" data-confirm-ok="Remove">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                        <button class="icon-btn icon-btn--danger" type="submit" title="Remove" aria-label="Remove <?= e($row['title']) ?>">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1): ?>
                <nav class="pagination" aria-label="Pages">
                    <?php if ($page > 1): ?>
                        <a href="<?= e(url('financial', $listQuery + ['p' => $page - 1])) ?>" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <?php if ($i === 1 || $i === $pages || abs($i - $page) <= 1): ?>
                            <a href="<?= e(url('financial', $listQuery + ['p' => $i])) ?>"<?= $i === $page ? ' class="active" aria-current="page"' : '' ?>><?= $i ?></a>
                        <?php elseif (abs($i - $page) === 2): ?>
                            <span>…</span>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $pages): ?>
                        <a href="<?= e(url('financial', $listQuery + ['p' => $page + 1])) ?>" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

</div>

<?php require __DIR__ . '/_claim_modal.php'; ?>
<?php view('layouts/footer'); ?>
