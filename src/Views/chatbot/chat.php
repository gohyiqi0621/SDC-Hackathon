<?php
/**
 * chatbot/chat.php - Chat Interface, shared by Customer and Staff.
 *
 * Expects (from ChatbotController): $title, $heading, $sub, $icon, $intro,
 * $suggestions, $notes, $settingsUrl.
 * Optional (Customer): $sendUrl, $previewUrl, $history - messages then go
 * through the real pipeline (PostgreSQL settings -> Flowise), the same one
 * Telegram uses. Without $sendUrl (Staff) replies are a frontend demo.
 * Optional (Customer): $tab ('test' | 'conversations'), $tabUrls,
 * $conversations, $thread - the view-only history of the AI with each
 * customer (chat_messages), with that customer's orders.
 */
$sendUrl    = $sendUrl ?? null;
$previewUrl = $previewUrl ?? null;
$history    = $history ?? [];
$tab        = $tab ?? null;
$tabUrls = $tabUrls ?? [];
view('layouts/header', ['title' => $title, 'styles' => $tab !== null ? ['assets/css/conversations.css'] : []]);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1><?= e($heading) ?></h1>
        <p><?= e($sub) ?></p>
    </div>
    <div class="welcome-actions">
        <a class="secondary-btn" href="<?= e($settingsUrl) ?>">
            <i class="fa-solid fa-sliders"></i> Setup
        </a>
    </div>
</section>

<div class="page-body">
    <?php if ($tabUrls !== []): ?>
        <nav class="chat-tabs" aria-label="Chat interface sections">
            <a href="<?= e($tabUrls['test']) ?>"<?= $tab === 'test' ? ' class="active" aria-current="page"' : '' ?>>
                <i class="fa-regular fa-comments"></i> Test chat
            </a>
            <a href="<?= e($tabUrls['conversations']) ?>"<?= $tab === 'conversations' ? ' class="active" aria-current="page"' : '' ?>>
                <i class="fa-solid fa-users"></i> Customer conversations
            </a>
        </nav>
    <?php endif; ?>

    <?php if ($tab === 'conversations'): ?>
        <?php require __DIR__ . '/_conversations.php'; ?>
    <?php else: ?>
    <div class="grid-main-side">

        <section class="panel chat-panel" data-chat<?= $sendUrl ? ' data-chat-endpoint="' . e($sendUrl) . '"' : '' ?>>
            <div class="chat-head">
                <span class="chat-head-avatar"><i class="<?= e($icon) ?>"></i></span>
                <div>
                    <strong><?= e($heading) ?></strong>
                    <span><?= $sendUrl ? 'Test chat &middot; uses your AI Settings and Flowise, like Telegram' : 'Demo mode &middot; AI service not connected yet' ?></span>
                </div>
                <span class="badge"><?= $sendUrl ? 'Live test' : 'Preview' ?></span>
            </div>

            <div class="chat-body">
                <div class="chat-bubble chat-bubble--bot"><?= e($intro) ?></div>
                <div class="chat-suggest">
                    <?php foreach ($suggestions as $suggestion): ?>
                        <button class="chip" type="button" data-suggest><?= e($suggestion) ?></button>
                    <?php endforeach; ?>
                </div>
                <?php foreach ($history as $item): ?>
                    <div class="chat-bubble chat-bubble--<?= $item['role'] === 'customer' ? 'me' : 'bot' ?><?= $item['status'] !== 'ok' ? ' chat-bubble--muted' : '' ?>"><?= e($item['content']) ?></div>
                <?php endforeach; ?>
            </div>

            <form class="composer">
                <label class="sr-only" for="chat-message">Message</label>
                <textarea id="chat-message" rows="1" placeholder="Type a message..."></textarea>
                <button class="primary-btn" type="submit" aria-label="Send message">
                    <i class="fa-regular fa-paper-plane"></i>
                </button>
            </form>
        </section>

        <aside class="stack">
        <?php if ($previewUrl): ?>
            <section class="panel" data-ai-preview="<?= e($previewUrl) ?>">
                <div class="panel-header">
                    <div>
                        <h2>What Flowise receives</h2>
                        <p>Built from AI Settings for every message.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <ul class="item-list" data-ai-status>
                        <li><span class="item-icon"><i class="fa-solid fa-spinner fa-spin"></i></span><span><strong>Checking setup…</strong></span></li>
                    </ul>
                    <div class="form-actions">
                        <button class="secondary-btn" type="button" data-ai-preview-open>
                            <i class="fa-regular fa-file-lines"></i> View system message
                        </button>
                    </div>
                </div>
            </section>

            <dialog class="preview-dialog" data-ai-preview-dialog aria-label="System message preview">
                <div class="preview-head">
                    <h3>System message &amp; Flowise request</h3>
                    <button type="button" class="icon-btn" data-ai-preview-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <h4>System message</h4>
                <pre data-ai-preview-system></pre>
                <h4>Request body sent to Flowise (secrets masked)</h4>
                <pre data-ai-preview-payload></pre>
            </dialog>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>How it works</h2>
                    <p>Same account, same safeguards.</p>
                </div>
            </div>
            <div class="panel-body">
                <ul class="item-list">
                    <?php foreach ($notes as [$noteIcon, $noteTitle, $noteText]): ?>
                        <li>
                            <span class="item-icon"><i class="<?= e($noteIcon) ?>"></i></span>
                            <span><strong><?= e($noteTitle) ?></strong><small><?= e($noteText) ?></small></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
        </aside>

    </div>
    <?php endif; ?>
</div>
<?php view('layouts/footer'); ?>
