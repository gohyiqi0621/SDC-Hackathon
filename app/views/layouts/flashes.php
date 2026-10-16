<?php
/**
 * layouts/flashes.php - one-shot session messages, rendered as v2 notices.
 * Expects: $flashes (array of ['type' => 'success|error|warning|info', 'message' => string])
 *
 * A flash may carry its own 'title'. Without one the heading comes from the
 * type below - but a sign-in redirect is type 'error' while not being a
 * failure, so require_login() passes "Sign in to continue" explicitly instead
 * of letting it read as "Something went wrong".
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
    $flashTitle = trim((string) ($f['title'] ?? '')) !== '' ? (string) $f['title'] : $flashLabel;
    ?>
    <div class="notice<?= $type === 'warning' ? ' notice--warn' : '' ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>">
        <?= icon($flashIcon) ?>
        <div><strong><?= e($flashTitle) ?></strong><p><?= e($f['message']) ?></p></div>
    </div>
<?php endforeach; ?>
