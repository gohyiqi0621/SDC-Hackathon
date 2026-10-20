<?php
/**
 * errors/error.php - status page body for abort().
 * Expects: $code, $title, $message, $signedIn. Wrapped by the app or auth shell.
 */
$signedIn = $signedIn ?? !empty($_SESSION['user_id']);
$body     = $message !== '' ? $message : 'The page you asked for is not available.';
$home     = $signedIn ? url('dashboard') : url('auth', ['action' => 'login']);
?>
<?php if ($signedIn): ?><div class="page-body" style="padding-top:32px"><?php endif; ?>

<section class="<?= $signedIn ? 'panel' : 'auth-card' ?>">
    <div class="empty-state">
        <div class="error-code"><?= (int) $code ?></div>
        <h3><?= e($title) ?></h3>
        <p><?= e($body) ?></p>
        <a class="primary-btn" href="<?= e($home) ?>">
            <i class="fa-solid <?= $signedIn ? 'fa-house' : 'fa-lock' ?>"></i>
            <?= $signedIn ? 'Back to dashboard' : 'Back to sign in' ?>
        </a>
    </div>
</section>

<?php if ($signedIn): ?></div><?php endif; ?>
