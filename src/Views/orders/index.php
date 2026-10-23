<?php
/**
 * orders/index.php - orders from the AI chatbot and their payment receipts.
 *
 * Expects: $orders, $items (order id => items), $counts (status => n, with the
 * other filters applied), $overview (top cards), $summary (filtered count/sum),
 * $products (names for the product filter), $f (filters: status, q, range,
 * from, to, product, sort), $total, $page, $pages, $perPage, $missingTable.
 *
 * Layout: header -> four summary cards (click = filter) -> one panel with the
 * status tabs, the filter bar (search, date, product, sort), the result line
 * with active filter chips, the order table (cards on phones) and the pager.
 * Columns: Order | Customer | Items | Total | Payment (status + receipt) | Action.
 */
use Src\Controllers\OrderController;
use Src\Models\Order;

view('layouts/header', ['title' => 'Orders', 'styles' => ['assets/css/orders.css']]);

$status    = $f['status'];
$q         = $f['q'];
// Everything that describes the current list, so links keep the other filters.
$listQuery = array_filter([
    'status'  => $f['status'],
    'q'       => $f['q'],
    'range'   => $f['range'],
    'from'    => $f['range'] === 'custom' ? $f['from'] : null,
    'to'      => $f['range'] === 'custom' ? $f['to'] : null,
    'product' => $f['product'],
    'sort'    => $f['sort'] !== 'review' ? $f['sort'] : null,
], static fn ($v) => $v !== null && $v !== '');
$listUrl   = url('orders', $listQuery + ($page > 1 ? ['p' => $page] : []));
$without   = static fn (string ...$keys): string => url('orders', array_diff_key($listQuery, array_flip($keys)));
$allCount  = array_sum($counts);
$from      = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
$to        = min($total, $page * $perPage);
$rangeText = $f['range'] === 'custom'
    ? trim(($f['from'] ? date('j M Y', strtotime($f['from'])) : '…') . ' – ' . ($f['to'] ? date('j M Y', strtotime($f['to'])) : '…'))
    : (OrderController::RANGES[$f['range']] ?? '');
$hasFilters = $f['q'] !== '' || $f['range'] !== '' || $f['product'] !== '' || $f['sort'] !== 'review';
$active = array_filter([
    'range'   => $f['range'] !== '' ? $rangeText : null,
    'product' => $f['product'] !== '' ? $f['product'] : null,
    'q'       => $f['q'] !== '' ? '“' . $f['q'] . '”' : null,
]);
?>
<div class="page-body orders-page">

    <header class="orders-head">
        <div>
            <h1>Orders</h1>
            <p>Check each customer's bank reference, approve it to issue the official receipt, then ship. Customers are told on Telegram at every step.</p>
        </div>
    </header>

    <?php if ($missingTable): ?>
        <div class="flash flash--warning" role="status">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <strong>The orders table does not exist yet</strong>
                <span>Run <code>C:\xampp\php\php.exe bin\migrate.php</code> in the project folder, then reload this page.</span>
            </div>
        </div>
    <?php endif; ?>

    <section class="orders-kpis" aria-label="Summary">
        <?php
        $kpis = [
            ['review',  'payment_submitted', 'fa-solid fa-receipt',        (string) (int) $overview['review'],  'Bank refs to review', 'Check and approve'],
            ['waiting', 'pending_payment',   'fa-regular fa-hourglass-half', (string) (int) $overview['waiting'], 'Waiting for payment', 'No bank ref. yet'],
            ['ship',    'paid',              'fa-solid fa-box',            (string) (int) $overview['to_ship'], 'Ready to ship',       'Paid, not shipped'],
        ];
        foreach ($kpis as [$kind, $st, $icon, $value, $label, $hint]):
            $on = $status === $st; ?>
            <a class="orders-kpi orders-kpi--<?= e($kind) ?><?= $kind === 'review' && $overview['review'] > 0 ? ' has-items' : '' ?><?= $on ? ' is-active' : '' ?>"
               href="<?= e(url('orders', ['status' => $st])) ?>"<?= $on ? ' aria-current="true"' : '' ?>>
                <span class="orders-kpi__icon" aria-hidden="true"><i class="<?= e($icon) ?>"></i></span>
                <span class="orders-kpi__text">
                    <small><?= e($label) ?></small>
                    <strong><?= e($value) ?></strong>
                    <em><?= e($hint) ?></em>
                </span>
            </a>
        <?php endforeach; ?>
        <a class="orders-kpi orders-kpi--today<?= $f['range'] === 'today' && $status === '' ? ' is-active' : '' ?>" href="<?= e(url('orders', ['range' => 'today'])) ?>">
            <span class="orders-kpi__icon" aria-hidden="true"><i class="fa-solid fa-chart-line"></i></span>
            <span class="orders-kpi__text">
                <small>Paid today</small>
                <strong><?= e($overview['sales_today']) ?></strong>
                <em><?= (int) $overview['today'] ?> order<?= $overview['today'] === 1 ? '' : 's' ?> today</em>
            </span>
        </a>
    </section>

    <section class="panel orders-panel">

        <nav class="order-tabs" aria-label="Filter by status">
            <a href="<?= e($without('status')) ?>"<?= $status === '' ? ' class="is-active" aria-current="page"' : '' ?>>
                All <span class="order-tabs__n"><?= (int) $allCount ?></span>
            </a>
            <?php foreach (Order::STATUSES as $key => $label): ?>
                <a class="order-tabs--<?= e($key) ?><?= $status === $key ? ' is-active' : '' ?>" href="<?= e(url('orders', ['status' => $key] + $listQuery)) ?>"<?= $status === $key ? ' aria-current="page"' : '' ?>>
                    <i class="order-tabs__dot" aria-hidden="true"></i><?= e($label) ?> <span class="order-tabs__n"><?= (int) $counts[$key] ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <form class="orders-filters" method="get" action="<?= e(url('orders')) ?>" data-orders-filters>
            <input type="hidden" name="page" value="orders">
            <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>

            <div class="orders-search">
                <label class="sr-only" for="orders-q">Search orders</label>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input id="orders-q" name="q" type="search" placeholder="Search order no., customer or phone" value="<?= e($q) ?>">
                <button type="submit" class="orders-search__go" aria-label="Search"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
            </div>

            <div class="orders-selects">
                <label class="orders-select">
                    <span>Date</span>
                    <select name="range" data-range>
                        <?php foreach (OrderController::RANGES as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $f['range'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="orders-select">
                    <span>Product</span>
                    <select name="product">
                        <option value="">All products</option>
                        <?php foreach ($products as $name): ?>
                            <option value="<?= e($name) ?>"<?= $f['product'] === $name ? ' selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="orders-select">
                    <span>Sort</span>
                    <select name="sort">
                        <?php foreach (Order::SORTS as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $f['sort'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($hasFilters): ?>
                    <a class="orders-reset" href="<?= e(url('orders', array_filter(['status' => $status]))) ?>"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reset</a>
                <?php endif; ?>
            </div>

            <div class="orders-dates" data-custom-dates<?= $f['range'] === 'custom' ? '' : ' hidden' ?>>
                <label class="orders-select">
                    <span>From</span>
                    <input type="date" name="from" value="<?= e($f['range'] === 'custom' ? (string) $f['from'] : '') ?>" max="<?= e(date('Y-m-d')) ?>">
                </label>
                <label class="orders-select">
                    <span>To</span>
                    <input type="date" name="to" value="<?= e($f['range'] === 'custom' ? (string) $f['to'] : '') ?>" max="<?= e(date('Y-m-d')) ?>">
                </label>
                <button class="orders-apply" type="submit">Apply dates</button>
            </div>
        </form>

        <div class="orders-resultbar">
            <p class="orders-resultbar__text">
                <?php if ($total > 0): ?>
                    <strong><?= (int) $total ?></strong> order<?= $total === 1 ? '' : 's' ?>
                    <span class="orders-sep" aria-hidden="true">·</span>
                    <strong><?= e(format_money($summary['sum'], $summary['currency'])) ?></strong> total<?= $status === '' ? ' <span class="muted">(excl. cancelled)</span>' : '' ?>
                <?php elseif ($allCount > 0 || $active !== []): ?>
                    No orders match these filters.
                <?php else: ?>
                    No orders yet.
                <?php endif; ?>
            </p>
            <?php if ($active !== []): ?>
                <div class="orders-chips">
                    <?php foreach ($active as $key => $label): ?>
                        <a class="orders-chip" href="<?= e($key === 'range' ? $without('range', 'from', 'to') : $without($key)) ?>" title="Remove this filter">
                            <?= e($label) ?> <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($orders === []): ?>
            <div class="empty-state orders-empty">
                <div class="empty-icon"><i class="fa-solid fa-bag-shopping"></i></div>
                <h3><?= $allCount === 0 ? 'No orders yet' : 'Nothing here' ?></h3>
                <p><?= $allCount === 0
                    ? 'When a customer orders through your AI chatbot on Telegram, the order appears here. Their bank reference (transfer slip) arrives here too.'
                    : 'No orders match this filter. Try another status or clear the filters.' ?></p>
            </div>
        <?php else: ?>
            <div class="orders-table-wrap">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th class="c-order">Order</th>
                            <th class="c-customer">Customer</th>
                            <th class="c-items">Items</th>
                            <th class="c-total">Total</th>
                            <th class="c-status">Payment</th>
                            <th class="c-actions">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <?php
                            $id        = (int) $order['id'];
                            $st        = (string) $order['status'];
                            $lines     = $items[$id] ?? [];
                            $name      = trim((string) ($order['customer_name'] ?? '')) ?: 'Telegram ' . $order['customer_chat_id'];
                            $parts     = preg_split('/\s+/u', $name) ?: [$name];
                            $initials  = mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : mb_substr($parts[0], 1, 1)));
                            $hue       = crc32((string) $order['customer_chat_id']) % 360;
                            $sub       = array_filter([
                                !empty($order['customer_username']) ? '@' . $order['customer_username'] : null,
                                !empty($order['customer_phone']) ? $order['customer_phone'] : null,
                            ]);
                            $hasReceipt = !empty($order['receipt_path']);
                            // Waiting for payment (no bank reference yet): Payment and Action stay blank.
                            $waiting    = in_array($st, ['pending_payment', 'payment_rejected'], true) || ($st === 'payment_submitted' && !$hasReceipt);
                            $canCancel  = $st === 'payment_submitted' && $hasReceipt;
                            ?>
                            <tr class="orders-row orders-row--<?= e($st) ?>">
                                <td class="c-order">
                                    <span class="order-no"><?= e($order['order_no']) ?></span>
                                    <small class="order-date"><?= e(date('j M Y, g:i a', strtotime((string) $order['created_at']))) ?></small>
                                </td>
                                <td class="c-customer">
                                    <div class="order-customer">
                                        <span class="order-avatar" style="--h: <?= (int) $hue ?>" aria-hidden="true"><?= e($initials) ?></span>
                                        <span class="order-customer__text">
                                            <strong><?= e($name) ?></strong>
                                            <?php if ($sub !== []): ?><small><?= e(implode(' · ', $sub)) ?></small><?php endif; ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="c-items">
                                    <?php if ($lines === []): ?>
                                        <span class="muted">—</span>
                                    <?php else: ?>
                                        <ul class="order-items">
                                            <?php foreach (array_slice($lines, 0, 2) as $item): ?>
                                                <li><span class="order-qty"><?= (int) $item['quantity'] ?>×</span><span class="order-item-name"><?= e($item['product_name']) ?></span></li>
                                            <?php endforeach; ?>
                                            <?php if (count($lines) > 2): ?>
                                                <li class="order-more" title="<?= e(implode(', ', array_map(static fn ($i) => $i['quantity'] . ' × ' . $i['product_name'], array_slice($lines, 2)))) ?>">+<?= count($lines) - 2 ?> more item<?= count($lines) - 2 === 1 ? '' : 's' ?></li>
                                            <?php endif; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php if (!empty($order['delivery_note'])): ?>
                                        <small class="order-note"><i class="fa-regular fa-note-sticky" aria-hidden="true"></i> <?= e($order['delivery_note']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="c-total"><strong class="order-total"><?= e(format_money($order['total'], $order['currency'])) ?></strong></td>
                                <td class="c-status">
                                    <span class="order-status order-status--<?= e($st) ?>"><i aria-hidden="true"></i><?= e(Order::STATUSES[$st] ?? $st) ?></span>
                                    <span class="order-pay">
                                        <?php if ($hasReceipt): ?>
                                            <a class="order-receipt" href="<?= e(url('orders', ['action' => 'receipt', 'id' => $id])) ?>" target="_blank" rel="noopener">
                                                <i class="fa-regular <?= $order['receipt_mime'] === 'application/pdf' ? 'fa-file-pdf' : 'fa-file-image' ?>" aria-hidden="true"></i> Bank reference
                                            </a>
                                        <?php elseif ($st !== 'cancelled' && !$waiting): ?>
                                            <span class="order-receipt order-receipt--none">No bank reference yet</span>
                                        <?php endif; ?>
                                        <?php if ($st === 'payment_rejected' && !empty($order['review_note'])): ?>
                                            <small class="order-reason"><?= e($order['review_note']) ?></small>
                                        <?php elseif ($st === 'shipped' && !empty($order['shipped_at'])): ?>
                                            <small>Shipped <?= e(date('j M, g:i a', strtotime((string) $order['shipped_at']))) ?></small>
                                        <?php endif; ?>
                                        <?php if (in_array($st, ['paid', 'shipped'], true)): ?>
                                            <small class="order-receipt-sent<?= empty($order['official_receipt_sent_at']) ? ' is-missing' : '' ?>">
                                                <?= !empty($order['official_receipt_sent_at'])
                                                    ? 'Official receipt sent ' . e(date('j M, g:i a', strtotime((string) $order['official_receipt_sent_at'])))
                                                    : 'Official receipt not sent yet' ?>
                                            </small>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td class="c-actions">
                                    <div class="order-actions">
                                        <?php if ($st === 'payment_submitted' && $hasReceipt): ?>
                                            <form method="post" action="<?= e(url('orders', ['action' => 'approve'])) ?>"
                                                  data-confirm="Mark <?= e($order['order_no']) ?> as Payment successful and tell the customer &quot;Payment verified&quot; on Telegram?"
                                                  data-confirm-title="Approve payment?" data-confirm-ok="Approve">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                                <button class="order-btn order-btn--approve" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i> Approve</button>
                                            </form>
                                            <details class="order-reject">
                                                <summary class="order-btn order-btn--ghost"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Reject</summary>
                                                <form method="post" action="<?= e(url('orders', ['action' => 'reject'])) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id" value="<?= $id ?>">
                                                    <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                                    <label for="reason-<?= $id ?>">Reason (sent to the customer)</label>
                                                    <input id="reason-<?= $id ?>" name="reason" type="text" maxlength="300" placeholder="e.g. Amount does not match">
                                                    <button class="danger-btn btn-sm" type="submit">Reject payment</button>
                                                </form>
                                            </details>
                                        <?php elseif ($st === 'paid'): ?>
                                            <form method="post" action="<?= e(url('orders', ['action' => 'ship'])) ?>"
                                                  data-confirm="Mark <?= e($order['order_no']) ?> as Shipped and tell the customer on Telegram?"
                                                  data-confirm-title="Mark as shipped?" data-confirm-ok="Mark as shipped">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                                <button class="order-btn order-btn--ship" type="submit"><i class="fa-solid fa-truck-fast" aria-hidden="true"></i> Mark shipped</button>
                                            </form>
                                            <a class="order-btn order-btn--doc" href="<?= e(url('orders', ['action' => 'official_receipt', 'id' => $id])) ?>" target="_blank" rel="noopener"
                                               title="Official receipt (PDF)"><i class="fa-regular fa-file-pdf" aria-hidden="true"></i> Receipt</a>
                                            <form method="post" action="<?= e(url('orders', ['action' => 'send_receipt'])) ?>"
                                                  data-confirm="Send the official receipt of <?= e($order['order_no']) ?> to the customer on Telegram<?= !empty($order['official_receipt_sent_at']) ? ' again' : '' ?>?"
                                                  data-confirm-title="Send official receipt?" data-confirm-ok="Send" data-confirm-tone="primary">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                                <button class="order-btn order-btn--icon order-btn--send" type="submit" title="Send the official receipt to the customer on Telegram"
                                                        aria-label="Send official receipt of <?= e($order['order_no']) ?>"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i><span class="order-btn__label">Send receipt</span></button>
                                            </form>
                                        <?php elseif ($waiting): ?>
                                        <?php elseif ($st === 'shipped'): ?>
                                            <a class="order-btn order-btn--doc" href="<?= e(url('orders', ['action' => 'official_receipt', 'id' => $id])) ?>" target="_blank" rel="noopener"
                                               title="Official receipt (PDF)"><i class="fa-regular fa-file-pdf" aria-hidden="true"></i> Official receipt</a>
                                            <form method="post" action="<?= e(url('orders', ['action' => 'send_receipt'])) ?>"
                                                  data-confirm="Send the official receipt of <?= e($order['order_no']) ?> to the customer on Telegram<?= !empty($order['official_receipt_sent_at']) ? ' again' : '' ?>?"
                                                  data-confirm-title="Send official receipt?" data-confirm-ok="Send" data-confirm-tone="primary">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                                <button class="order-btn order-btn--icon order-btn--send" type="submit" title="Send the official receipt to the customer on Telegram"
                                                        aria-label="Send official receipt of <?= e($order['order_no']) ?>"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i><span class="order-btn__label">Send receipt</span></button>
                                            </form>
                                        <?php else: ?>
                                            <span class="order-wait order-wait--done">Closed</span>
                                        <?php endif; ?>
                                        <?php if ($canCancel): ?>
                                            <form method="post" action="<?= e(url('orders', ['action' => 'cancel'])) ?>"
                                                  data-confirm="Cancel <?= e($order['order_no']) ?>? The customer is told on Telegram."
                                                  data-confirm-title="Cancel order?" data-confirm-ok="Cancel order">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                                <button class="order-btn order-btn--icon" type="submit" title="Cancel order" aria-label="Cancel <?= e($order['order_no']) ?>">
                                                    <i class="fa-solid fa-ban" aria-hidden="true"></i><span class="order-btn__label">Cancel</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <footer class="orders-foot">
                <p>Showing <strong><?= $from ?>–<?= $to ?></strong> of <strong><?= (int) $total ?></strong></p>
                <?php if ($pages > 1): ?>
                    <nav class="orders-pager" aria-label="Pages">
                        <?php if ($page > 1): ?>
                            <a href="<?= e(url('orders', $listQuery + ['p' => $page - 1])) ?>" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></a>
                        <?php else: ?>
                            <span class="is-disabled" aria-hidden="true"><i class="fa-solid fa-chevron-left"></i></span>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $pages; $i++): ?>
                            <?php if ($i === 1 || $i === $pages || abs($i - $page) <= 1): ?>
                                <a href="<?= e(url('orders', $listQuery + ['p' => $i])) ?>"<?= $i === $page ? ' class="is-active" aria-current="page"' : '' ?>><?= $i ?></a>
                            <?php elseif (abs($i - $page) === 2): ?>
                                <span class="orders-pager__gap" aria-hidden="true">…</span>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $pages): ?>
                            <a href="<?= e(url('orders', $listQuery + ['p' => $page + 1])) ?>" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></a>
                        <?php else: ?>
                            <span class="is-disabled" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </footer>
        <?php endif; ?>
    </section>
</div>
<script>
(function () {
    var form = document.querySelector('[data-orders-filters]');
    if (!form) { return; }
    var range = form.querySelector('[data-range]');
    var dates = form.querySelector('[data-custom-dates]');
    // Apply as soon as a dropdown changes (custom dates wait for "Apply dates", search for Enter / the arrow).
    form.querySelectorAll('select').forEach(function (sel) {
        sel.addEventListener('change', function () {
            if (sel === range) {
                dates.hidden = range.value !== 'custom';
                if (range.value === 'custom') { dates.querySelector('input').focus(); return; }
            }
            form.submit();
        });
    });
    // Close an open "Reject" box when another one opens or on a click outside.
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.order-reject[open]').forEach(function (d) {
            if (!d.contains(e.target)) { d.removeAttribute('open'); }
        });
    });
})();
</script>
<?php view('layouts/footer'); ?>
