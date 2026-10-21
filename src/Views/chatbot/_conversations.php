<?php
/**
 * chatbot/_conversations.php - Chat Interface > Customer conversations.
 *
 * View only: every conversation the AI had with a customer (Telegram, and the
 * website test chat), newest first. Selecting one shows the full exchange,
 * including order events (QR sent, receipt received, payment verified), and
 * that customer's latest orders. Nothing here can send a message.
 *
 * Expects: $conversations (ChatMessage::conversations), $thread (selected
 * conversation + messages + orders, or null), $tabUrls.
 */
$statusBadge = [
    'pending_payment'   => ['Awaiting payment', 'badge--muted'],
    'payment_submitted' => ['Receipt to review', 'badge--warn'],
    'paid'              => ['Paid', 'badge--success'],
    'payment_rejected'  => ['Payment rejected', 'badge--danger'],
    'cancelled'         => ['Cancelled', 'badge--muted'],
];
$labelFor = static function (array $c): string {
    if ($c['channel'] === 'web') {
        return 'Website test chat';
    }
    $name = trim((string) ($c['customer_name'] ?? ''));
    return $name !== '' ? $name : 'Telegram chat ' . $c['chat_id'];
};
$selectedKey = $thread !== null ? $thread['channel'] . ':' . $thread['chat_id'] : '';
?>
<?php if ($conversations === []): ?>
    <section class="panel">
        <div class="empty-state">
            <div class="empty-icon"><i class="fa-regular fa-comments"></i></div>
            <h3>No customer conversations yet</h3>
            <p>When customers message your chatbot on Telegram, every message and reply appears here.</p>
        </div>
    </section>
<?php else: ?>
<div class="convo-layout">
    <section class="panel convo-list" aria-label="Conversations">
        <div class="panel-header">
            <div>
                <h2>Conversations</h2>
                <p><?= count($conversations) ?> customer<?= count($conversations) === 1 ? '' : 's' ?> · newest first</p>
            </div>
        </div>
        <ul>
            <?php foreach ($conversations as $c): ?>
                <?php $key = $c['channel'] . ':' . $c['chat_id']; ?>
                <li>
                    <a href="<?= e(url('customer', ['action' => 'chat', 'tab' => 'conversations', 'chat' => $key])) ?>"<?= $key === $selectedKey ? ' class="active" aria-current="true"' : '' ?>>
                        <span class="convo-avatar convo-avatar--<?= e($c['channel']) ?>">
                            <i class="<?= $c['channel'] === 'telegram' ? 'fa-brands fa-telegram' : 'fa-solid fa-globe' ?>"></i>
                        </span>
                        <span class="convo-text">
                            <span class="convo-row">
                                <strong><?= e($labelFor($c)) ?></strong>
                                <time datetime="<?= e(date(DATE_ATOM, strtotime((string) $c['last_at']))) ?>"><?= e(date('j M, g:i a', strtotime((string) $c['last_at']))) ?></time>
                            </span>
                            <small><?= $c['last_role'] === 'assistant' ? 'AI: ' : '' ?><?= e(mb_strimwidth(str_replace("\n", ' ', (string) $c['last_message']), 0, 70, '…')) ?></small>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <?php if ($thread !== null): ?>
    <section class="panel convo-thread" aria-label="Conversation">
        <div class="chat-head">
            <span class="chat-head-avatar"><i class="<?= $thread['channel'] === 'telegram' ? 'fa-brands fa-telegram' : 'fa-solid fa-globe' ?>"></i></span>
            <div>
                <strong><?= e($labelFor($thread)) ?></strong>
                <span>
                    <?= !empty($thread['customer_username']) ? '@' . e($thread['customer_username']) . ' · ' : '' ?>
                    <?= $thread['channel'] === 'telegram' ? 'Telegram chat ' . e($thread['chat_id']) : 'Website' ?>
                    · <?= (int) $thread['messages'] ?> messages
                </span>
            </div>
            <span class="badge"><i class="fa-regular fa-eye"></i> View only</span>
        </div>

        <div class="chat-body convo-body">
            <?php $lastDay = ''; ?>
            <?php foreach ($thread['history'] as $m): ?>
                <?php $day = date('j M Y', strtotime((string) $m['created_at'])); ?>
                <?php if ($day !== $lastDay): $lastDay = $day; ?>
                    <div class="convo-day"><span><?= e($day) ?></span></div>
                <?php endif; ?>
                <?php $isEvent = preg_match('/^(📎|🖼|🧾|✅|⚠️)/u', (string) $m['content']) === 1; ?>
                <div class="chat-bubble chat-bubble--<?= $m['role'] === 'customer' ? 'me' : 'bot' ?><?= $m['status'] !== 'ok' ? ' chat-bubble--muted' : '' ?><?= $isEvent ? ' chat-bubble--event' : '' ?>">
                    <?= nl2br(e($m['content'])) ?>
                    <time><?= e(date('g:i a', strtotime((string) $m['created_at']))) ?><?= $m['status'] === 'error' ? ' · fallback reply' : ($m['status'] === 'skipped' ? ' · not sent' : '') ?></time>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="convo-readonly"><i class="fa-solid fa-lock"></i> View only. Customers are answered by the AI on Telegram.</p>
    </section>

    <aside class="panel convo-orders" aria-label="This customer's orders">
        <div class="panel-header">
            <div>
                <h2>Orders</h2>
                <p>This customer's latest orders</p>
            </div>
        </div>
        <div class="panel-body">
            <?php if ($thread['orders'] === []): ?>
                <p class="muted">No orders from this customer yet.</p>
            <?php else: ?>
                <ul class="convo-order-list">
                    <?php foreach ($thread['orders'] as $o): ?>
                        <?php [$sLabel, $sClass] = $statusBadge[$o['status']] ?? [$o['status'], 'badge--muted']; ?>
                        <li>
                            <div class="convo-row">
                                <strong class="order-no"><?= e($o['order_no']) ?></strong>
                                <span class="badge <?= e($sClass) ?>"><?= e($sLabel) ?></span>
                            </div>
                            <small><?= e(implode(', ', array_map(static fn (array $i): string => (int) $i['quantity'] . ' × ' . $i['product_name'], $o['items']))) ?></small>
                            <div class="convo-row">
                                <small class="muted"><?= e(date('j M, g:i a', strtotime((string) $o['created_at']))) ?></small>
                                <strong><?= e(format_money($o['total'], $o['currency'])) ?></strong>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a class="secondary-btn convo-orders-link" href="<?= e(url('orders', ['q' => (string) $thread['chat_id']])) ?>">
                    <i class="fa-solid fa-bag-shopping"></i> Open in Orders
                </a>
            <?php endif; ?>
        </div>
    </aside>
    <?php endif; ?>
</div>
<?php endif; ?>
