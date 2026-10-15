<?php
/**
 * notification/_row.php - one row of the notification history.
 *
 * Shared by the history table and by the send response, so the audit markup
 * exists once. Expects $n: a notifications row with `sender_name` joined in.
 */
$status = (string) ($n['status'] ?? '');
$tone = match ($status) {
    'sent'      => 'badge--accent',
    'scheduled' => 'badge--warn',
    'failed'    => 'badge--danger',
    default     => '',
};

// Frozen at send time; fall back for rows that predate the column.
$target = trim((string) ($n['audience_label'] ?? ''));
if ($target === '') {
    $target = match ((string) ($n['audience'] ?? 'all')) {
        'user'  => 'User #' . $n['audience_value'],
        'users' => 'Selected users',
        'role'  => ucfirst((string) $n['audience_value']) . 's',
        default => 'Everyone',
    };
}

$when = (string) ($n['scheduled_at'] ?? '') !== ''
    ? db_time((string) $n['scheduled_at'])
    : db_time((string) ($n['created_at'] ?? ''));
?>
<tr>
    <td>
        <div class="table__title">
            <span>
                <span><?= e((string) $n['title']) ?></span>
                <small><?= e(mb_strimwidth((string) $n['body'], 0, 70, '…')) ?></small>
            </span>
        </div>
    </td>
    <td class="muted"><?= e($target) ?></td>
    <td class="muted"><?= e((string) ($n['sender_name'] ?? '—')) ?></td>
    <td class="muted">
        <?= e($when) ?>
        <?php if ($status === 'scheduled'): ?><small>scheduled</small><?php endif; ?>
    </td>
    <td>
        <span class="badge badge--dot <?= e($tone) ?>" title="<?= e((string) ($n['error'] ?? '')) ?>">
            <?= e(ucfirst($status)) ?>
        </span>
    </td>
</tr>
