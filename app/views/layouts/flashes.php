<?php
/**
 * layouts/flashes.php - one-shot session messages, rendered as v2 notices.
 * Expects: $flashes (array of ['type' => 'success|error|warning|info', 'message' => string])
 */
$flashCopy = [
    'success' => ['check', 'Done'],
    'error'   => ['alert', 'Something went wrong'],
    'warning' => ['alert', 'Heads up'],
    'info'    => ['info',  'Note'],
];
foreach ($flashes as $f):
    $type = in_array($f['type'], ['success', 'error', 'warning', 'info'], true) ? $f['type'] : 'info';
    [$flashIcon, $flashLabel] = $flashCopy[$type];
    ?>
    <div class="notice<?= $type === 'warning' ? ' notice--warn' : '' ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
        <?= icon($flashIcon) ?>
        <div><strong><?= e($flashLabel) ?></strong><p><?= e($f['message']) ?></p></div>
    </div>
<?php endforeach; ?>
