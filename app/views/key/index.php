<?php
/**
 * key/index.php - the admin Key page: add, list (masked) and revoke API keys,
 * plus a read-only list of user chat sessions. Admins only (controller guard).
 */
view('layouts/header', ['title' => 'Key']);
?>
<div class="page-head">
    <div>
        <h2 class="page-head__title">Key</h2>
        <p class="page-head__sub">Secrets used by the AI service. Stored encrypted, never shown in full.</p>
    </div>
    <div class="page-head__actions">
        <div class="seg" role="navigation" aria-label="Admin sections">
            <a class="seg__btn" href="<?= e(url('admin')) ?>">Users</a>
            <a class="seg__btn is-active" href="<?= e(url('key')) ?>">API keys</a>
        </div>
        <button class="btn btn--primary" type="button" data-open-modal="#key-dialog">
            <?= icon('plus', 'icon--sm') ?> Add key
        </button>
    </div>
</div>

<?php if (!$encrypted): ?>
    <div class="notice notice--warn" role="status">
        <?= icon('alert') ?>
        <div>
            <strong>Encryption is not configured</strong>
            <p>Add a base64 <code>APP_KEY</code> (32 bytes) to <code>.env</code> before storing a key.</p>
        </div>
    </div>
<?php endif; ?>

<section class="stat-grid" aria-label="Summary">
    <article class="stat">
        <span class="stat__icon"><?= icon('key') ?></span>
        <p class="stat__label">Stored keys</p>
        <p class="stat__value"><?= e($stats['keys']) ?></p>
        <p class="stat__hint">Encrypted at rest</p>
    </article>
    <article class="stat">
        <span class="stat__icon"><?= icon('lock') ?></span>
        <p class="stat__label">Configured</p>
        <p class="stat__value"><?= e($stats['settings']) ?></p>
        <p class="stat__hint">Keys &amp; IDs saved</p>
    </article>
    <article class="stat">
        <span class="stat__icon"><?= icon('items') ?></span>
        <p class="stat__label">Messages</p>
        <p class="stat__value"><?= e($stats['messages']) ?></p>
        <p class="stat__hint">All time</p>
    </article>
    <article class="stat">
        <span class="stat__icon"><?= icon('shield') ?></span>
        <p class="stat__label">Admins</p>
        <p class="stat__value"><?= e($stats['admins']) ?></p>
        <p class="stat__hint">Can manage keys</p>
    </article>
</section>

<section class="card">
    <div class="card__head">
        <div>
            <h3>Configuration</h3>
            <p>App settings stored in the database. Secrets are encrypted and never shown in full.</p>
        </div>
    </div>

    <?php foreach ($settings as $s): ?>
        <form method="post" action="<?= e(url('key', ['action' => 'save_setting'])) ?>" data-ajax autocomplete="off" class="settings-form">
            <?= csrf_field() ?>
            <input type="hidden" name="key" value="<?= e($s['key']) ?>">
            <div class="field">
                <label for="set-<?= e($s['key']) ?>"><?= e($s['label']) ?></label>
                <div class="set-row">
                    <input id="set-<?= e($s['key']) ?>" name="value"
                           type="<?= $s['secret'] ? 'password' : 'text' ?>"
                           placeholder="<?= $s['set'] ? e($s['mask']) : 'Not set' ?>"
                           value="<?= $s['secret'] ? '' : e($s['mask']) ?>">
                    <button class="btn btn--primary" type="submit">Save</button>
                </div>
                <p class="field__hint">
                    <?= e($s['hint']) ?>
                    <?php if ($s['set']): ?>
                        · Saved<?= $s['updater'] ? ' by ' . e($s['updater']) : '' ?><?= $s['updated_at'] ? ' ' . e(date('j M Y', strtotime((string) $s['updated_at']))) : '' ?>
                    <?php else: ?>
                        · Not set yet
                    <?php endif; ?>
                </p>
                <p class="field__error" data-error-for="value"></p>
            </div>
        </form>
    <?php endforeach; ?>
</section>

<section class="card">
    <div class="card__head">
        <div>
            <h3>Saved keys</h3>
            <p>Non-editable. Revoke to remove and replace.</p>
        </div>
        <span class="badge"><?= e($stats['keys']) ?> key<?= $stats['keys'] === 1 ? '' : 's' ?></span>
    </div>

    <?php if ($keys === []): ?>
        <div class="empty">
            <span class="empty__icon"><?= icon('key') ?></span>
            <h3>No keys stored yet</h3>
            <p>Add one with the button above. It is encrypted before it is saved.</p>
        </div>
    <?php else: ?>
        <ul class="key-list" style="list-style:none;margin:0;padding:0">
            <?php foreach ($keys as $key): ?>
                <li class="key-row">
                    <span class="key-row__icon"><?= icon('key') ?></span>
                    <div class="key-row__main">
                        <p class="key-row__name"><?= e($key['label']) ?></p>
                        <p class="key-row__meta">
                            Added by <?= e((string) ($key['creator_name'] ?? '—')) ?> · <?= e(date('j M Y', strtotime((string) $key['created_at']))) ?>
                        </p>
                    </div>
                    <span class="key-mask"><?= e(mask_secret((string) $key['last4'])) ?></span>
                    <span class="row-actions">
                        <form method="post" action="<?= e(url('key', ['action' => 'revoke'])) ?>"
                              data-confirm-submit
                              data-confirm-title="Revoke key?"
                              data-confirm-text="&ldquo;<?= e($key['label']) ?>&rdquo; will stop working immediately.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $key['id'] ?>">
                            <button class="icon-btn icon-btn--flat" type="submit" aria-label="Revoke <?= e($key['label']) ?>">
                                <?= icon('trash', 'icon--sm') ?>
                            </button>
                        </form>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
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

<dialog class="modal" id="key-dialog" aria-labelledby="key-dialog-title">
    <form class="modal__panel" method="post" action="<?= e(url('key', ['action' => 'store'])) ?>" data-ajax autocomplete="off">
        <?= csrf_field() ?>
        <div class="modal__head">
            <h3 id="key-dialog-title">Add API key</h3>
            <p>The secret is encrypted when you save it.</p>
        </div>
        <div class="field">
            <label for="key-label">Key label</label>
            <input id="key-label" name="label" type="text" maxlength="100" required
                   placeholder="e.g. OpenAI production" autocomplete="off">
            <p class="field__error" data-error-for="label"></p>
        </div>
        <div class="field">
            <label for="key-secret">Secret key</label>
            <input id="key-secret" name="secret" type="password" required autocomplete="off"
                   placeholder="sk-...">
            <p class="field__hint">Paste it once. You will only see a masked version afterwards.</p>
            <p class="field__error" data-error-for="secret"></p>
        </div>
        <div class="modal__foot">
            <button class="btn" type="button" data-close-modal>Cancel</button>
            <button class="btn btn--primary" type="submit">Save key</button>
        </div>
    </form>
</dialog>

<?php view('layouts/footer', ['scripts' => [asset('js/api.js')]]); ?>
