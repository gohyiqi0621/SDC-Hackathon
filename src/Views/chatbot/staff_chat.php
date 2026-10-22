<?php
/**
 * chatbot/staff_chat.php - Staff > Chat Interface.
 *
 * Same messenger look as Customer > Chat Interface (conversations.css), but
 * the staff member is chatting: their messages on the right, the assistant's
 * on the left, and a composer at the bottom. Messages go to
 * POST staff/chat_send (StaffAssistantService -> staff Flowise chatflow).
 *
 * Chat sessions: "New chat" starts a fresh session (own history and Flowise
 * memory); earlier sessions stay listed under "Chats" and can be reopened.
 *
 * Expects: $assistant, $model, $enabled, $problems, $functions
 * [label, icon, state on|off], $suggestions, $sessions [key, title, at, count,
 * url, current], $session, $isNew, $history [role me|ai, content, status, at,
 * problem], $sendUrl, $newUrl, $sessionUrl, $settingsUrl, $chatflowSet.
 */
view('layouts/header', ['title' => $title, 'styles' => ['assets/css/conversations.css']]);
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/u', trim($name)) ?: [$name];
    return mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : mb_substr($parts[0], 1, 1)));
};
?>
<div class="page-body">
    <section class="msgr msgr--staff" data-staff-chat
             data-send-url="<?= e($sendUrl) ?>" data-decide-url="<?= e($decideUrl) ?>" data-session="<?= e($session === '' ? 'main' : $session) ?>"
             data-session-url="<?= e($sessionUrl) ?>" data-new="<?= $isNew ? '1' : '0' ?>">

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
                <a class="icon-btn" href="<?= e($settingsUrl) ?>" title="Setup" aria-label="Setup">
                    <i class="fa-solid fa-sliders"></i>
                </a>
            </div>

            <div class="staff-side__body">
                <a class="staff-new" href="<?= e($newUrl) ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i> New chat</a>

                <h2 class="staff-side__title">Chats</h2>
                <ul class="staff-sessions" role="list" data-sessions>
                    <?php if ($isNew): ?>
                        <li><a class="staff-session is-current" href="<?= e($sessionUrl) ?>" aria-current="page" data-session-current>
                            <i class="fa-regular fa-message" aria-hidden="true"></i>
                            <span><strong data-session-title>New chat</strong><small>Now</small></span>
                        </a></li>
                    <?php endif; ?>
                    <?php foreach ($sessions as $s): ?>
                        <li><a class="staff-session<?= $s['current'] ? ' is-current' : '' ?>" href="<?= e($s['url']) ?>"<?= $s['current'] ? ' aria-current="page" data-session-current' : '' ?>>
                            <i class="fa-regular fa-message" aria-hidden="true"></i>
                            <span><strong<?= $s['current'] ? ' data-session-title' : '' ?>><?= e($s['title']) ?></strong>
                                  <small data-when="<?= e($s['at']) ?>"><?= e(date('j M, g:i A', strtotime($s['at']))) ?> · <?= (int) $s['count'] ?> messages</small></span>
                        </a></li>
                    <?php endforeach; ?>
                    <?php if (!$isNew && $sessions === []): ?>
                        <li class="staff-sessions__empty">No chats yet.</li>
                    <?php endif; ?>
                </ul>

                <?php if (!$enabled || $problems !== []): ?>
                    <div class="staff-alert">
                        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                        <div>
                            <strong><?= $enabled ? 'Check the setup' : 'The staff assistant is off' ?></strong>
                            <?php foreach ($problems as $p): ?><small><?= e($p) ?></small><?php endforeach; ?>
                            <?php if (!$chatflowSet): ?><small>FLOWISE_STAFF_CHATFLOW_ID is not set, so the customer chatflow is used.</small><?php endif; ?>
                            <a href="<?= e($settingsUrl) ?>">Open Setup</a>
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
                            <small><?= $f['state'] === 'on' ? 'On' : 'Off' ?></small>
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
            </div>
        </aside>

        <!-- Right: the conversation -->
        <div class="msgr-main">
            <header class="msgr-head">
                <span class="msgr-avatar msgr-avatar--bot" aria-hidden="true"><i class="fa-solid fa-user-tie"></i></span>
                <div class="msgr-head__text">
                    <strong><?= e($assistant) ?></strong>
                    <small data-status>Internal assistant · emails, invoices, claims and orders</small>
                </div>
                <a class="staff-head-new" href="<?= e($newUrl) ?>" title="Start a new chat"><i class="fa-solid fa-plus" aria-hidden="true"></i> New chat</a>
                <a class="icon-btn staff-head-settings" href="<?= e($settingsUrl) ?>" aria-label="Setup"><i class="fa-solid fa-sliders"></i></a>
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
                <textarea id="staff-message" rows="1" maxlength="4000" placeholder="Ask about emails, invoices, claims or orders…" data-input></textarea>
                <button class="staff-send" type="submit" aria-label="Send" data-send><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
            </form>
        </div>
    </section>

</div>
<script type="application/json" id="staff-chat-data"><?= json_encode(['history' => $history], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php view('layouts/footer', ['scripts' => ['assets/js/staff-chat.js']]); ?>
