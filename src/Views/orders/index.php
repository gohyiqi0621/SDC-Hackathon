<?php
/**
 * orders/index.php - orders from the AI chatbot and their payment receipts.
 *
 * Expects: $orders, $items (order id => items), $counts (status => n, with the
 * other filters applied), $overview (top cards), $summary (filtered count/sum),
 * $products (names for the product filter), $f (filters: status, q, range,
 * from, to, product, sort), $total, $page, $pages, $perPage, $missingTable.
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
$active = array_filter([
    'range'   => $f['range'] !== '' ? $rangeText : null,
    'product' => $f['product'] !== '' ? $f['product'] : null,
    'q'       => $f['q'] !== '' ? '“' . $f['q'] . '”' : null,
]);
?>
<div class="page-body orders-page">

    <header class="orders-title">
        <div>
            <h1>Orders</h1>
            <p>Approve receipts customers send, then mark paid orders as shipped. Customers are told on Telegram each time.</p>
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
        <a class="orders-kpi orders-kpi--review<?= $overview['review'] > 0 ? ' has-items' : '' ?>" href="<?= e(url('orders', ['status' => 'payment_submitted'])) ?>">
            <span class="orders-kpi__icon"><i class="fa-solid fa-receipt"></i></span>
            <span><strong><?= (int) $overview['review'] ?></strong><small>Receipts to review</small></span>
        </a>
        <a class="orders-kpi orders-kpi--waiting" href="<?= e(url('orders', ['status' => 'pending_payment'])) ?>">
            <span class="orders-kpi__icon"><i class="fa-regular fa-hourglass-half"></i></span>
            <span><strong><?= (int) $overview['waiting'] ?></strong><small>Waiting for payment</small></span>
        </a>
        <a class="orders-kpi orders-kpi--ship" href="<?= e(url('orders', ['status' => 'paid'])) ?>">
            <span class="orders-kpi__icon"><i class="fa-solid fa-box"></i></span>
            <span><strong><?= (int) $overview['to_ship'] ?></strong><small>Paid, to ship</small></span>
        </a>
        <a class="orders-kpi orders-kpi--today" href="<?= e(url('orders', ['range' => 'today'])) ?>">
            <span class="orders-kpi__icon"><i class="fa-solid fa-chart-line"></i></span>
            <span><strong><?= e($overview['sales_today']) ?></strong><small>Paid today · <?= (int) $overview['today'] ?> order<?= $overview['today'] === 1 ? '' : 's' ?></small></span>
        </a>
    </section>

    <section class="panel orders-panel">
        <nav class="order-filter" aria-label="Filter by status">
            <a href="<?= e($without('status')) ?>"<?= $status === '' ? ' class="is-active" aria-current="page"' : '' ?>>
                All <span><?= (int) $allCount ?></span>
            </a>
            <?php foreach (Order::STATUSES as $key => $label): ?>
                <a class="order-filter--<?= e($key) ?><?= $status === $key ? ' is-active' : '' ?>" href="<?= e(url('orders', ['status' => $key] + $listQuery)) ?>"<?= $status === $key ? ' aria-current="page"' : '' ?>>
                    <i aria-hidden="true"></i><?= e($label) ?> <span><?= (int) $counts[$key] ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <form class="orders-filters" method="get" action="<?= e(url('orders')) ?>" data-orders-filters>
            <input type="hidden" name="page" value="orders">
            <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>

            <label class="orders-field orders-field--search">
                <span class="sr-only">Search</span>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input name="q" type="search" placeholder="Order no., name, phone…" value="<?= e($q) ?>">
            </label>

            <label class="orders-field">
                <span class="orders-field__label">Date</span>
                <select name="range" data-range>
                    <?php foreach (OrderController::RANGES as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $f['range'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="orders-dates" data-custom-dates<?= $f['range'] === 'custom' ? '' : ' hidden' ?>>
                <label class="orders-field">
                    <span class="orders-field__label">From</span>
                    <input type="date" name="from" value="<?= e($f['range'] === 'custom' ? (string) $f['from'] : '') ?>" max="<?= e(date('Y-m-d')) ?>">
                </label>
                <label class="orders-field">
                    <span class="orders-field__label">To</span>
                    <input type="date" name="to" value="<?= e($f['range'] === 'custom' ? (string) $f['to'] : '') ?>" max="<?= e(date('Y-m-d')) ?>">
                </label>
            </div>

            <label class="orders-field">
                <span class="orders-field__label">Product</span>
                <select name="product">
                    <option value="">All products</option>
                    <?php foreach ($products as $name): ?>
                        <option value="<?= e($name) ?>"<?= $f['product'] === $name ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="orders-field">
                <span class="orders-field__label">Sort</span>
                <select name="sort">
                    <?php foreach (Order::SORTS as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $f['sort'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <button class="orders-apply" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
        </form>

        <div class="orders-resultbar">
            <p>
                <?php if ($total > 0): ?>
                    <strong><?= (int) $total ?></strong> order<?= $total === 1 ? '' : 's' ?>
                    · <strong><?= e(format_money($summary['sum'], $summary['currency'])) ?></strong> total<?= $status === '' ? ' (excl. cancelled)' : '' ?>
                    <?php if ($pages > 1): ?><span class="muted"> · showing <?= $from ?>–<?= $to ?></span><?php endif; ?>
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
                    <a class="orders-chip orders-chip--clear" href="<?= e(url('orders', array_filter(['status' => $status]))) ?>">Clear all</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($orders === []): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-solid fa-bag-shopping"></i></div>
                <h3><?= $allCount === 0 ? 'No orders yet' : 'Nothing here' ?></h3>
                <p><?= $allCount === 0
                    ? 'When a customer orders through your AI chatbot on Telegram, the order appears here. Their payment receipt arrives here too.'
                    : 'No orders match this filter.' ?></p>
            </div>
        <?php else: ?>
            <div class="orders-table-wrap">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th class="num">Total</th>
                            <th>Status</th>
                            <th>Receipt</th>
                            <th class="actions"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <?php
                            $id        = (int) $order['id'];
                            $lines     = $items[$id] ?? [];
                            $name      = trim((string) ($order['customer_name'] ?? '')) ?: 'Telegram ' . $order['customer_chat_id'];
                            $parts     = preg_split('/\s+/u', $name) ?: [$name];
                            $initials  = mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : mb_substr($parts[0], 1, 1)));
                            $hue       = crc32((string) $order['customer_chat_id']) % 360;
                            $sub       = array_filter([
                                !empty($order['customer_username']) ? '@' . $order['customer_username'] : null,
                                !empty($order['customer_phone']) ? $order['customer_phone'] : null,
                            ]);
                            ?>
                            <tr class="orders-row orders-row--<?= e($order['status']) ?>">
                                <td data-label="Order">
                                    <span class="order-no"><?= e($order['order_no']) ?></span>
                                    <small><?= e(date('j M Y · g:i a', strtotime((string) $order['created_at']))) ?></small>
                                </td>
                                <td data-label="Customer">
                                    <div class="order-customer">
                                        <span class="order-avatar" style="--h: <?= (int) $hue ?>" aria-hidden="true"><?= e($initials) ?></span>
                                        <span>
                                            <strong><?= e($name) ?></strong>
                                            <?php if ($sub !== []): ?><small><?= e(implode(' · ', $sub)) ?></small><?php endif; ?>
                                        </span>
                                    </div>
                                </td>
                                <td data-label="Items">
                                    <ul class="order-items">
                                        <?php foreach (array_slice($lines, 0, 2) as $item): ?>
                                            <li><span class="order-qty"><?= (int) $item['quantity'] ?>×</span> <?= e($item['product_name']) ?></li>
                                        <?php endforeach; ?>
                                        <?php if (count($lines) > 2): ?>
                                            <li class="order-more" title="<?= e(implode(', ', array_map(static fn ($i) => $i['quantity'] . ' × ' . $i['product_name'], array_slice($lines, 2)))) ?>">+<?= count($lines) - 2 ?> more</li>
                                        <?php endif; ?>
                                    </ul>
                                    <?php if (!empty($order['delivery_note'])): ?>
                                        <small class="order-note"><i class="fa-regular fa-note-sticky"></i> <?= e($order['delivery_note']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Total" class="num"><strong class="order-total"><?= e(format_money($order['total'], $order['currency'])) ?></strong></td>
                                <td data-label="Status">
                                    <span class="order-status order-status--<?= e($order['status']) ?>"><i aria-hidden="true"></i><?= e(Order::STATUSES[$order['status']] ?? $order['status']) ?></span>
                                    <?php if ($order['status'] === 'payment_rejected' && !empty($order['review_note'])): ?>
                                        <small class="order-reason"><?= e($order['review_note']) ?></small>
                                    <?php elseif ($order['status'] === 'shipped' && !empty($order['shipped_at'])): ?>
                                        <small><?= e(date('j M, g:i a', strtotime((string) $order['shipped_at']))) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Receipt">
                                    <?php if (!empty($order['receipt_path'])): ?>
                                        <a class="order-receipt" href="<?= e(url('orders', ['action' => 'receipt', 'id' => $id])) ?>" target="_blank" rel="noopener">
                                            <i class="fa-regular <?= $order['receipt_mime'] === 'application/pdf' ? 'fa-file-pdf' : 'fa-file-image' ?>"></i> View
                                        </a>
                                    <?php else: ?>
                                        <span class="order-receipt order-receipt--none">Not sent</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <div class="order-actions">
                                        <?php if ($order['status'] === 'payment_submitted' && !empty($order['receipt_path'])): ?>
                                            <form method="post" action="<?= e(url('orders', ['action' => 'approve'])) ?>"
                                                  data-confirm="Mark <?= e($order['order_no']) ?> as Payment successful and tell the customer &quot;Payment verified&quot; on Telegram?"
                                                  data-confirm-title="Approve payment?" data-confirm-ok="Approve">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                                <button class="order-btn order-btn--approve" type="submit"><i class="fa-solid fa-check"></i> Approve</button>
                                            </form>
                                            <details class="order-reject">
                                                <summary class="order-btn order-btn--ghost"><i class="fa-solid fa-xmark"></i> Reject</summary>
                                                <form method="post" action="<?= e(url('orders', ['action' => 'reject'])) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id" value="<?= $id ?>">
                                                    <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                                    <label for="reason-<?= $id ?>">Reason (sent to the customer)</label>
                                                    <input id="reason-<?= $id ?>" name="reason" type="text" maxlength="300" placeholder="e.g. Amount does not match">
                                                    <button class="danger-btn btn-sm" type="submit">Reject payment</button>
                                                </form>
                                            </details>
                                        <?php elseif ($order['status'] === 'paid'): ?>
                                            <form method="post" action="<?= e(url('orders', ['action' => 'ship'])) ?>"
                                                  data-confirm="Mark <?= e($order['order_no']) ?> as Shipped and tell the customer on Telegram?"
                                                  data-confirm-title="Mark as shipped?" data-confirm-ok="Mark as shipped">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                                <button class="order-btn order-btn--ship" type="submit"><i class="fa-solid fa-truck-fast"></i> Mark shipped</button>
                                            </form>
                                        <?php elseif (in_array($order['status'], ['pending_payment', 'payment_rejected', 'payment_submitted'], true)): ?>
                                            <span class="order-wait">Waiting for receipt</span>
                                        <?php else: ?>
                                            <span class="order-wait">—</span>
                                        <?php endif; ?>
                                        <?php if (!in_array($order['status'], ['paid', 'shipped', 'cancelled'], true)): ?>
                                            <form method="post" action="<?= e(url('orders', ['action' => 'cancel'])) ?>"
                                                  data-confirm="Cancel <?= e($order['order_no']) ?>? The customer is told on Telegram."
                                                  data-confirm-title="Cancel order?" data-confirm-ok="Cancel order">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                                <button class="order-btn order-btn--icon" type="submit" title="Cancel order" aria-label="Cancel <?= e($order['order_no']) ?>">
                                                    <i class="fa-solid fa-ban"></i>
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

            <?php if ($pages > 1): ?>
                <nav class="orders-pager" aria-label="Pages">
                    <?php if ($page > 1): ?>
                        <a href="<?= e(url('orders', $listQuery + ['p' => $page - 1])) ?>" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <a href="<?= e(url('orders', $listQuery + ['p' => $i])) ?>"<?= $i === $page ? ' class="is-active" aria-current="page"' : '' ?>><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $pages): ?>
                        <a href="<?= e(url('orders', $listQuery + ['p' => $page + 1])) ?>" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
<script>
(function () {
    var form = document.querySelector('[data-orders-filters]');
    if (!form) { return; }
    var range = form.querySelector('[data-range]');
    var dates = form.querySelector('[data-custom-dates]');
    // Apply as soon as a dropdown changes (custom dates wait for the Apply button).
    form.querySelectorAll('select').forEach(function (sel) {
        sel.addEventListener('change', function () {
            if (sel === range) {
                dates.hidden = range.value !== 'custom';
                if (range.value === 'custom') { dates.querySelector('input').focus(); return; }
            }
            form.submit();
        });
    });
})();
</script>
<?php view('layouts/footer'); ?>
