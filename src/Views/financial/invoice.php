<?php
/**
 * financial/invoice.php - the business's invoices to its customers.
 *
 * Anyone creates invoices (from Products & Services), continues drafts, and
 * prints invoices and receipts. A Supervisor also records payments, edits an
 * unpaid invoice with no payments, deletes drafts / unpaid ones, and sets SST.
 *
 * Expects: $invoices, $total (matching filters), $allCount, $stats,
 * $filters [q, status, sort], $page, $pages, $perPage, $modal, $modalOpen,
 * $pay (payments popup or null), $products, $customers, $finance.
 */
use Src\Models\Invoice;

view('layouts/header', ['title' => 'Invoice']);

$supervisor = is_supervisor();
// While the popup is editing an invoice, "Create invoice" reloads into a blank one.
$newUrl     = url('financial', ['action' => 'invoice', 'new' => 1]);
$openNew    = $modal['invoice'] === null && empty($modal['values']['order_id']) ? ' data-modal-open="#invoiceModal"' : '';

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
$sstLabel  = $finance['sst_registered'] ? 'SST ' . rtrim(rtrim($finance['sst_rate'], '0'), '.') . '%' : 'No SST';
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Invoice</h1>
        <p>Bill your customers from your Products &amp; Services, then record their payments and issue receipts.</p>
    </div>
    <div class="welcome-actions">
        <?php if ($supervisor): ?>
            <button class="secondary-btn" type="button" data-modal-open="#sstModal" title="SST charged on new invoices">
                <i class="fa-solid fa-percent"></i> <?= e($sstLabel) ?>
            </button>
        <?php endif; ?>
        <a class="primary-btn" href="<?= e($newUrl) ?>"<?= $openNew ?>>
            <i class="fa-solid fa-plus"></i> Create invoice
        </a>
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
                <p>Create an invoice for a customer by ticking the products or services they bought.</p>
                <a class="primary-btn" href="<?= e($newUrl) ?>"<?= $openNew ?>>
                    <i class="fa-solid fa-plus"></i> Create invoice
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
                            $balance   = (float) $row['amount'] - (float) $row['amount_paid'];
                            $overdue   = in_array($row['status'], ['unpaid', 'partial'], true) && $row['due_date'] !== null && $row['due_date'] < $today;
                            $noPayment = (float) $row['amount_paid'] === 0.0;
                            $canEdit   = $isDraft || ($supervisor && $row['status'] === 'unpaid' && $noPayment
                                         && $row['type'] === 'sales' && empty($row['file_path']) && (int) $row['loose_items'] === 0);
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
                                    <?php if ($isDraft): ?>
                                        <a class="icon-btn" href="<?= e(url('financial', ['action' => 'invoice', 'edit' => $row['id']])) ?>"
                                           title="Continue draft" aria-label="Continue draft for <?= e($row['party_name']) ?>">
                                            <i class="fa-regular fa-pen-to-square"></i>
                                        </a>
                                    <?php else: ?>
                                        <a class="icon-btn" href="<?= e(url('financial', ['action' => 'print', 'id' => $row['id']])) ?>" target="_blank" rel="noopener"
                                           title="Print invoice" aria-label="Print invoice <?= e($name) ?>">
                                            <i class="fa-solid fa-print"></i>
                                        </a>
                                        <a class="icon-btn<?= $supervisor && $balance > 0.001 ? ' icon-btn--ok' : '' ?>"
                                           href="<?= e(url('financial', ['action' => 'invoice', 'payments' => $row['id']])) ?>"
                                           title="<?= $supervisor && $balance > 0.001 ? 'Record payment / receipts' : 'Payments and receipts' ?>"
                                           aria-label="Payments for <?= e($name) ?>">
                                            <i class="fa-solid fa-<?= $supervisor && $balance > 0.001 ? 'money-bill-wave' : 'receipt' ?>"></i>
                                        </a>
                                        <?php if ($canEdit): ?>
                                            <a class="icon-btn" href="<?= e(url('financial', ['action' => 'invoice', 'edit' => $row['id']])) ?>"
                                               title="Edit" aria-label="Edit <?= e($name) ?>">
                                                <i class="fa-regular fa-pen-to-square"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($supervisor && in_array($row['status'], ['draft', 'unpaid'], true) && $noPayment): ?>
                                        <form method="post" action="<?= e(url('financial', ['action' => 'delete'])) ?>"
                                              data-confirm="Remove <?= $isDraft ? 'this draft' : 'invoice ' . e($name) ?>? This cannot be undone."
                                              data-confirm-title="Remove invoice?" data-confirm-ok="Remove">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                            <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                            <button class="icon-btn icon-btn--danger" type="submit" title="Remove" aria-label="Remove <?= e($name) ?>">
                                                <i class="fa-regular fa-trash-can"></i>
                                            </button>
                                        </form>
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

<?php require __DIR__ . '/_invoice_modal.php'; ?>
<?php if ($pay !== null): ?>
    <?php require __DIR__ . '/_payments_modal.php'; ?>
<?php endif; ?>

<?php if ($supervisor): ?>
    <!-- SST setting (Supervisor): applied to every new invoice. -->
    <dialog class="confirm-dialog sst-dialog" id="sstModal" aria-labelledby="sstTitle">
        <form method="post" action="<?= e(url('financial', ['action' => 'settings'])) ?>">
            <?= csrf_field() ?>
            <div class="confirm-icon confirm-icon--primary"><i class="fa-solid fa-percent"></i></div>
            <h3 id="sstTitle">SST on invoices</h3>
            <p>Businesses registered for SST charge it on every invoice. Not registered? Leave it off.</p>
            <label class="checkbox sst-check">
                <input type="checkbox" name="sst_registered" value="1"<?= $finance['sst_registered'] ? ' checked' : '' ?>>
                My business is SST-registered
            </label>
            <div class="field sst-rate">
                <label for="sst_rate">SST rate (%)</label>
                <input id="sst_rate" name="sst_rate" type="text" inputmode="decimal" list="sstRates"
                       value="<?= e(rtrim(rtrim($finance['sst_rate'], '0'), '.')) ?>">
                <datalist id="sstRates"><option value="8"></option><option value="6"></option><option value="10"></option><option value="5"></option></datalist>
                <p class="field-hint">Service tax is usually 8% (some services 6%); sales tax 5% or 10%. Existing invoices keep their rate.</p>
            </div>
            <div class="confirm-actions">
                <button class="secondary-btn" type="button" data-modal-close>Cancel</button>
                <button class="primary-btn" type="submit">Save</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>
<?php view('layouts/footer'); ?>
