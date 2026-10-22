<?php
/**
 * chatbot/staff_chat.php - Staff > Chat Interface.
 *
 * Same messenger look as Customer > Chat Interface (conversations.css), but
 * the staff member is chatting: their messages on the right, the assistant's
 * on the left, and a composer at the bottom. Messages go to
 * POST staff/chat_send (StaffAssistantService -> staff Flowise chatflow).
 *
 * Expects: $assistant, $model, $enabled, $problems, $functions
 * [label, icon, state on|off|soon], $suggestions, $history [role me|ai,
 * content, status, at], $sendUrl, $previewUrl, $settingsUrl, $chatflowSet.
 */
view('layouts/header', ['title' => $title, 'styles' => ['assets/css/conversations.css']]);
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/u', trim($name)) ?: [$name];
    return mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : mb_substr($parts[0], 1, 1)));
};
?>
<div class="page-body">
    <section class="msgr msgr--staff" data-staff-chat
             data-send-url="<?= e($sendUrl) ?>" data-preview-url="<?= e($previewUrl) ?>">

        <!-- Left: the assistant and what it can do -->
        <aside class="msgr-side staff-side" aria-label="Staff assistant">
            <div class="msgr-side__head">
                <div>
                    <h1>Staff chat</h1>
                    <p>
                        <?php if ($enabled): ?>
                            <span class="msgr-live"><i></i> Ready</span>
                        <?php else: ?>
                            <span class="msgr-live is-off"><i></i> Off</span>
                        <?php endif; ?>
                        · <?= e($model) ?>
                    </p>
                </div>
                <a class="icon-btn" href="<?= e($settingsUrl) ?>" title="Setting &amp; Integration" aria-label="Setting &amp; Integration">
                    <i class="fa-solid fa-sliders"></i>
                </a>
            </div>

            <div class="staff-side__body">
                <?php if (!$enabled || $problems !== []): ?>
                    <div class="staff-alert">
                        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                        <div>
                            <strong><?= $enabled ? 'Check the setup' : 'The staff assistant is off' ?></strong>
                            <?php foreach ($problems as $p): ?><small><?= e($p) ?></small><?php endforeach; ?>
                            <?php if (!$chatflowSet): ?><small>FLOWISE_STAFF_CHATFLOW_ID is not set, so the customer chatflow is used.</small><?php endif; ?>
                            <a href="<?= e($settingsUrl) ?>">Open Setting &amp; Integration</a>
                        </div>
                    </div>
                <?php elseif (!$chatflowSet): ?>
                    <div class="staff-alert">
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                        <div><strong>Using the customer chatflow</strong><small>Set FLOWISE_STAFF_CHATFLOW_ID to use your staff chatflow.</small></div>
                    </div>
                <?php endif; ?>

                <h2 class="staff-side__title">Can look up</h2>
                <ul class="staff-functions" role="list">
                    <?php foreach ($functions as $f): ?>
                        <li class="staff-function staff-function--<?= e($f['state']) ?>">
                            <span class="staff-function__icon"><i class="<?= e($f['icon']) ?>" aria-hidden="true"></i></span>
                            <span><?= e($f['label']) ?></span>
                            <small><?= $f['state'] === 'on' ? 'On' : ($f['state'] === 'soon' ? 'Soon' : 'Off') ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($suggestions !== []): ?>
                    <h2 class="staff-side__title">Try asking</h2>
                    <div class="staff-suggest">
                        <?php foreach ($suggestions as $s): ?>
                            <button type="button" class="staff-chip" data-suggest><?= e($s) ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <button type="button" class="staff-link" data-preview-open>
                    <i class="fa-regular fa-file-lines" aria-hidden="true"></i> View what Flowise receives
                </button>
            </div>
        </aside>

        <!-- Right: the conversation -->
        <div class="msgr-main">
            <header class="msgr-head">
                <span class="msgr-avatar msgr-avatar--bot" aria-hidden="true"><i class="fa-solid fa-user-tie"></i></span>
                <div class="msgr-head__text">
                    <strong><?= e($assistant) ?></strong>
                    <small data-status>Internal assistant · answers from your invoices, claims and orders</small>
                </div>
                <a class="icon-btn staff-head-settings" href="<?= e($settingsUrl) ?>" aria-label="Setting &amp; Integration"><i class="fa-solid fa-sliders"></i></a>
            </header>

            <div class="msgr-body" data-body aria-live="polite">
                <div class="msgr-msg msgr-msg--peer staff-welcome" data-welcome>
                    <div class="msgr-bubble">
                        <div class="msgr-bubble__text">Hi <?= e(explode(' ', trim((string) ($user['name'] ?? '')))[0] ?: 'there') ?>! Ask me about your <?= e(implode(', ', array_map(static fn (array $f): string => strtolower(str_replace('Check ', '', $f['label'])), array_filter($functions, static fn (array $f): bool => $f['state'] === 'on'))) ?: 'records') ?>.</div>
                    </div>
                </div>
            </div>

            <button class="msgr-jump" type="button" data-jump hidden><i class="fa-solid fa-arrow-down"></i> New messages</button>

            <form class="staff-composer" data-composer>
                <label class="sr-only" for="staff-message">Message</label>
                <textarea id="staff-message" rows="1" maxlength="4000" placeholder="Ask about invoices, claims or orders…" data-input></textarea>
                <button class="staff-send" type="submit" aria-label="Send" data-send><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
            </form>
        </div>
    </section>

    <dialog class="preview-dialog" data-preview-dialog aria-label="What Flowise receives">
        <div class="preview-head">
            <h3>What the staff chatflow receives</h3>
            <button type="button" class="icon-btn" data-preview-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <h4>System message</h4>
        <pre data-preview-system>Loading…</pre>
        <h4>Request body (secrets masked)</h4>
        <pre data-preview-payload></pre>
    </dialog>
</div>
<script type="application/json" id="staff-chat-data"><?= json_encode(['history' => $history], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php view('layouts/footer', ['scripts' => ['assets/js/staff-chat.js']]); ?>
