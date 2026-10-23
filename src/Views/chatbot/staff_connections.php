<?php
/**
 * chatbot/staff_connections.php - Staff Assistant > Connections.
 *
 *   Gmail             for "Check email" in Staff Chat. The same connection as
 *                     the Dashboard inbox (GmailController; ?return=staff
 *                     brings you back here after Google).
 *   Telegram alerts   link your own Telegram to the customer bot, to get "new
 *                     receipt to review" messages (ai_settings/telegram_staff_link).
 *
 * Expects: $gmail [configured, email, connected_at, needed], $telegram [bot_connected,
 *          bot_username, staff_linked, staff_name], $connectUrl, $disconnectUrl,
 *          $linkUrl, $unlinkUrl, $customerConnectionsUrl, $setupUrl, $dashboardUrl.
 */
view('layouts/header', ['title' => 'Staff Assistant Connections', 'styles' => ['assets/css/ai-settings.css']]);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Staff Assistant Connections</h1>
        <p>Accounts the staff assistant uses for you.</p>
    </div>
    <div class="welcome-actions">
        <a class="secondary-btn" href="<?= e($setupUrl) ?>"><i class="fa-solid fa-list-check"></i> Back to setup</a>
    </div>
</section>

<div class="page-body connections-page" data-staff-connections
     data-link-url="<?= e($linkUrl) ?>" data-unlink-url="<?= e($unlinkUrl) ?>">

    <!-- Gmail -->
    <section class="panel integration-card conn-card" id="gmail">
        <div class="integration-head">
            <span class="integration-logo conn-logo conn-logo--gmail" aria-hidden="true"><i class="fa-solid fa-envelope"></i></span>
            <div class="integration-title">
                <h2>Gmail</h2>
                <p>Lets Staff Chat list, summarise and reply to your emails ("Check email"). Also shows your inbox on the Dashboard.</p>
            </div>
            <?php if ($gmail['email'] !== null): ?>
                <span class="conn-pill conn-pill--ok"><i class="fa-solid fa-circle-check"></i> Connected</span>
            <?php else: ?>
                <span class="conn-pill<?= $gmail['needed'] ? ' conn-pill--warn' : '' ?>"><?= $gmail['needed'] ? 'Needed' : 'Not connected' ?></span>
            <?php endif; ?>
        </div>
        <div class="conn-body">
            <?php if (!$gmail['configured']): ?>
                <div class="flash flash--warning"><i class="fa-solid fa-triangle-exclamation"></i>
                    <div><strong>Gmail is not set up on this server</strong><span>Add GMAIL_CLIENT_ID and GMAIL_CLIENT_SECRET (Replit Secrets or .env), then restart.</span></div></div>
            <?php elseif ($gmail['email'] !== null): ?>
                <dl class="conn-facts">
                    <dt>Account</dt><dd><?= e($gmail['email']) ?></dd>
                    <?php if ($gmail['connected_at']): ?><dt>Connected</dt><dd><?= e($gmail['connected_at']) ?></dd><?php endif; ?>
                    <dt>Used by</dt><dd>Staff Chat "Check email"<?= $gmail['needed'] ? '' : ' (switched off in Setup)' ?> · Dashboard inbox</dd>
                </dl>
                <div class="conn-actions">
                    <a class="secondary-btn" href="<?= e($dashboardUrl) ?>"><i class="fa-regular fa-envelope"></i> Open inbox</a>
                    <form method="post" action="<?= e($disconnectUrl) ?>" onsubmit="return confirm('Disconnect Gmail? Staff Chat can no longer check email.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return" value="staff">
                        <button class="link-btn conn-danger" type="submit">Disconnect</button>
                    </form>
                </div>
            <?php else: ?>
                <p class="conn-text"><?= $gmail['needed']
                    ? '"Check email" is switched on, so Staff Chat needs your Gmail.'
                    : 'Only needed if you switch on "Check email" in Staff Assistant Setup.' ?></p>
                <div class="conn-actions">
                    <a class="primary-btn" href="<?= e($connectUrl) ?>"><i class="fa-brands fa-google"></i> Connect Gmail</a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Telegram staff alerts -->
    <section class="panel integration-card conn-card" id="alerts">
        <div class="integration-head">
            <span class="integration-logo integration-logo--telegram" aria-hidden="true"><i class="fa-brands fa-telegram"></i></span>
            <div class="integration-title">
                <h2>Telegram staff alerts</h2>
                <p>Get a message in your own Telegram when a customer sends a payment receipt to review.</p>
            </div>
            <?php if ($telegram['staff_linked']): ?>
                <span class="conn-pill conn-pill--ok"><i class="fa-solid fa-circle-check"></i> Linked</span>
            <?php else: ?>
                <span class="conn-pill">Not linked</span>
            <?php endif; ?>
        </div>
        <div class="conn-body">
            <?php if (!$telegram['bot_connected']): ?>
                <p class="conn-text">Alerts come from your customer Telegram bot, so connect that first.</p>
                <div class="conn-actions">
                    <a class="primary-btn" href="<?= e($customerConnectionsUrl) ?>#telegram"><i class="fa-brands fa-telegram"></i> Connect the Telegram bot</a>
                </div>
            <?php elseif ($telegram['staff_linked']): ?>
                <dl class="conn-facts">
                    <dt>Linked to</dt><dd><?= e($telegram['staff_name'] !== '' ? $telegram['staff_name'] : 'your Telegram') ?></dd>
                    <dt>Bot</dt><dd>@<?= e($telegram['bot_username']) ?></dd>
                </dl>
                <div class="conn-actions">
                    <button class="link-btn conn-danger" type="button" data-staff-unlink>Unlink</button>
                </div>
            <?php else: ?>
                <ol class="conn-steps">
                    <li>Click <b>Link my Telegram</b>. Telegram opens @<?= e($telegram['bot_username']) ?>.</li>
                    <li>Press <b>Start</b> in Telegram.</li>
                    <li>Come back and click <b>Check again</b>.</li>
                </ol>
                <div class="conn-actions">
                    <button class="primary-btn" type="button" data-staff-link><i class="fa-brands fa-telegram"></i> Link my Telegram</button>
                    <button class="secondary-btn" type="button" data-staff-check hidden><i class="fa-solid fa-rotate"></i> Check again</button>
                </div>
            <?php endif; ?>
            <p class="conn-msg" data-staff-msg></p>
        </div>
    </section>
</div>
<?php view('layouts/footer', ['scripts' => ['assets/js/staff-connections.js']]); ?>
