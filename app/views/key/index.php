<?php
/**
 * key/index.php - the admin Key page.
 *
 * Integration configuration is two circular brand icons; clicking one slides
 * its card down beneath them (one open at a time). Everything is stored in the
 * `settings` table via the existing ?page=key&action=save_setting endpoint, so
 * there is no per-integration route.
 *
 * Secrets are rendered as password inputs with the stored value never echoed
 * back - only a mask appears as the placeholder. Leaving a secret field blank
 * keeps the saved value; clearing it is an explicit action via the Clear button.
 *
 * Below the integrations: stored keys (encrypted) and a read-only chat review.
 * Admins only (controller guard).
 */
view('layouts/header', ['title' => 'Key']);

// Group the flat Setting::SCHEMA list into the two integrations. Each entry
// keeps its key/label/hint/set/mask so the form markup stays generic.
$byKey = [];
foreach ($settings as $s) {
    $byKey[$s['key']] = $s;
}
$integrations = [
    [
        'id'    => 'google',
        'name'  => 'Google',
        'desc'  => 'OAuth sign-in for the "Continue with Google" button.',
        'keys'  => ['google_client_id', 'google_secret'],
    ],
    [
        'id'    => 'onesignal',
        'name'  => 'OneSignal',
        'desc'  => 'Web push notifications and the notification bell.',
        'keys'  => ['onesignal_app_id', 'onesignal_api_key'],
    ],
    [
        'id'    => 'agnes',
        'name'  => 'Agnes AI',
        'desc'  => 'Generates the replies in AI Chat, through the Python service.',
        'keys'  => ['agnes_api_key'],
    ],
    [
        'id'       => 'brevo',
        'name'     => 'Brevo',
        'desc'     => 'Sends the one-time codes for "Forgot password".',
        'keys'     => ['brevo_api_key', 'brevo_sender_email', 'brevo_sender_name'],
        // A sender name is a nicety: without it the app name is used, so it
        // must not keep the integration marked "Not configured".
        'optional' => ['brevo_sender_name'],
    ],
];

// Which integrations are fully configured (every required key present).
$isSet = static function (array $entry) use ($byKey): bool {
    $optional = $entry['optional'] ?? [];
    foreach ($entry['keys'] as $k) {
        if (in_array($k, $optional, true)) {
            continue;
        }
        if (empty($byKey[$k]['set'])) {
            return false;
        }
    }
    return true;
};
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title">Key</h2>
        <p class="page-head__sub">Integration credentials. Stored encrypted, never shown in full.</p>
    </div>
    <div class="page-head__actions">
        <div class="seg" role="navigation" aria-label="Admin sections">
            <a class="seg__btn" href="<?= e(url('admin')) ?>">Users</a>
            <a class="seg__btn is-active" href="<?= e(url('key')) ?>">API keys</a>
        </div>
    </div>
</div>

<?php if (!$encrypted): ?>
    <div class="notice notice--warn" role="status">
        <?= icon('alert') ?>
        <div>
            <strong>Encryption is not configured</strong>
            <p>Add a base64 <code>APP_KEY</code> (32 bytes) to <code>.env</code> before storing a secret.</p>
        </div>
    </div>
<?php endif; ?>

<section class="card" aria-labelledby="integrations-title" data-int-root>
    <div class="card__head">
        <div>
            <h3 id="integrations-title">Integrations</h3>
            <p>Select an integration to configure it. One Save at the bottom writes every change.</p>
        </div>
    </div>

    <div class="int-icons" role="tablist" aria-label="Integrations">
        <?php foreach ($integrations as $it):
            $configured = $isSet($it); ?>
            <button class="int-icon<?= $configured ? ' is-configured' : '' ?>"
                    type="button"
                    role="tab"
                    id="tab-<?= e($it['id']) ?>"
                    aria-controls="panel-<?= e($it['id']) ?>"
                    aria-selected="false"
                    aria-expanded="false"
                    data-int-toggle="<?= e($it['id']) ?>">
                <span class="int-icon__logo">
                    <?php if ($it['id'] === 'google'): ?>
                        <svg viewBox="0 0 48 48" aria-hidden="true">
                            <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.5 30.2 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.8 6.1C12.3 13.3 17.7 9.5 24 9.5z"/>
                            <path fill="#4285F4" d="M46.1 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.4c-.5 2.9-2.2 5.3-4.6 7l7.6 5.9c4.4-4.1 6.7-10.1 6.7-17.4z"/>
                            <path fill="#FBBC05" d="M10.4 28.7c-.5-1.5-.8-3-.8-4.7s.3-3.2.8-4.7l-7.8-6.1C.9 16.2 0 20 0 24s.9 7.8 2.6 10.8l7.8-6.1z"/>
                            <path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.6-5.9c-2.1 1.4-4.8 2.3-8.3 2.3-6.3 0-11.7-3.8-13.6-9.2l-7.8 6.1C6.5 42.6 14.6 48 24 48z"/>
                        </svg>
                    <?php elseif ($it['id'] === 'onesignal'): ?>
                        <svg viewBox="0 0 32 32" aria-hidden="true">
                            <circle cx="16" cy="16" r="16" fill="#E54B4D"/>
                            <path fill="#fff" d="M16 6.2a9.8 9.8 0 0 0-9.8 9.8c0 4.3 2.8 8 6.6 9.3.5.1.7-.2.7-.5v-1.7c-2.7.6-3.3-1.3-3.3-1.3-.4-1.1-1.1-1.4-1.1-1.4-.9-.6.1-.6.1-.6 1 .1 1.5 1 1.5 1 .9 1.5 2.3 1.1 2.9.8.1-.6.3-1.1.6-1.3-2.2-.2-4.5-1.1-4.5-4.9 0-1.1.4-2 1-2.7-.1-.3-.4-1.3.1-2.7 0 0 .8-.3 2.7 1a9.3 9.3 0 0 1 5 0c1.9-1.3 2.7-1 2.7-1 .5 1.4.2 2.4.1 2.7.6.7 1 1.6 1 2.7 0 3.8-2.3 4.7-4.5 4.9.4.3.7.9.7 1.9v2.8c0 .3.2.6.7.5a9.8 9.8 0 0 0 6.6-9.3A9.8 9.8 0 0 0 16 6.2z"/>
                        </svg>
                    <?php elseif ($it['id'] === 'brevo'): ?>
                        <svg viewBox="0 0 48 48" aria-hidden="true">
                            <circle cx="24" cy="24" r="24" fill="#0B996E"/>
                            <path fill="#fff" d="M13 17.6 24 25l11-7.4v14.4a2 2 0 0 1-2 2H15a2 2 0 0 1-2-2z" opacity=".9"/>
                            <path fill="#fff" d="M13 17.6V16a2 2 0 0 1 2-2h18a2 2 0 0 1 2 2v1.6L24 25z"/>
                        </svg>
                    <?php else: ?>
                        <svg viewBox="0 0 48 48" aria-hidden="true">
                            <defs>
                                <linearGradient id="agnes-g" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0" stop-color="#6C5CE7"/>
                                    <stop offset="1" stop-color="#00B8D9"/>
                                </linearGradient>
                            </defs>
                            <circle cx="24" cy="24" r="24" fill="url(#agnes-g)"/>
                            <path fill="#fff" d="M24 9.5 12.6 38.5h6.3l2.2-6.1h5.8l2.2 6.1h6.3L24 9.5zm-1.2 17.6 1.2-3.3 1.2 3.3h-2.4z"/>
                            <circle cx="34.6" cy="13.4" r="3.6" fill="#fff" opacity=".92"/>
                        </svg>
                    <?php endif; ?>
                </span>
                <span class="int-icon__name"><?= e($it['name']) ?></span>
                <span class="int-icon__state"><?= $configured ? 'Configured' : 'Not configured' ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <?php /* One form for every integration: the Save button at the bottom writes
             all five fields in a single request. Fields inside a collapsed panel
             still submit, so edits survive closing one panel to open another. */ ?>
    <form method="post" action="<?= e(url('key', ['action' => 'save_all'])) ?>"
          data-ajax autocomplete="off" class="settings-form">
        <?= csrf_field() ?>

    <?php foreach ($integrations as $it): ?>
        <div class="int-panel" id="panel-<?= e($it['id']) ?>" role="tabpanel"
             aria-labelledby="tab-<?= e($it['id']) ?>" data-int-panel="<?= e($it['id']) ?>" hidden>
            <div class="int-panel__inner">
                <div class="int-panel__head">
                    <div>
                        <h4><?= e($it['name']) ?> configuration</h4>
                        <p><?= e($it['desc']) ?></p>
                    </div>
                    <button class="icon-btn" type="button" data-int-close="<?= e($it['id']) ?>" aria-label="Close <?= e($it['name']) ?> settings">
                        <?= icon('x', 'icon--sm') ?>
                    </button>
                </div>

                <?php foreach ($it['keys'] as $k):
                    $s = $byKey[$k] ?? null;
                    if ($s === null) { continue; } ?>
                    <div class="setting-field">
                        <div class="field">
                            <label for="set-<?= e($s['key']) ?>"><?= e($s['label']) ?></label>
                            <div class="set-row">
                                <input id="set-<?= e($s['key']) ?>" name="settings[<?= e($s['key']) ?>]"
                                       type="<?= $s['secret'] ? 'password' : 'text' ?>"
                                       placeholder="<?= $s['set'] ? e($s['mask']) : 'Not set' ?>"
                                       value="<?= $s['secret'] ? '' : e($s['mask']) ?>"
                                       <?= $s['secret'] ? 'autocomplete="new-password"' : '' ?>>
                                <?php if ($s['set']): ?>
                                    <?php /* Sits in this form but belongs to the hidden clear
                                             form below (HTML5 form= attribute), so it posts
                                             on its own without nesting forms. */ ?>
                                    <button class="btn" type="submit" form="clear-<?= e($s['key']) ?>">Clear</button>
                                <?php endif; ?>
                            </div>
                            <p class="field__hint">
                                <?= e($s['hint']) ?>
                                <?php if ($s['set']): ?>
                                    · Saved<?= $s['updater'] ? ' by ' . e($s['updater']) : '' ?><?= $s['updated_at'] ? ' ' . e(date('j M Y', strtotime((string) $s['updated_at']))) : '' ?>
                                <?php else: ?>
                                    · Not set yet
                                <?php endif; ?>
                            </p>
                            <p class="field__error" data-error-for="settings[<?= e($s['key']) ?>]"></p>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ($it['id'] === 'google'): ?>
                    <div class="int-panel__meta">
                        <p class="field__hint">
                            Add this <strong>Authorised redirect URI</strong> in the Google Cloud Console:
                            <code><?= e(GOOGLE_REDIRECT_URI) ?></code>
                        </p>
                    </div>
                <?php elseif ($it['id'] === 'brevo'): ?>
                    <div class="int-panel__meta">
                        <p class="field__hint">
                            Create the key in Brevo under <strong>SMTP &amp; API &rarr; API Keys</strong> (it starts with
                            <code>xkeysib-</code>). The sender address must be a <strong>verified sender</strong>
                            (<strong>Senders &amp; IP &rarr; Senders</strong>) or Brevo rejects the message.
                        </p>
                        <p class="field__hint">
                            Codes are mailed from the
                            <a href="<?= e(url('auth', ['action' => 'forgot'])) ?>" target="_blank" rel="noopener">Forgot password</a>
                            page.
                        </p>
                        <?php /* Belongs to the hidden form after </form> - same trick as the
                                 Clear buttons, because a <form> cannot nest. */ ?>
                        <button class="btn btn--sm" type="submit" form="brevo-test">
                            <?= icon('send', 'icon--sm') ?> Send a test email
                        </button>
                        <p class="field__hint">Sends to the saved sender address. Save first, then test.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

        <div class="form-actions int-save">
            <button class="btn btn--primary" type="submit" data-save-all>Save all changes</button>
        </div>
    </form>

    <?php /* The Brevo "send a test email" form. Its button lives inside the panel
             above and reaches it with form="brevo-test". data-ajax so the result
             arrives as a toast instead of a page reload. */ ?>
    <form id="brevo-test" method="post" action="<?= e(url('key', ['action' => 'test_mail'])) ?>" data-ajax>
        <?= csrf_field() ?>
        <button type="submit" hidden>Send a test email</button>
    </form>

    <?php /* Clear targets live outside the main form - nesting <form> is illegal.
             Each Clear button above reaches its own form with form="clear-<key>",
             so one field can still be wiped without touching the others. */ ?>
    <?php foreach ($byKey as $k => $s): if (!$s['set']) { continue; } ?>
        <form id="clear-<?= e($k) ?>" method="post"
              action="<?= e(url('key', ['action' => 'save_setting'])) ?>"
              data-confirm-submit
              data-confirm-title="Clear <?= e($s['label']) ?>?"
              data-confirm-text="This removes the saved value. The integration will stop working until it is set again.">
            <?= csrf_field() ?>
            <input type="hidden" name="key" value="<?= e($k) ?>">
            <input type="hidden" name="clear" value="1">
            <?php /* Present only so the confirm dialog can borrow the word "Clear". */ ?>
            <button type="submit" hidden>Clear</button>
        </form>
    <?php endforeach; ?>
</section>

<section class="card card--flush">
    <div class="card__head">
        <div>
            <h3>Chat sessions</h3>
            <p>Read-only. Open a session to review the conversation.</p>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col">User</th>
                    <th scope="col">Title</th>
                    <th scope="col">Messages</th>
                    <th scope="col">Updated</th>
                    <th scope="col"><span class="sr-only">Open</span></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($sessions === []): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty">
                                <span class="empty__icon"><?= icon('sparkle') ?></span>
                                <h3>No chat sessions yet</h3>
                                <p>Conversations will appear here as users send messages.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sessions as $s): ?>
                        <tr>
                            <td>
                                <div class="table__title">
                                    <span class="avatar" aria-hidden="true"><?= e(strtoupper(mb_substr((string) ($s['owner_name'] ?? '?'), 0, 1))) ?></span>
                                    <span>
                                        <span><?= e((string) ($s['owner_name'] ?? '—')) ?></span>
                                        <small><?= e((string) ($s['owner_email'] ?? '')) ?></small>
                                    </span>
                                </div>
                            </td>
                            <td class="muted"><?= e((string) $s['title']) ?></td>
                            <td class="muted"><?= e((string) $s['message_count']) ?></td>
                            <td class="muted"><?= e(date('j M Y, H:i', strtotime((string) $s['updated_at']))) ?></td>
                            <td>
                                <span class="row-actions">
                                    <a class="btn btn--sm" href="<?= e(url('key', ['action' => 'chat', 'id' => $s['id']])) ?>">
                                        <?= icon('eye', 'icon--sm') ?> View
                                    </a>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php view('layouts/footer', ['scripts' => [asset('js/key.js')]]); // api.js comes from app_footer ?>
