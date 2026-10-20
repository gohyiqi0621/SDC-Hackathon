<?php
/** chatbot/customer.php - customer-facing assistant surface (structure). */
view('layouts/header', ['title' => 'Customer Chatbot']);
?>
<div class="page-head page-head--duo">
    <div>
        <h2 class="page-head__title">Customer assistant</h2>
        <p class="page-head__sub">Answer customer questions about invoices, claims and payments.</p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('chatbot', ['action' => 'staff'])) ?>"><?= icon('users', 'icon--sm') ?> Staff view</a>
    </div>
</div>

<div class="chat-layout">
    <section class="card chat-shell">
        <div class="chat-head">
            <span class="chat-head__avatar"><?= icon('sparkle') ?></span>
            <div class="chat-head__text">
                <strong>Customer assistant</strong>
                <span>Being connected to the finance workspace</span>
            </div>
            <span class="badge">Preview</span>
        </div>

        <div class="chat-body" id="chat-body">
            <div class="chat-empty">
                <span class="empty__icon"><?= icon('chat') ?></span>
                <h3>The assistant is being connected</h3>
                <p>
                    The conversation surface is in place. Once the assistant is connected,
                    a customer can ask about an invoice, a claim or a payment and get an
                    answer scoped to their own records.
                </p>
                <div class="chat-suggest" aria-hidden="true">
                    <span class="chip">Where is my invoice?</span>
                    <span class="chip">What does my claim status mean?</span>
                    <span class="chip">When will my payment settle?</span>
                </div>
            </div>
        </div>

        <div class="composer">
            <label class="sr-only" for="customer-message">Message</label>
            <textarea class="composer__input" id="customer-message" rows="1"
                      placeholder="Ask about an invoice, claim or payment..." disabled></textarea>
            <button class="composer__send" type="button" aria-label="Send message" disabled><?= icon('send') ?></button>
        </div>
    </section>

    <aside class="card">
        <div class="card__head">
            <div>
                <h3>How it will work</h3>
                <p>Customer-safe by design.</p>
            </div>
        </div>
        <ul class="activity">
            <li><span class="dot"></span><span><strong>Signed-in only</strong><small>Every answer is tied to your account</small></span></li>
            <li><span class="dot"></span><span><strong>Own records</strong><small>No other customer's data is reachable</small></span></li>
            <li><span class="dot"></span><span><strong>No admin portal</strong><small>This portal is for users only</small></span></li>
        </ul>
        <p class="field__hint">
            The assistant will use the same authenticated session as the rest of the
            portal, so the server — not the browser — decides which records it can see.
        </p>
    </aside>
</div>

<?php view('layouts/footer'); ?>
