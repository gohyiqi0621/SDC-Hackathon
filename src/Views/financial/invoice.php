<?php
/**
 * financial/invoice.php - the signed-in user's invoices.
 *
 * Expects: $invoices, $total (matching filters), $allCount, $stats,
 * $filters [q, status, sort], $page, $pages, $perPage.
 */
use Src\Models\Invoice;

view('layouts/header', ['title' => 'Invoice']);

$filtered  = $filters['q'] !== '' || $filters['status'] !== '';
$listQuery = array_filter([
    'action' => 'invoice',
    'q'      => $filters['q'],
    'status' => $filters['status'],
    'sort'   => $filters['sort'] !== 'newest' ? $filters['sort'] : null,
], static fn ($v) => $v !== null && $v !== '');
$listUrl   = url('financial', $listQuery + ($page > 1 ? ['p' => $page] : []));
$from      = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
$to        = min($total, $page * $perPage);
$today     = date('Y-m-d');
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Invoice</h1>
        <p>Track every invoice, its file and whether it has been paid.</p>
    </div>
    <div class="welcome-actions">
        <a class="primary-btn" href="<?= e(url('financial', ['action' => 'upload'])) ?>">
            <i class="fa-solid fa-cloud-arrow-up"></i> Upload invoice
        </a>
    </div>
</section>

<div class="page-body">

    <?php require __DIR__ . '/_tabs.php'; ?>

    <section class="stats-grid stats-grid--3">
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fa-solid fa-file-invoice"></i></div>
            <div class="stat-details"><h2><?= (int) $stats['issued'] ?></h2><p>Issued</p></div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-details"><h2><?= (int) $stats['paid'] ?></h2><p>Paid</p></div>
        </div>
        <div class="stat-card yellow">
            <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="stat-details">
                <h2><?= e(format_money($stats['outstanding_amount'], 'RM')) ?></h2>
                <p>Outstanding (<?= (int) $stats['outstanding_count'] ?> invoice<?= $stats['outstanding_count'] === 1 ? '' : 's' ?>)</p>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2>All invoices</h2>
                <p>
                    <?php if ($total > 0): ?>
                        Showing <?= $from ?>–<?= $to ?> of <?= (int) $total ?><?= $filtered ? ' matching' : '' ?>
                    <?php elseif ($allCount > 0): ?>
                        Nothing matches these filters.
                    <?php else: ?>
                        No invoice has been added yet.
                    <?php endif; ?>
                </p>
            </div>
            <span class="badge"><?= (int) $allCount ?> invoice<?= $allCount === 1 ? '' : 's' ?></span>
        </div>

        <?php if ($allCount > 0): ?>
            <form class="toolbar" method="get" action="<?= e(url('financial', ['action' => 'invoice'])) ?>" data-auto-submit>
                <input type="hidden" name="page" value="financial">
                <input type="hidden" name="action" value="invoice">

                <div class="toolbar-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <label class="sr-only" for="q">Search</label>
                    <input id="q" name="q" type="search" placeholder="Search number, customer or file..." value="<?= e($filters['q']) ?>">
                </div>

                <label class="sr-only" for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (Invoice::STATUS_LABELS as $key => [$label]): ?>
                        <option value="<?= e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="sr-only" for="sort">Sort by</label>
                <select id="sort" name="sort">
                    <?php foreach (Invoice::SORTS as $key => [$label]): ?>
                        <option value="<?= e($key) ?>"<?= $filters['sort'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <button class="secondary-btn" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
                <?php if ($filtered || $filters['sort'] !== 'newest'): ?>
                    <a class="toolbar-reset" href="<?= e(url('financial', ['action' => 'invoice'])) ?>">Reset</a>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <?php if ($allCount === 0): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-solid fa-file-invoice"></i></div>
                <h3>No invoices yet</h3>
                <p>Upload an invoice file or type one in by hand to start tracking it.</p>
                <a class="primary-btn" href="<?= e(url('financial', ['action' => 'upload'])) ?>">
                    <i class="fa-solid fa-cloud-arrow-up"></i> Upload invoice
                </a>
            </div>

        <?php elseif ($total === 0): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-solid fa-magnifying-glass"></i></div>
                <h3>No matches</h3>
                <p>Try another search or clear the filters.</p>
                <a class="secondary-btn" href="<?= e(url('financial', ['action' => 'invoice'])) ?>">Clear filters</a>
            </div>

        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Customer / supplier</th>
                            <th>Date</th>
                            <th>Due</th>
                            <th class="num">Amount</th>
                            <th>Status</th>
                            <th class="actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $row): ?>
                            <?php
                            [$statusLabel, $statusClass] = Invoice::STATUS_LABELS[$row['status']] ?? [ucfirst((string) $row['status']), 'badge--muted'];
                            $overdue = $row['status'] !== 'paid' && $row['due_date'] !== null && $row['due_date'] < $today;
                            ?>
                            <tr>
                                <td data-label="Invoice">
                                    <strong><?= e($row['invoice_number']) ?></strong>
                                    <?php if (!empty($row['file_path'])): ?>
                                        <br><a class="file-link" href="<?= e(url('financial', ['action' => 'file', 'id' => $row['id']])) ?>" target="_blank" rel="noopener">
                                            <i class="<?= $row['file_mime'] === 'application/pdf' ? 'fa-regular fa-file-pdf' : 'fa-regular fa-file-image' ?>"></i> Open file
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Customer / supplier"><?= e($row['party_name']) ?></td>
                                <td data-label="Date"><?= e(fmt_datetime($row['issue_date'], 'j M Y')) ?></td>
                                <td data-label="Due">
                                    <?php if ($row['due_date'] === null): ?>
                                        <span class="muted">—</span>
                                    <?php else: ?>
                                        <?= e(fmt_datetime($row['due_date'], 'j M Y')) ?>
                                        <?php if ($overdue): ?><br><span class="badge badge--danger">Overdue</span><?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Amount" class="num"><?= e(format_money($row['amount'], $row['currency'])) ?></td>
                                <td data-label="Status"><span class="badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                                <td class="actions">
                                    <a class="icon-btn" href="<?= e(url('financial', ['action' => 'edit', 'id' => $row['id']])) ?>" title="Edit" aria-label="Edit <?= e($row['invoice_number']) ?>">
                                        <i class="fa-regular fa-pen-to-square"></i>
                                    </a>
                                    <form method="post" action="<?= e(url('financial', ['action' => 'delete'])) ?>"
                                          data-confirm="Remove invoice &quot;<?= e($row['invoice_number']) ?>&quot; and its file? This cannot be undone."
                                          data-confirm-title="Remove invoice?" data-confirm-ok="Remove">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                        <button class="icon-btn icon-btn--danger" type="submit" title="Remove" aria-label="Remove <?= e($row['invoice_number']) ?>">
                                            <i class="fa-regular fa-trash-can"></i>
                                        </button>
                                    </form>
                                </td>
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
<?php view('layouts/footer'); ?>
