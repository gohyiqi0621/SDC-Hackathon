<?php
/**
 * orders/index.php - Orders: orders from the AI chatbot, their bank references,
 * invoices and official receipts.
 *
 * Layout: 4 summary cards (click = filter) -> filter bar (status dropdown +
 * search) -> table  Order | Customer | Total | Status | Action  -> pager.
 *
 * Action column:
 *   Verify payment (bank reference sent)  Approve · Reject (popup: reason) · Details
 *   Payment successful                    Mark shipped · Details
 *   anything else                         Details
 * Details popup: order, customer, items, payment + bank reference, invoice and
 * official receipt (Send invoice / Send receipt), timeline, Cancel order.
 *
 * Delete: tick orders (checkbox column, or "select all") -> the bar shows
 * "N selected  Delete" -> confirm -> POST orders/delete. Also in Details.
 *
 * Expects: $orders, $items (order id => items), $invoices (order id => invoice),
 * $overview (cards), $f (status, q), $total, $page, $pages, $perPage, $missingTable.
 */
use Src\Controllers\OrderController;

view('layouts/header', ['title' => 'Orders', 'styles' => ['assets/css/orders.css']]);

$status    = $f['status'];
$q         = $f['q'];
$listQuery = array_filter(['status' => $status, 'q' => $q], static fn ($v) => $v !== '');
$listUrl   = url('orders', $listQuery + ($page > 1 ? ['p' => $page] : []));
$from      = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
$to        = min($total, $page * $perPage);

// One pill per status: label + colour.
$pill = [
    'pending_payment'   => ['Awaiting payment',   'grey'],
    'payment_submitted' => ['Verify payment',     'amber'],
    'paid'              => ['Payment successful', 'green'],
    'shipped'           => ['Shipped',            'blue'],
    'payment_rejected'  => ['Payment rejected',   'red'],
    'cancelled'         => ['Cancelled',          'red'],
];
$when = static fn (?string $t): string => $t ? date('j M Y, g:i a', strtotime($t)) : '';
$cards = [
    ['payment_submitted', 'fa-solid fa-magnifying-glass-dollar', 'Verify Payment',      (string) (int) $overview['review'],  'Bank reference sent',  'amber'],
    ['paid',              'fa-solid fa-box',                     'Ready to Ship',       (string) (int) $overview['to_ship'], 'Paid, not shipped',    'green'],
    ['awaiting',          'fa-regular fa-hourglass-half',        'Waiting for Payment', (string) (int) $overview['waiting'], 'No bank reference yet', 'grey'],
    [null,                'fa-solid fa-sack-dollar',             'Income Today',        (string) $overview['sales_today'],   'Payments approved today', 'purple'],
];
?>
<div class="page-body orders-page">

    <header class="orders-head">
        <h1>Orders</h1>
        <p>Orders from your AI assistant. Verify the customer's payment, then ship. Customers are told on Telegram at every step.</p>
    </header>

    <?php if ($missingTable): ?>
        <div class="flash flash--warning" role="status">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <strong>The orders table does not exist yet</strong>
                <span>Run <code>php bin/migrate.php</code> in the project folder, then reload this page.</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- 1. Summary cards (click = filter) -->
    <section class="ocards" aria-label="Summary">
        <?php foreach ($cards as [$filter, $icon, $label, $value, $hint, $tone]): ?>
            <?php $tag = $filter !== null ? 'a' : 'div'; ?>
            <<?= $tag ?> class="ocard ocard--<?= e($tone) ?><?= $filter !== null && $status === $filter ? ' is-active' : '' ?><?= $filter === 'payment_submitted' && (int) $value > 0 ? ' has-items' : '' ?>"
                <?= $filter !== null ? 'href="' . e(url('orders', ['status' => $filter])) . '"' : '' ?>>
                <span class="ocard__icon"><i class="<?= e($icon) ?>" aria-hidden="true"></i></span>
                <span class="ocard__text">
                    <small><?= e($label) ?></small>
                    <strong><?= e($value) ?></strong>
                    <em><?= e($hint) ?></em>
                </span>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </section>

    <section class="opanel">
        <!-- 2. Filter bar -->
        <form class="ofilter" method="get" action="<?= e(APP_URL . '/') ?>" role="search">
            <input type="hidden" name="page" value="orders">
            <label class="ofilter__status">
                <span class="sr-only">Status</span>
                <select name="status" onchange="this.form.submit()">
                    <?php foreach (OrderController::FILTERS as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="ofilter__search">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <span class="sr-only">Search</span>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search order no., customer or phone" maxlength="100">
            </label>
            <?php if ($status !== '' || $q !== ''): ?>
                <a class="ofilter__clear" href="<?= e(url('orders')) ?>">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Bulk delete: the row checkboxes belong to this form (form="bulkDelete") -->
        <form class="obulk" id="bulkDelete" method="post" action="<?= e(url('orders', ['action' => 'delete'])) ?>" hidden
              data-confirm="Delete the selected orders? This cannot be undone. Paid invoices stay in Financial &gt; Invoice."
              data-confirm-title="Delete orders?" data-confirm-ok="Delete">
            <?= csrf_field() ?>
            <input type="hidden" name="return" value="<?= e($listUrl) ?>">
            <span><strong data-bulk-count>0</strong> selected</span>
            <button class="obtn obtn--ghost" type="button" data-bulk-clear>Clear</button>
            <button class="obtn obtn--danger" type="submit"><i class="fa-regular fa-trash-can" aria-hidden="true"></i> Delete</button>
        </form>

        <!-- 3. Table -->
        <?php if ($orders === []): ?>
            <div class="oempty">
                <i class="fa-solid fa-bag-shopping" aria-hidden="true"></i>
                <strong><?= $status !== '' || $q !== '' ? 'No orders match' : 'No orders yet' ?></strong>
                <span><?= $status !== '' || $q !== '' ? 'Try another status or search.' : 'Orders placed through your AI assistant on Telegram appear here.' ?></span>
            </div>
        <?php else: ?>
            <div class="otable-wrap">
                <table class="otable">
                    <thead>
                        <tr><th class="is-check"><input type="checkbox" data-check-all aria-label="Select all orders on this page"></th><th>Order</th><th>Customer</th><th class="is-num">Total</th><th>Status</th><th class="is-act">Action</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $order):
                        $id         = (int) $order['id'];
                        $st         = (string) $order['status'];
                        [$stLabel, $stTone] = $pill[$st] ?? [$st, 'grey'];
                        $hasReceipt = !empty($order['receipt_path']);
                        $username   = trim((string) ($order['customer_username'] ?? ''));
                        $sub        = $username !== '' ? '@' . $username : (string) ($order['customer_phone'] ?? '');
                        ?>
                        <tr class="orow">
                            <td class="is-check"><input type="checkbox" name="ids[]" value="<?= $id ?>" form="bulkDelete" data-check
                                                        aria-label="Select <?= e($order['order_no']) ?>"></td>
                            <td data-label="Order">
                                <strong class="ono"><?= e($order['order_no']) ?></strong>
                                <small class="osub"><?= e($when($order['created_at'])) ?></small>
                            </td>
                            <td data-label="Customer">
                                <strong class="oname"><?= e($order['customer_name'] ?: ($username !== '' ? '@' . $username : 'Customer')) ?></strong>
                                <?php if ($sub !== '' && $sub !== ($order['customer_name'] ?? '')): ?><small class="osub"><?= e($sub) ?></small><?php endif; ?>
                            </td>
                            <td data-label="Total" class="is-num"><strong class="ototal"><?= e(format_money($order['total'], $order['currency'])) ?></strong></td>
                            <td data-label="Status"><span class="opill opill--<?= e($stTone) ?>"><?= e($stLabel) ?></span></td>
                            <td data-label="Action" class="is-act">
                                <div class="oacts">
                                    <?php if ($st === 'payment_submitted' && $hasReceipt): ?>
                                        <form method="post" action="<?= e(url('orders', ['action' => 'approve'])) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $id ?>">
                                            <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                            <button class="obtn obtn--primary" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i> Approve</button>
                                        </form>
                                        <button class="obtn obtn--danger" type="button" data-reject="<?= $id ?>" data-order-no="<?= e($order['order_no']) ?>">
                                            <i class="fa-solid fa-xmark" aria-hidden="true"></i> Reject
                                        </button>
                                    <?php elseif ($st === 'paid'): ?>
                                        <form method="post" action="<?= e(url('orders', ['action' => 'ship'])) ?>"
                                              data-confirm="Mark <?= e($order['order_no']) ?> as shipped and tell the customer on Telegram?"
                                              data-confirm-title="Mark as shipped?" data-confirm-ok="Mark shipped" data-confirm-tone="primary">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $id ?>">
                                            <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                            <button class="obtn obtn--primary" type="submit"><i class="fa-solid fa-truck-fast" aria-hidden="true"></i> Mark shipped</button>
                                        </form>
                                    <?php endif; ?>
                                    <button class="obtn obtn--ghost" type="button" data-details="<?= $id ?>">Details</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <footer class="ofoot">
                <p>Showing <strong><?= $from ?>–<?= $to ?></strong> of <strong><?= (int) $total ?></strong></p>
                <?php if ($pages > 1): ?>
                    <nav class="opager" aria-label="Pages">
                        <?php if ($page > 1): ?>
                            <a href="<?= e(url('orders', $listQuery + ['p' => $page - 1])) ?>" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $pages; $i++): ?>
                            <?php if ($i === 1 || $i === $pages || abs($i - $page) <= 1): ?>
                                <a href="<?= e(url('orders', $listQuery + ['p' => $i])) ?>"<?= $i === $page ? ' class="is-active" aria-current="page"' : '' ?>><?= $i ?></a>
                            <?php elseif (abs($i - $page) === 2): ?>
                                <span aria-hidden="true">…</span>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $pages): ?>
                            <a href="<?= e(url('orders', $listQuery + ['p' => $page + 1])) ?>" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </footer>
        <?php endif; ?>
    </section>
</div>

<!-- Reject: the reason is sent to the customer -->
<dialog class="odialog odialog--small" id="rejectDialog" aria-labelledby="rejectTitle">
    <form method="post" action="<?= e(url('orders', ['action' => 'reject'])) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="" data-reject-id>
        <input type="hidden" name="return" value="<?= e($listUrl) ?>">
        <header class="odialog__head">
            <h2 id="rejectTitle">Reject payment <span data-reject-no></span></h2>
            <button class="odialog__x" type="button" data-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </header>
        <div class="odialog__body">
            <label class="ofield">
                <span>Reason (sent to the customer on Telegram)</span>
                <textarea name="reason" rows="3" maxlength="300" required placeholder="e.g. The amount does not match the order total."></textarea>
            </label>
            <p class="ohint">The customer is asked to send a new bank reference.</p>
        </div>
        <footer class="odialog__foot">
            <button class="obtn obtn--ghost" type="button" data-close>Cancel</button>
            <button class="obtn obtn--danger-solid" type="submit">Reject payment</button>
        </footer>
    </form>
</dialog>

<!-- Details: one popup per order on this page -->
<?php foreach ($orders as $order):
    $id         = (int) $order['id'];
    $st         = (string) $order['status'];
    [$stLabel, $stTone] = $pill[$st] ?? [$st, 'grey'];
    $hasReceipt = !empty($order['receipt_path']);
    $isImage    = $hasReceipt && str_starts_with((string) $order['receipt_mime'], 'image/');
    $paid       = in_array($st, ['paid', 'shipped'], true);
    $invoice    = $invoices[$id] ?? null;
    $username   = trim((string) ($order['customer_username'] ?? ''));
    $receiptUrl = url('orders', ['action' => 'receipt', 'id' => $id]);
    $steps = array_filter([
        ['Order placed', $order['created_at']],
        !empty($order['receipt_uploaded_at']) ? ['Bank reference received', $order['receipt_uploaded_at']] : null,
        $paid && !empty($order['reviewed_at']) ? ['Payment approved', $order['reviewed_at']] : null,
        $st === 'payment_rejected' && !empty($order['reviewed_at']) ? ['Payment rejected', $order['reviewed_at']] : null,
        !empty($order['official_receipt_sent_at']) ? ['Official receipt sent', $order['official_receipt_sent_at']] : null,
        !empty($order['shipped_at']) ? ['Shipped', $order['shipped_at']] : null,
        $st === 'cancelled' ? ['Cancelled', $order['reviewed_at'] ?? null] : null,
    ]);
    ?>
    <dialog class="odialog" id="details-<?= $id ?>" aria-labelledby="details-title-<?= $id ?>">
        <header class="odialog__head">
            <div class="ohead">
                <span class="ohead__icon"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i></span>
                <div>
                    <h2 id="details-title-<?= $id ?>"><?= e($order['order_no']) ?></h2>
                    <p>Placed <?= e($when($order['created_at'])) ?></p>
                </div>
            </div>
            <button class="odialog__x" type="button" data-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </header>

        <div class="odialog__body odetail">
            <!-- At a glance -->
            <div class="osum">
                <div><small>Status</small><span class="opill opill--<?= e($stTone) ?>"><?= e($stLabel) ?></span></div>
                <div><small>Total</small><strong><?= e(format_money($order['total'], $order['currency'])) ?></strong></div>
                <div><small>Items</small><strong><?= (int) array_sum(array_map(static fn (array $i): int => (int) $i['quantity'], $items[$id] ?? [])) ?></strong></div>
                <div><small>Invoice</small><strong><?= $invoice !== null && !empty($invoice['invoice_number']) ? e($invoice['invoice_number']) : '—' ?></strong></div>
            </div>

            <div class="ogrid">
                <!-- Customer -->
                <section class="obox">
                    <h3><i class="fa-regular fa-user" aria-hidden="true"></i> Customer</h3>
                    <dl class="odl">
                        <dt>Name</dt><dd><?= e($order['customer_name'] ?: '—') ?></dd>
                        <?php if ($username !== ''): ?><dt>Telegram</dt><dd>@<?= e($username) ?></dd><?php endif; ?>
                        <dt>Phone</dt><dd><?= e($order['customer_phone'] ?: '—') ?></dd>
                        <?php if (!empty($order['delivery_note'])): ?><dt>Note</dt><dd><?= e($order['delivery_note']) ?></dd><?php endif; ?>
                    </dl>
                </section>

                <!-- Payment -->
                <section class="obox">
                    <h3><i class="fa-regular fa-credit-card" aria-hidden="true"></i> Payment</h3>
                    <dl class="odl">
                        <dt>Status</dt><dd><span class="opill opill--<?= e($stTone) ?>"><?= e($stLabel) ?></span></dd>
                        <?php if ($paid && !empty($order['reviewed_at'])): ?><dt>Approved</dt><dd><?= e($when($order['reviewed_at'])) ?></dd><?php endif; ?>
                        <?php if ($st === 'payment_rejected' && !empty($order['review_note'])): ?>
                            <dt>Reason</dt><dd class="is-red"><?= e($order['review_note']) ?></dd>
                        <?php endif; ?>
                    </dl>
                    <?php if ($hasReceipt): ?>
                        <a class="oref" href="<?= e($receiptUrl) ?>" target="_blank" rel="noopener" title="Open the bank reference full size">
                            <?php if ($isImage): ?>
                                <img src="<?= e($receiptUrl) ?>" alt="Bank reference of <?= e($order['order_no']) ?>" loading="lazy">
                            <?php else: ?>
                                <span class="oref__file"><i class="fa-regular fa-file-pdf" aria-hidden="true"></i> Bank reference (PDF)</span>
                            <?php endif; ?>
                            <span class="oref__open">Bank reference · open full size <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></span>
                        </a>
                    <?php else: ?>
                        <p class="oempty-note"><i class="fa-regular fa-hourglass-half" aria-hidden="true"></i> No bank reference from the customer yet.</p>
                    <?php endif; ?>
                </section>
            </div>

            <!-- Items -->
            <section class="obox">
                <h3><i class="fa-solid fa-list-ul" aria-hidden="true"></i> Items</h3>
                <table class="oitems">
                    <thead><tr><th>Product</th><th class="is-num">Qty</th><th class="is-num">Price</th><th class="is-num">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($items[$id] ?? [] as $item): ?>
                        <tr>
                            <td><?= e($item['product_name']) ?><?php if (!empty($item['sku'])): ?> <small><?= e($item['sku']) ?></small><?php endif; ?></td>
                            <td class="is-num"><?= (int) $item['quantity'] ?></td>
                            <td class="is-num"><?= e(number_format((float) $item['unit_price'], 2)) ?></td>
                            <td class="is-num"><?= e(number_format((float) $item['line_total'], 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><td colspan="3">Total</td><td class="is-num"><?= e(format_money($order['total'], $order['currency'])) ?></td></tr></tfoot>
                </table>
            </section>

            <div class="ogrid">
                <!-- Documents -->
                <section class="obox">
                    <h3><i class="fa-regular fa-folder-open" aria-hidden="true"></i> Documents</h3>
                    <ul class="odocs">
                        <li>
                            <span class="odocs__name"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i>
                                <span><strong>Invoice</strong>
                                    <?php if ($invoice !== null && !empty($invoice['invoice_number'])): ?>
                                        <em><?= e($invoice['invoice_number']) ?> · <?= e(ucfirst((string) $invoice['status'])) ?></em>
                                    <?php else: ?>
                                        <em>Not created</em>
                                    <?php endif; ?>
                                </span>
                            </span>
                            <?php if ($invoice !== null && !empty($invoice['invoice_number'])): ?>
                                <a href="<?= e(url('financial', ['action' => 'print', 'id' => (int) $invoice['id']])) ?>" target="_blank" rel="noopener">Open</a>
                            <?php endif; ?>
                        </li>
                        <li>
                            <span class="odocs__name"><i class="fa-solid fa-receipt" aria-hidden="true"></i>
                                <span><strong>Official receipt</strong>
                                    <em><?= $paid
                                        ? (!empty($order['official_receipt_sent_at']) ? 'Sent ' . e(date('j M, g:i a', strtotime((string) $order['official_receipt_sent_at']))) : 'Not sent yet')
                                        : 'After the payment is approved' ?></em>
                                </span>
                            </span>
                            <?php if ($paid): ?>
                                <a href="<?= e(url('orders', ['action' => 'official_receipt', 'id' => $id])) ?>" target="_blank" rel="noopener">Open</a>
                            <?php endif; ?>
                        </li>
                    </ul>
                    <?php if ($paid && $order['channel'] === 'telegram'): ?>
                        <div class="osend">
                            <form method="post" action="<?= e(url('orders', ['action' => 'send_invoice'])) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                <button class="obtn obtn--ghost" type="submit"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send invoice</button>
                            </form>
                            <form method="post" action="<?= e(url('orders', ['action' => 'send_receipt'])) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                <button class="obtn obtn--ghost" type="submit"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send receipt</button>
                            </form>
                        </div>
                        <p class="ohint">Sent as a PDF to the customer on Telegram.</p>
                    <?php endif; ?>
                </section>

                <!-- Timeline -->
                <section class="obox">
                    <h3><i class="fa-regular fa-clock" aria-hidden="true"></i> Timeline</h3>
                    <ol class="otime">
                        <?php foreach ($steps as [$label, $at]): ?>
                            <li><strong><?= e($label) ?></strong><?php if ($at): ?><small><?= e($when($at)) ?></small><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            </div>
        </div>

        <footer class="odialog__foot">
            <form method="post" action="<?= e(url('orders', ['action' => 'delete'])) ?>" class="odialog__left"
                  data-confirm="Delete <?= e($order['order_no']) ?>? This cannot be undone.<?= $paid ? ' Its paid invoice stays in Financial &gt; Invoice.' : '' ?>"
                  data-confirm-title="Delete order?" data-confirm-ok="Delete">
                <?= csrf_field() ?>
                <input type="hidden" name="ids[]" value="<?= $id ?>">
                <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                <button class="obtn obtn--link-danger" type="submit"><i class="fa-regular fa-trash-can" aria-hidden="true"></i> Delete order</button>
            </form>
            <?php if (in_array($st, ['pending_payment', 'payment_submitted', 'payment_rejected'], true)): ?>
                <form method="post" action="<?= e(url('orders', ['action' => 'cancel'])) ?>"
                      data-confirm="Cancel <?= e($order['order_no']) ?>? The customer is told on Telegram."
                      data-confirm-title="Cancel order?" data-confirm-ok="Cancel order">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                    <button class="obtn obtn--danger" type="submit"><i class="fa-solid fa-ban" aria-hidden="true"></i> Cancel order</button>
                </form>
            <?php endif; ?>
            <?php if ($st === 'payment_submitted' && $hasReceipt): ?>
                <button class="obtn obtn--danger" type="button" data-reject="<?= $id ?>" data-order-no="<?= e($order['order_no']) ?>">Reject</button>
                <form method="post" action="<?= e(url('orders', ['action' => 'approve'])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                    <button class="obtn obtn--primary" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i> Approve</button>
                </form>
            <?php elseif ($st === 'paid'): ?>
                <form method="post" action="<?= e(url('orders', ['action' => 'ship'])) ?>"
                      data-confirm="Mark <?= e($order['order_no']) ?> as shipped and tell the customer on Telegram?"
                      data-confirm-title="Mark as shipped?" data-confirm-ok="Mark shipped" data-confirm-tone="primary">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                    <button class="obtn obtn--primary" type="submit"><i class="fa-solid fa-truck-fast" aria-hidden="true"></i> Mark shipped</button>
                </form>
            <?php else: ?>
                <button class="obtn obtn--ghost" type="button" data-close>Close</button>
            <?php endif; ?>
        </footer>
    </dialog>
<?php endforeach; ?>

<?php view('layouts/footer', ['scripts' => ['assets/js/orders.js']]); ?>
