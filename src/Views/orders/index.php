<?php
/**
 * orders/index.php - orders from the AI chatbot and their payment receipts.
 *
 * Expects: $orders, $items (order id => items), $counts (status => n),
 * $status, $q, $total, $page, $pages, $perPage, $missingTable.
 */
use Src\Models\Order;

view('layouts/header', ['title' => 'Orders', 'styles' => ['assets/css/orders.css']]);

$badges = [
    'pending_payment'   => 'badge--muted',
    'payment_submitted' => 'badge--warn',
    'paid'              => 'badge--success',
    'payment_rejected'  => 'badge--danger',
    'cancelled'         => 'badge--muted',
];
$listQuery = array_filter(['status' => $status, 'q' => $q], static fn ($v) => $v !== '');
$listUrl   = url('orders', $listQuery + ($page > 1 ? ['p' => $page] : []));
$allCount  = array_sum($counts);
$from      = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
$to        = min($total, $page * $perPage);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Orders</h1>
        <p>Orders customers placed with your AI chatbot. Check each payment receipt, then approve it: the customer is told on Telegram.</p>
    </div>
</section>

<div class="page-body">

    <?php if ($missingTable): ?>
        <div class="flash flash--warning" role="status">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <strong>The orders table does not exist yet</strong>
                <span>Run <code>C:\xampp\php\php.exe bin\migrate.php</code> in the project folder, then reload this page.</span>
            </div>
        </div>
    <?php endif; ?>

    <section class="stats-grid stats-grid--3">
        <a class="stat-card pink order-stat" href="<?= e(url('orders', ['status' => 'payment_submitted'])) ?>">
            <div class="stat-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
            <div class="stat-details"><h2><?= (int) $counts['payment_submitted'] ?></h2><p>Receipts to review</p></div>
        </a>
        <a class="stat-card blue order-stat" href="<?= e(url('orders', ['status' => 'pending_payment'])) ?>">
            <div class="stat-icon"><i class="fa-regular fa-hourglass-half"></i></div>
            <div class="stat-details"><h2><?= (int) $counts['pending_payment'] + (int) $counts['payment_rejected'] ?></h2><p>Waiting for the customer to pay</p></div>
        </a>
        <a class="stat-card green order-stat" href="<?= e(url('orders', ['status' => 'paid'])) ?>">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-details"><h2><?= (int) $counts['paid'] ?></h2><p>Paid</p></div>
        </a>
    </section>

    <section class="panel">
        <div class="panel-header">
            <div>
                <h2><?= $status !== '' ? e(Order::STATUSES[$status]) : 'All orders' ?></h2>
                <p>
                    <?php if ($total > 0): ?>
                        Showing <?= $from ?>–<?= $to ?> of <?= (int) $total ?>. Receipts to review are listed first.
                    <?php elseif ($allCount > 0): ?>
                        Nothing matches.
                    <?php else: ?>
                        No orders yet.
                    <?php endif; ?>
                </p>
            </div>
            <span class="badge"><?= (int) $allCount ?> order<?= $allCount === 1 ? '' : 's' ?></span>
        </div>

        <nav class="order-tabs" aria-label="Filter by status">
            <a href="<?= e(url('orders', array_filter(['q' => $q]))) ?>"<?= $status === '' ? ' class="active" aria-current="page"' : '' ?>>All <span><?= (int) $allCount ?></span></a>
            <?php foreach (Order::STATUSES as $key => $label): ?>
                <a href="<?= e(url('orders', array_filter(['status' => $key, 'q' => $q]))) ?>"<?= $status === $key ? ' class="active" aria-current="page"' : '' ?>>
                    <?= e($label) ?> <span><?= (int) $counts[$key] ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($allCount > 0): ?>
            <form class="toolbar" method="get" action="<?= e(url('orders')) ?>">
                <input type="hidden" name="page" value="orders">
                <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
                <div class="toolbar-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <label class="sr-only" for="q">Search</label>
                    <input id="q" name="q" type="search" placeholder="Search order no. or customer..." value="<?= e($q) ?>">
                </div>
                <button class="secondary-btn" type="submit"><i class="fa-solid fa-filter"></i> Search</button>
                <?php if ($q !== ''): ?>
                    <a class="toolbar-reset" href="<?= e(url('orders', array_filter(['status' => $status]))) ?>">Clear</a>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <?php if ($orders === []): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-solid fa-bag-shopping"></i></div>
                <h3><?= $allCount === 0 ? 'No orders yet' : 'Nothing here' ?></h3>
                <p><?= $allCount === 0
                    ? 'When a customer orders through your AI chatbot on Telegram, the order appears here. Their payment receipt arrives here too.'
                    : 'No orders match this filter.' ?></p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table order-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th class="num">Total</th>
                            <th>Status</th>
                            <th>Receipt</th>
                            <th class="actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <?php
                            $id        = (int) $order['id'];
                            $canReview = in_array($order['status'], ['payment_submitted', 'pending_payment', 'payment_rejected'], true);
                            ?>
                            <tr class="<?= $order['status'] === 'payment_submitted' ? 'order-row--review' : '' ?>">
                                <td data-label="Order">
                                    <strong class="order-no"><?= e($order['order_no']) ?></strong>
                                    <small class="muted"><?= e(date('j M Y, g:i a', strtotime((string) $order['created_at']))) ?></small>
                                </td>
                                <td data-label="Customer">
                                    <?= e($order['customer_name'] ?: 'Telegram chat ' . $order['customer_chat_id']) ?>
                                    <?php if (!empty($order['customer_username'])): ?>
                                        <small class="muted">@<?= e($order['customer_username']) ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($order['customer_phone'])): ?>
                                        <small class="muted"><i class="fa-solid fa-phone"></i> <?= e($order['customer_phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Items">
                                    <ul class="order-items">
                                        <?php foreach ($items[$id] ?? [] as $item): ?>
                                            <li><?= (int) $item['quantity'] ?> × <?= e($item['product_name']) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php if (!empty($order['delivery_note'])): ?>
                                        <small class="muted"><i class="fa-regular fa-note-sticky"></i> <?= e($order['delivery_note']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Total" class="num"><strong><?= e(format_money($order['total'], $order['currency'])) ?></strong></td>
                                <td data-label="Status">
                                    <span class="badge <?= e($badges[$order['status']] ?? 'badge--muted') ?>"><?= e(Order::STATUSES[$order['status']] ?? $order['status']) ?></span>
                                    <?php if ($order['status'] === 'payment_rejected' && !empty($order['review_note'])): ?>
                                        <small class="muted"><?= e($order['review_note']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Receipt">
                                    <?php if (!empty($order['receipt_path'])): ?>
                                        <a class="order-receipt" href="<?= e(url('orders', ['action' => 'receipt', 'id' => $id])) ?>" target="_blank" rel="noopener">
                                            <i class="fa-regular <?= $order['receipt_mime'] === 'application/pdf' ? 'fa-file-pdf' : 'fa-image' ?>"></i> View receipt
                                        </a>
                                    <?php else: ?>
                                        <span class="muted">Not sent yet</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions order-actions">
                                    <?php if ($canReview): ?>
                                        <form method="post" action="<?= e(url('orders', ['action' => 'approve'])) ?>"
                                              data-confirm="Mark <?= e($order['order_no']) ?> as paid and tell the customer &quot;Payment verified&quot; on Telegram?"
                                              data-confirm-title="Approve payment?" data-confirm-ok="Approve">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $id ?>">
                                            <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                            <button class="primary-btn btn-sm" type="submit"><i class="fa-solid fa-check"></i> Approve</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($order['status'] === 'payment_submitted'): ?>
                                        <details class="order-reject">
                                            <summary class="secondary-btn btn-sm"><i class="fa-solid fa-xmark"></i> Reject</summary>
                                            <form method="post" action="<?= e(url('orders', ['action' => 'reject'])) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                                <label for="reason-<?= $id ?>">Reason (sent to the customer)</label>
                                                <input id="reason-<?= $id ?>" name="reason" type="text" maxlength="300" placeholder="e.g. Amount does not match">
                                                <button class="danger-btn btn-sm" type="submit">Reject payment</button>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                    <?php if (!in_array($order['status'], ['paid', 'cancelled'], true)): ?>
                                        <form method="post" action="<?= e(url('orders', ['action' => 'cancel'])) ?>"
                                              data-confirm="Cancel <?= e($order['order_no']) ?>? The customer is told on Telegram."
                                              data-confirm-title="Cancel order?" data-confirm-ok="Cancel order">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $id ?>">
                                            <input type="hidden" name="return" value="<?= e($listUrl) ?>">
                                            <button class="icon-btn icon-btn--danger" type="submit" title="Cancel order" aria-label="Cancel <?= e($order['order_no']) ?>">
                                                <i class="fa-solid fa-ban"></i>
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
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <a href="<?= e(url('orders', $listQuery + ['p' => $i])) ?>"<?= $i === $page ? ' class="active" aria-current="page"' : '' ?>><?= $i ?></a>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
<?php view('layouts/footer'); ?>
