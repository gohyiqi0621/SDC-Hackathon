<?php
/**
 * errors/error.php - one page for every error code, in v2 markup.
 * abort() provides $code, $title and $message, and wraps this in the app shell
 * (or the bare auth panel) before requiring it.
 */
$copy = [
    403 => 'You do not have permission to view this page.',
    404 => 'We could not find the page you were looking for.',
    405 => 'That action is not allowed with this request method.',
    419 => 'Your session expired before the form was submitted.',
    500 => 'Something broke on our side.',
][$code] ?? 'Something went wrong.';

$hint = [
    403 => 'If you think you should have access, ask an administrator.',
    404 => 'Check the address, or head back to the dashboard.',
    405 => 'Go back and use the button or link you were given.',
    419 => 'Go back, refresh the page and submit the form again.',
    500 => 'Try again in a moment. If it keeps happening, check the Apache error log.',
][$code] ?? '';
?>
<section class="card empty">
    <span class="empty__icon"><?= icon('alert') ?></span>
    <h3><?= e($code) ?> &middot; <?= e($title) ?></h3>
    <p><?= e($copy) ?></p>
    <?php if ($message !== ''): ?>
        <p class="muted"><code><?= e($message) ?></code></p>
    <?php endif; ?>
    <p class="muted"><?= e($hint) ?></p>
    <a class="btn btn--primary" href="<?= e(url('dashboard')) ?>"><?= icon('home', 'icon--sm') ?> Back to dashboard</a>
</section>
