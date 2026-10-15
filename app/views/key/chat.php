<?php
/**
 * key/chat.php - admin read-only review of one user's chat session.
 * Admins only (controller guard). No composer, no sending.
 */
view('layouts/header', ['title' => 'Chat review']);
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title"><?= e((string) $session['title']) ?></h2>
        <p class="page-head__sub">
            <?= e((string) ($session['owner_name'] ?? '—')) ?>
            &middot; <?= e((string) ($session['owner_email'] ?? '')) ?>
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('key')) ?>"><?= icon('chevron-left', 'icon--sm') ?> Back to Key</a>
    </div>
</div>

<div class="notice">
    <?= icon('eye') ?>
    <div>
        <strong>Read-only view</strong>
        <p>Messages here cannot be replied to.</p>
    </div>
</div>

<section class="card">
    <div class="chat-body">
        <?php if ($messages === []): ?>
            <div class="chat-empty" id="chat-empty">
                <span class="empty__icon"><?= icon('sparkle') ?></span>
                <strong>No messages</strong>
                <p>This session has no messages.</p>
            </div>
        <?php else: ?>
            <?php foreach ($messages as $m): ?>
                <?php $isUser = $m['role'] === 'user'; ?>
                <div class="msg msg--<?= $isUser ? 'user' : 'bot' ?>">
                    <?php if (!empty($m['image_name'])): ?>
<small class="muted"><?= e((string) $m['image_name']) ?></small>
                    <?php endif; ?>
                    <?= e((string) $m['body']) ?>
<small class="muted"><?= e(date('j M Y, H:i', strtotime((string) $m['created_at']))) ?></small>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<?php view('layouts/footer'); ?>
