<?php
/**
 * chatbot/conversations.php - Customer > Chat Interface (live, view only).
 *
 * A messenger-style view of what the AI and each customer said on Telegram.
 * The page polls ChatbotController::customerChatFeed every few seconds, so
 * new messages, new customers and order changes appear without reloading.
 * Nothing here sends a message: customers are answered by the AI.
 *
 * Expects: $feed (first feed, same shape as the JSON endpoint), $feedUrl,
 * $ordersUrl, $settingsUrl.
 */
view('layouts/header', ['title' => $title, 'styles' => ['assets/css/conversations.css']]);
?>
<div class="page-body">
    <section class="msgr" data-msgr
             data-feed-url="<?= e($feedUrl) ?>"
             data-orders-url="<?= e($ordersUrl) ?>">

        <!-- Conversation list -->
        <aside class="msgr-side" aria-label="Conversations">
            <div class="msgr-side__head">
                <div>
                    <h1>Chats</h1>
                    <p><span class="msgr-live" data-live><i></i> Live</span> <span data-count></span></p>
                </div>
                <a class="icon-btn" href="<?= e($settingsUrl) ?>" title="Setup" aria-label="Setup">
                    <i class="fa-solid fa-sliders"></i>
                </a>
            </div>
            <label class="msgr-search">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <span class="sr-only">Search chats</span>
                <input type="search" placeholder="Search name or message" data-search>
            </label>
            <ul class="msgr-list" data-list role="list"></ul>
            <p class="msgr-empty-list" data-list-empty hidden>No chats match.</p>
        </aside>

        <!-- Selected chat -->
        <div class="msgr-main" data-main>
            <header class="msgr-head" data-head hidden>
                <button class="icon-btn msgr-back" type="button" data-back aria-label="Back to chats"><i class="fa-solid fa-arrow-left"></i></button>
                <span class="msgr-avatar" data-head-avatar></span>
                <div class="msgr-head__text">
                    <strong data-head-name></strong>
                    <small data-head-sub></small>
                </div>
                <span class="msgr-pill"><i class="fa-regular fa-eye"></i> View only</span>
            </header>

            <div class="msgr-body" data-body aria-live="polite"></div>

            <button class="msgr-jump" type="button" data-jump hidden><i class="fa-solid fa-arrow-down"></i> New messages</button>

            <details class="msgr-orders" data-orders hidden>
                <summary>
                    <i class="fa-solid fa-bag-shopping" aria-hidden="true"></i>
                    <span>Today's orders</span>
                    <span class="msgr-orders__count" data-orders-count>0</span>
                    <i class="fa-solid fa-chevron-up msgr-orders__chev" aria-hidden="true"></i>
                </summary>
                <ul data-orders-list role="list"></ul>
            </details>

            <footer class="msgr-foot" data-foot hidden>
                <i class="fa-solid fa-robot" aria-hidden="true"></i>
                Your AI chatbot replies to this customer on Telegram. This page updates by itself.
            </footer>

            <div class="msgr-placeholder" data-placeholder>
                <span class="msgr-placeholder__icon"><i class="fa-regular fa-comments"></i></span>
                <h2>No chat selected</h2>
                <p>When customers message your chatbot on Telegram, their chats appear on the left as they happen.</p>
            </div>
        </div>
    </section>
</div>
<script type="application/json" id="msgr-data"><?= json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php view('layouts/footer', ['scripts' => ['assets/js/conversations.js']]); ?>
