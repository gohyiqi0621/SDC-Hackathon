<?php
/**
 * dashboard/index.php - stat cards, a 30-day chart and recent items.
 * The chart is fed from a JSON block so the page needs no extra request.
 * Markup follows the v2 design system exactly.
 */
view('layouts/header', ['title' => 'Dashboard']);

$isAdmin = is_admin();

// The two roles get genuinely different dashboards, not just different labels.
if ($isAdmin) {
    $cards = [
        [
            'label' => 'Accounts',
            'value' => $stats['users'],
            'icon'  => 'users',
            'hint'  => $admin['admins'] . ' admin · ' . $admin['disabled'] . ' disabled',
        ],
        [
            'label' => 'Items',
            'value' => $stats['items_total'],
            'icon'  => 'items',
            'hint'  => $stats['items_active'] . ' active across all users',
        ],
        [
            'label' => 'AI messages',
            'value' => $stats['ai_calls'],
            'icon'  => 'sparkle',
            'hint'  => 'All users, all time',
        ],
        [
            'label' => 'Stored keys',
            'value' => $admin['keys'],
            'icon'  => 'key',
            'hint'  => 'Manage on the Key page',
        ],
    ];
} else {
    $cards = [
        [
            'label' => 'Your items',
            'value' => $stats['items_total'],
            'icon'  => 'items',
            'hint'  => $stats['items_active'] . ' currently active',
        ],
        [
            'label' => 'AI messages',
            'value' => $stats['ai_calls'],
            'icon'  => 'sparkle',
            'hint'  => 'Your conversations',
        ],
        [
            'label' => 'Your role',
            'value' => ucfirst($user['role']),
            'icon'  => 'shield',
            'hint'  => $user['email'],
        ],
        [
            'label' => 'Member since',
            'value' => date('j M Y', strtotime((string) $user['created_at'])),
            'icon'  => 'calendar',
            'hint'  => 'Welcome back',
        ],
    ];
}

$statusBadge = static function (string $status): string {
    $map = ['active' => 'badge--ok', 'draft' => 'badge--warn', 'archived' => ''];
    return $map[$status] ?? '';
};
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title">Hello, <?= e($user['name']) ?></h2>
        <p class="page-head__sub">
            <?php if ($isAdmin): ?>
                Here is what has been happening across the whole app.
            <?php else: ?>
                Here is what has been happening in your own items.
            <?php endif; ?>
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('items')) ?>"><?= icon('items', 'icon--sm') ?> View items</a>
        <?php if ($isAdmin): ?>
            <a class="btn btn--primary" href="<?= e(url('key')) ?>"><?= icon('key', 'icon--sm') ?> Manage keys</a>
        <?php else: ?>
            <a class="btn btn--primary" href="<?= e(url('ai')) ?>"><?= icon('sparkle', 'icon--sm') ?> Ask the AI</a>
        <?php endif; ?>
    </div>
</div>

<section class="overview liquid-glass" aria-label="Overview">
    <div>
        <p class="overview__label"><?= $isAdmin ? 'All items' : 'Your items' ?></p>
        <p class="overview__value"><?= e($stats['items_total']) ?></p>
        <p class="overview__sub">
            <?php if ($isAdmin): ?>
                <?= e($stats['items_active']) ?> are active right now, across every account.
            <?php else: ?>
                <?= e($stats['items_active']) ?> are active right now. Everything you create is saved to your account and available to the AI assistant.
            <?php endif; ?>
        </p>
    </div>
    <div class="overview__meta">
        <span class="badge badge--accent badge--dot"><?= e(ucfirst((string) $user['role'])) ?></span>
        <span class="badge"><?= e($stats['ai_calls']) ?> AI messages</span>
        <?php if ($isAdmin): ?>
            <span class="badge"><?= e($admin['keys']) ?> keys stored</span>
        <?php endif; ?>
    </div>
</section>

<section class="stat-grid" aria-label="Summary">
    <?php foreach ($cards as $card): ?>
        <article class="stat">
            <span class="stat__icon"><?= icon($card['icon']) ?></span>
            <p class="stat__label"><?= e($card['label']) ?></p>
            <p class="stat__value"><?= e($card['value']) ?></p>
            <p class="stat__hint"><?= e($card['hint']) ?></p>
        </article>
    <?php endforeach; ?>
</section>

<div class="dash-grid">
    <section class="card">
        <div class="card__head">
            <div>
                <h3>Activity</h3>
                <p>Items created and AI calls per day</p>
            </div>
            <div class="seg" role="group" aria-label="Chart range">
                <button type="button" class="seg__btn is-active" data-range="7">7 days</button>
                <button type="button" class="seg__btn" data-range="14">14 days</button>
                <button type="button" class="seg__btn" data-range="30">30 days</button>
            </div>
        </div>
        <div class="chart-wrap">
            <canvas id="activity-chart" role="img" aria-label="Items and AI calls over time"></canvas>
        </div>
        <p class="chart-fallback muted" hidden>Chart.js could not load. The dashboard still works without it.</p>
    </section>

    <section class="card">
        <div class="card__head">
            <h3>Recent items</h3>
            <a class="link" href="<?= e(url('items')) ?>">See all</a>
        </div>
        <?php if ($recent === []): ?>
            <div class="empty">
                <span class="empty__icon"><?= icon('items') ?></span>
                <h3>No items yet</h3>
                <p>Create the first one and it will show up here.</p>
                <a class="btn btn--primary" href="<?= e(url('items', ['action' => 'create'])) ?>">Create the first one</a>
            </div>
        <?php else: ?>
            <ul class="activity">
                <?php foreach ($recent as $item): ?>
                    <li>
                        <span class="dot"></span>
                        <span><strong><?= e($item['title']) ?></strong></span>
                        <span class="badge <?= $statusBadge((string) $item['status']) ?>"><?= e($item['status']) ?></span>
                        <time><?= e(date('j M', strtotime((string) $item['created_at']))) ?></time>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<section class="quick" aria-label="Quick actions">
    <a class="quick__item" href="<?= e(url('items', ['action' => 'create'])) ?>">
        <span class="stat__icon"><?= icon('plus') ?></span>
        <strong>New item</strong><span>Create something to track</span>
    </a>
    <?php if ($isAdmin): ?>
        <a class="quick__item" href="<?= e(url('key')) ?>">
            <span class="stat__icon"><?= icon('key') ?></span>
            <strong>API keys</strong><span>Add or revoke a secret</span>
        </a>
    <?php else: ?>
        <a class="quick__item" href="<?= e(url('ai')) ?>">
            <span class="stat__icon"><?= icon('sparkle') ?></span>
            <strong>Ask the AI</strong><span>Chat or send a photo</span>
        </a>
    <?php endif; ?>
    <a class="quick__item" href="<?= e(url('profile')) ?>">
        <span class="stat__icon"><?= icon('user') ?></span>
        <strong>Your profile</strong><span>Details and password</span>
    </a>
</section>

<?php if ($isAdmin): ?>
    <section class="card">
        <div class="card__head">
            <div>
                <h3>Recent chats</h3>
                <p>Read-only. Open the Key page to review a conversation.</p>
            </div>
            <a class="link" href="<?= e(url('key')) ?>">Manage keys</a>
        </div>
        <?php if ($admin['chats'] === []): ?>
            <div class="empty">
                <span class="empty__icon"><?= icon('sparkle') ?></span>
                <h3>No chat sessions yet</h3>
                <p>Conversations will appear here as users send messages.</p>
            </div>
        <?php else: ?>
            <ul class="activity">
                <?php foreach ($admin['chats'] as $s): ?>
                    <li>
                        <span class="dot"></span>
                        <span><strong><?= e((string) ($s['owner_name'] ?? '—')) ?></strong></span>
                        <span class="badge"><?= e((string) $s['message_count']) ?> msgs</span>
                        <time><?= e(date('j M, H:i', strtotime((string) $s['updated_at']))) ?></time>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php
// HEX flags keep the payload safe if a label ever contains markup.
$chartJson = json_encode($series, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<script type="application/json" id="activity-data"><?= $chartJson ?></script>

<?php view('layouts/footer', ['scripts' => [
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
    asset('js/dashboard.js'),
]]); ?>
