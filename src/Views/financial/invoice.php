<?php
/** Invoice list with read-only details and PDF printing. */
use Src\Models\Invoice;

view('layouts/header', ['title' => 'Invoice']);

$filtered  = $filters['q'] !== '' || $filters['status'] !== '';
$listQuery = array_filter([
    'action' => 'invoice',
    'q'      => $filters['q'],
    'status' => $filters['status'],
    'sort'   => $filters['sort'] !== 'newest' ? $filters['sort'] : null,
], static fn ($v) => $v !== null && $v !== '');
$from      = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
$to        = min($total, $page * $perPage);
$today     = date('Y-m-d');
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Invoice</h1>
        <p>View your invoices, payment status and balances, and print invoice PDFs.</p>
    </div>
</section>

<div class="page-body">

    <?php require __DIR__ . '/_tabs.php'; ?>

    <section class="stats-grid stats-grid--3">
        <div class="stat-card blue">
            <div class="stat-icon"><i class="fa-solid fa-file-invoice"></i></div>
            <div class="stat-details">
                <h2><?= (int) $stats['issued'] ?></h2>
                <p>Issued<?= $stats['drafts'] > 0 ? ' (+' . (int) $stats['drafts'] . ' draft' . ($stats['drafts'] === 1 ? '' : 's') . ')' : '' ?></p>
            </div>
        </div>
        <div class="stat-card green">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-details"><h2><?= (int) $stats['paid'] ?></h2><p>Paid in full</p></div>
        </div>
        <div class="stat-card yellow">
            <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div class="stat-details">
                <h2><?= e(format_money($stats['outstanding_amount'], 'RM')) ?></h2>
                <p>Still to collect (<?= (int) $stats['outstanding_count'] ?> invoice<?= $stats['outstanding_count'] === 1 ? '' : 's' ?>)</p>
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
                        No invoice has been created yet.
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
                    <input id="q" name="q" type="search" placeholder="Search invoice no. or customer..." value="<?= e($filters['q']) ?>">
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
                <p>Your invoices will appear here when available.</p>
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
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Due</th>
                            <th class="num">Total</th>
                            <th class="num">Balance</th>
                            <th>Status</th>
                            <th class="actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $row): ?>
                            <?php
                            [$statusLabel, $statusClass] = Invoice::STATUS_LABELS[$row['status']] ?? [ucfirst((string) $row['status']), 'badge--muted'];
                            $isDraft   = $row['status'] === 'draft';
                            $balance   = $row['status'] === 'voided' ? 0.0 : (float) $row['amount'] - (float) $row['amount_paid'];
                            $overdue   = in_array($row['status'], ['unpaid', 'partial'], true) && $row['due_date'] !== null && $row['due_date'] < $today;
                            $name      = $row['invoice_number'] ?: 'Draft';
                            ?>
                            <tr>
                                <td data-label="Invoice">
                                    <?php if ($isDraft): ?>
                                        <span class="muted"><i class="fa-regular fa-pen-to-square"></i> Draft</span>
                                    <?php else: ?>
                                        <strong><?= e($row['invoice_number']) ?></strong>
                                    <?php endif; ?>
                                    <?php if ($row['order_id'] !== null): ?>
                                        <br><small class="muted">From order <?= e($row['reference_no']) ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($row['file_path'])): ?>
                                        <br><a class="file-link" href="<?= e(url('financial', ['action' => 'file', 'id' => $row['id']])) ?>" target="_blank" rel="noopener">
                                            <i class="fa-regular fa-file"></i> Uploaded file
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Customer"><?= e($row['party_name']) ?></td>
                                <td data-label="Date"><?= e(fmt_datetime($row['issue_date'], 'j M Y')) ?></td>
                                <td data-label="Due">
                                    <?php if ($row['due_date'] === null): ?>
                                        <span class="muted">—</span>
                                    <?php else: ?>
                                        <?= e(fmt_datetime($row['due_date'], 'j M Y')) ?>
                                        <?php if ($overdue): ?><br><span class="badge badge--danger">Overdue</span><?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Total" class="num"><?= e(format_money($row['amount'], $row['currency'])) ?></td>
                                <td data-label="Balance" class="num">
                                    <?= $isDraft ? '<span class="muted">—</span>'
                                        : ($balance > 0.001 ? '<strong>' . e(format_money(number_format($balance, 2, '.', ''), $row['currency'])) . '</strong>'
                                        : '<span class="muted">' . e(format_money('0', $row['currency'])) . '</span>') ?>
                                </td>
                                <td data-label="Status"><span class="badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                                <td class="actions">
                                    <?php if (!$isDraft): ?>
                                        <a class="icon-btn" href="<?= e(url('financial', ['action' => 'print', 'id' => $row['id']])) ?>" target="_blank" rel="noopener"
                                           title="Print invoice" aria-label="Print invoice <?= e($name) ?>">
                                            <i class="fa-solid fa-print"></i>
                                        </a>
                                    <?php endif; ?>
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
