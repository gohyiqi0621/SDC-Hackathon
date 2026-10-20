<?php
/**
 * chatbot/chat.php - Chat Interface, shared by Customer and Staff.
 *
 * Expects (from ChatbotController): $title, $heading, $sub, $icon, $intro,
 * $suggestions, $notes, $settingsUrl.
 * Replies are a frontend demo (assets/js/app.js) until the AI service is connected.
 */
view('layouts/header', ['title' => $title]);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1><?= e($heading) ?></h1>
        <p><?= e($sub) ?></p>
    </div>
    <div class="welcome-actions">
        <a class="secondary-btn" href="<?= e($settingsUrl) ?>">
            <i class="fa-solid fa-sliders"></i> Setting &amp; Integration
        </a>
    </div>
</section>

<div class="page-body">
    <div class="grid-main-side">

        <section class="panel chat-panel" data-chat>
            <div class="chat-head">
                <span class="chat-head-avatar"><i class="<?= e($icon) ?>"></i></span>
                <div>
                    <strong><?= e($heading) ?></strong>
                    <span>Demo mode &middot; AI service not connected yet</span>
                </div>
                <span class="badge">Preview</span>
            </div>

            <div class="chat-body">
                <div class="chat-bubble chat-bubble--bot"><?= e($intro) ?></div>
                <div class="chat-suggest">
                    <?php foreach ($suggestions as $suggestion): ?>
                        <button class="chip" type="button" data-suggest><?= e($suggestion) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <form class="composer">
                <label class="sr-only" for="chat-message">Message</label>
                <textarea id="chat-message" rows="1" placeholder="Type a message..."></textarea>
                <button class="primary-btn" type="submit" aria-label="Send message">
                    <i class="fa-regular fa-paper-plane"></i>
                </button>
            </form>
        </section>

        <aside class="panel">
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
        </aside>

    </div>
</div>
<?php view('layouts/footer'); ?>
