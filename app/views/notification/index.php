<?php
/**
 * notification/index.php - admins get a composer + history (the audit log);
 * everyone else gets a read-only feed of what they have received.
 *
 * The composer is a fetch-driven modal: notification.js submits it, closes it on
 * the outcome, and inserts the returned row, so nothing here needs a reload.
 */
view('layouts/header', ['title' => 'Notification']);

$subscriberCount = count($recipients);
?>
<div class="page-head page-head--inline">
    <div>
        <h2 class="page-head__title">Notifications</h2>
        <p class="page-head__sub">
            <?= $isAdmin
                ? 'Send a push now or schedule it for later.'
                : 'Everything sent to you, even if you missed the push.' ?>
        </p>
    </div>
    <?php if ($isAdmin): ?>
        <div class="page-head__actions">
            <?php if ($configured): ?>
                <form method="post" action="<?= e(url('notification', ['action' => 'test'])) ?>" data-ajax>
                    <?= csrf_field() ?>
                    <button class="btn btn--ghost" type="submit">
                        <?= icon('bell', 'icon--sm') ?> Send test to me
                    </button>
                </form>
            <?php endif; ?>
            <button class="btn btn--primary<?= $configured ? '' : ' is-disabled' ?>" type="button"
                    <?= $configured ? 'data-open-modal="#notif-dialog"' : 'disabled' ?>>
                <?= icon('send', 'icon--sm') ?> New notification
            </button>
        </div>
    <?php endif; ?>
</div>

<?php if (!$configured): ?>
    <div class="notice notice--warn" role="status">
        <?= icon('alert') ?>
        <div>
            <strong>Push notifications are not available yet</strong>
            <p>
                This service is not configured yet. Please contact your administrator.
                <?php if ($isAdmin): ?>
                    Add the <strong>OneSignal App ID</strong> and <strong>REST API Key</strong> on the
                    <a href="<?= e(url('key')) ?>">Key</a> page to start sending.
                <?php endif; ?>
            </p>
        </div>
    </div>
<?php endif; ?>

<?php if ($isAdmin): ?>
    <section class="stat-grid" aria-label="Summary">
        <article class="stat">
            <span class="stat__icon"><?= icon('bell') ?></span>
            <p class="stat__label">Total</p>
            <p class="stat__value" data-stat="total"><?= e($stats['total']) ?></p>
            <p class="stat__hint">All time</p>
        </article>
        <article class="stat">
            <span class="stat__icon"><?= icon('check') ?></span>
            <p class="stat__label">Sent</p>
            <p class="stat__value" data-stat="sent"><?= e($stats['sent']) ?></p>
            <p class="stat__hint">Delivered to OneSignal</p>
        </article>
        <article class="stat">
            <span class="stat__icon"><?= icon('calendar') ?></span>
            <p class="stat__label">Scheduled</p>
            <p class="stat__value" data-stat="scheduled"><?= e($stats['scheduled']) ?></p>
            <p class="stat__hint">Waiting to fire</p>
        </article>
        <article class="stat">
            <span class="stat__icon"><?= icon('alert') ?></span>
            <p class="stat__label">Failed</p>
            <p class="stat__value" data-stat="failed"><?= e($stats['failed']) ?></p>
            <p class="stat__hint">Check the error column</p>
        </article>
    </section>
<?php endif; ?>

<?php if ($isAdmin): ?>
    <section class="card card--flush">
        <div class="card__head">
            <div>
                <h3>History</h3>
                <p>Who sent what, to whom, and when. Doubles as the audit log.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Title</th>
                        <th scope="col">Recipients</th>
                        <th scope="col">Sent by</th>
                        <th scope="col">When</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody id="notif-history">
                    <?php if ($history['rows'] === []): ?>
                        <tr id="notif-empty"><td colspan="5">
                            <div class="empty">
                                <span class="empty__icon"><?= icon('bell') ?></span>
                                <h3>Nothing sent yet</h3>
                                <p>Use “New notification” to send your first push.</p>
                            </div>
                        </td></tr>
                    <?php else: foreach ($history['rows'] as $n): ?>
                        <?php view('notification/_row', ['n' => $n]); ?>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($history['pages'] > 1): ?>
            <?php view('partials/pagination', [
                'page'  => $history['page'],
                'pages' => $history['pages'],
                'total' => $history['total'],
                'query' => ['page' => 'notification'],
                'label' => 'notifications',
            ]); ?>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="card">
        <div class="card__head">
            <div>
                <h3>Your notifications</h3>
                <p>Newest first.</p>
            </div>
        </div>
        <?php if ($feed === []): ?>
            <div class="empty">
                <span class="empty__icon"><?= icon('bell') ?></span>
                <h3>Nothing yet</h3>
                <p>Notifications sent to you will show up here.</p>
            </div>
        <?php else: ?>
            <ul class="notif-list" style="list-style:none;margin:0;padding:0">
                <?php foreach ($feed as $n): ?>
                    <li class="notif-item<?= $n['is_read'] ? '' : ' is-unread' ?>">
                        <span class="notif-item__icon"><?= icon('bell') ?></span>
                        <div class="notif-item__main">
                            <p class="notif-item__title"><?= e($n['title']) ?></p>
                            <p class="notif-item__body"><?= e($n['body']) ?></p>
                            <p class="notif-item__meta"><?= e(db_time((string) $n['created_at'])) ?></p>
                        </div>
                        <?php if (!empty($n['url'])): ?>
                            <a class="btn btn--sm" href="<?= e($n['url']) ?>">Open</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($isAdmin): ?>
<dialog class="modal" id="notif-dialog" aria-labelledby="notif-dialog-title">
    <form class="modal__panel" id="notif-form" method="post"
          action="<?= e(url('notification', ['action' => 'send'])) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <div class="modal__head">
            <h3 id="notif-dialog-title">Send a notification</h3>
            <p>Leave the time blank to send immediately.</p>
        </div>

        <div class="modal__scroll">
            <div class="field">
                <label for="notif-title">Title</label>
                <input id="notif-title" name="title" type="text" maxlength="190" required
                       placeholder="e.g. Maintenance tonight">
                <p class="field__error" data-error-for="title"></p>
            </div>

            <div class="field">
                <label for="notif-body">Message</label>
                <textarea id="notif-body" name="body" rows="3" required
                          placeholder="What do you want to say?"></textarea>
                <p class="field__error" data-error-for="body"></p>
            </div>

            <div class="field">
                <label for="notif-url">Link <span class="muted">(optional)</span></label>
                <input id="notif-url" name="url" type="url" placeholder="https://…">
                <p class="field__hint">Opened when the notification is clicked.</p>
            </div>

            <fieldset class="field">
                <legend>Recipients</legend>
                <div class="choice-group">
                    <label class="choice">
                        <input type="radio" name="audience" value="all" checked data-notif-audience>
                        <span class="choice__text">
                            <span>Everyone</span>
                            <small>Every device subscribed to this app</small>
                        </span>
                    </label>
                    <label class="choice<?= $subscriberCount === 0 ? ' is-disabled' : '' ?>">
                        <input type="radio" name="audience" value="users" data-notif-audience
                               <?= $subscriberCount === 0 ? 'disabled' : '' ?>>
                        <span class="choice__text">
                            <span>Selected users</span>
                            <small>
                                <?= $subscriberCount === 0
                                    ? 'Nobody is subscribed yet'
                                    : $subscriberCount . ' subscribed user' . ($subscriberCount === 1 ? '' : 's') ?>
                            </small>
                        </span>
                    </label>
                </div>
                <p class="field__error" data-error-for="audience"></p>
            </fieldset>

            <div class="field" data-notif-picker hidden>
                <div class="field__head">
                    <label>Who should receive it?</label>
                    <button class="btn btn--sm btn--ghost" type="button" data-notif-toggle-all>Select all</button>
                </div>
                <div class="picker">
                    <?php foreach ($recipients as $r): ?>
                        <label class="picker__row">
                            <input type="checkbox" name="recipients[]" value="<?= (int) $r['id'] ?>"
                                   data-devices="<?= (int) $r['devices'] ?>" data-notif-recipient>
                            <span class="picker__text">
                                <span><?= e($r['name']) ?></span>
                                <small><?= e($r['email']) ?></small>
                            </span>
                            <em><?= (int) $r['devices'] ?> device<?= $r['devices'] === 1 ? '' : 's' ?></em>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="field__hint" data-notif-recipient-count>No one selected yet.</p>
                <p class="field__error" data-error-for="recipients"></p>
            </div>

            <div class="field">
                <label for="notif-when">Schedule <span class="muted">(optional)</span></label>
                <input id="notif-when" name="scheduled_at" type="datetime-local">
                <div class="chip-row">
                    <button class="chip" type="button" data-notif-when="5">In 5 minutes</button>
                    <button class="chip" type="button" data-notif-when="60">In 1 hour</button>
                    <button class="chip" type="button" data-notif-when="tomorrow">Tomorrow 9am</button>
                    <button class="chip" type="button" data-notif-when="clear">Clear</button>
                </div>
                <p class="field__hint" data-notif-when-hint>Leave blank to send now.</p>
                <p class="field__error" data-error-for="scheduled_at"></p>
            </div>
        </div>

        <div class="modal__foot">
            <button class="btn" type="button" data-close-modal>Cancel</button>
            <button class="btn btn--primary" type="submit" data-notif-submit>
                <?= icon('send', 'icon--sm') ?> Send
            </button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php view('layouts/footer', ['scripts' => [$isAdmin ? asset('js/notification.js') : asset('js/api.js')]]); ?>
