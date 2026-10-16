<?php
/**
 * ai/panel.php - the user's AI chat.
 * Sends via fetch (public/js/chat.js); falls back to a full post without JS.
 */
view('layouts/header', ['title' => 'AI Chat']);
?>
<?php if (!$configured): ?>
    <div class="notice notice--warn" role="status">
        <?= icon('alert') ?>
        <div>
            <strong>AI Chat is not available yet</strong>
            <p>Agnes AI is not configured yet. Please contact your administrator.</p>
        </div>
    </div>
<?php elseif (!$online): ?>
    <div class="notice notice--warn" role="status">
        <?= icon('alert') ?>
        <div>
            <strong>The AI service is offline</strong>
            <?php if (AI_SERVICE_URL === ''): ?>
                <p>It is not configured yet — <code>AI_SERVICE_URL</code> is empty. Set it in your
                   environment (and start the service with <code>cd ai-service &amp;&amp; python app.py</code>)
                   so the assistant can answer.</p>
            <?php else: ?>
                <p>It is not answering at <code><?= e(AI_SERVICE_URL) ?></code>. Start it with
                   <code>cd ai-service &amp;&amp; python app.py</code>.</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<section class="chat-shell" aria-label="AI chat">
    <div class="chat-head liquid-glass">
        <span class="chat-head__avatar"><?= icon('sparkle') ?></span>
        <div class="chat-head__text">
            <strong>AI assistant</strong>
            <span>Ask a question or send a photo</span>
        </div>
        <span class="badge badge--dot <?= $online ? 'badge--ok badge--live' : 'badge--danger' ?>" data-ai-status>
            <?= $online ? 'Service online' : 'Service offline' ?>
        </span>
    </div>

    <div class="chat-body" id="chat-body" aria-live="polite">
        <?php if ($messages === []): ?>
            <div class="chat-empty" id="chat-empty">
                <span class="empty__icon"><?= icon('sparkle') ?></span>
                <strong>How can I help?</strong>
                <p>Try one of these, or write your own.</p>
                <div class="chat-suggest">
                    <button class="chip" type="button" data-suggest="Summarise my active items">Summarise my items</button>
                    <button class="chip" type="button" data-suggest="Give me three ideas to get started">Give me ideas</button>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($messages as $m): ?>
                <?php $isUser = $m['role'] === 'user'; ?>
                <div class="msg msg--<?= $isUser ? 'user' : 'bot' ?>">
                    <?php if ($isUser): ?>
                        <div class="msg__text"><?= e((string) $m['body']) ?></div>
                    <?php else: ?>
                        <?php if (!empty($m['image_name'])): ?>
                            <small class="muted"><?= e((string) $m['image_name']) ?></small>
                        <?php endif; ?>
                        <div class="msg__text" data-markdown><?= e((string) $m['body']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <form class="composer" id="chat-form" method="post"
          action="<?= e(url('ai', ['action' => 'send'])) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
        <input type="hidden" name="image_name" id="chat-image-name" value="">

        <div class="composer__extras">
            <div class="composer__camera" id="chat-camera" hidden>
                <video id="chat-video" playsinline muted></video>
                <div>
                    <button class="btn btn--primary btn--sm" type="button" id="chat-capture"><?= icon('camera', 'icon--sm') ?> Capture</button>
                    <button class="btn btn--sm" type="button" id="chat-camera-cancel">Cancel</button>
                </div>
            </div>
            <div class="composer__preview" id="chat-preview" hidden>
                <img id="chat-preview-img" src="" alt="" hidden>
                <span id="chat-preview-name">photo.jpg</span>
                <button class="icon-btn icon-btn--flat" type="button" id="chat-preview-remove" aria-label="Remove attachment"><?= icon('x', 'icon--sm') ?></button>
            </div>
        </div>

        <label class="icon-btn composer__attach" title="Attach a file">
            <?= icon('paperclip', 'icon--sm') ?>
            <span class="sr-only">Attach a file</span>
            <input type="file" id="chat-file" accept=".txt,.md,.csv,.json,.log,.png,.jpg,.jpeg,.webp" hidden>
        </label>
        <button class="icon-btn" type="button" id="chat-camera-btn" title="Take a photo" aria-label="Take a photo"><?= icon('camera', 'icon--sm') ?></button>
        <label class="sr-only" for="chat-text">Message</label>
        <textarea class="composer__input" id="chat-text" name="text" rows="1"
                  maxlength="<?= (int) $maxText ?>" placeholder="Ask anything" aria-label="Message"></textarea>
        <button class="composer__send" type="submit" id="chat-send" aria-label="Send"><?= icon('send', 'icon--sm') ?></button>
    </form>
</section>

<?php view('layouts/footer', ['scripts' => [asset('js/chat.js')]]); ?>
