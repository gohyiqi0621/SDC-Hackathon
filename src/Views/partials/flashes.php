<?php
/**
 * partials/flashes.php - one-shot session messages.
 * Expects: $flashes = [['type' => ..., 'message' => ..., 'title' => ...]]
 */
$flashIcons = [
    'success' => 'fa-circle-check',
    'error'   => 'fa-circle-exclamation',
    'warning' => 'fa-triangle-exclamation',
    'info'    => 'fa-circle-info',
];

if (empty($flashes)) {
    return;
}
?>
<div class="flashes" aria-live="polite">
    <?php foreach ($flashes as $flash): ?>
        <?php $type = isset($flashIcons[$flash['type'] ?? '']) ? $flash['type'] : 'info'; ?>
        <div class="flash flash--<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
            <i class="fa-solid <?= $flashIcons[$type] ?>"></i>
            <div>
                <?php if (!empty($flash['title'])): ?>
                    <strong><?= e($flash['title']) ?></strong>
                <?php endif; ?>
                <span><?= e($flash['message']) ?></span>
            </div>
        </div>
    <?php endforeach; ?>
</div>
