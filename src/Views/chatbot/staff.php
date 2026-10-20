<?php
/** chatbot/staff.php - staff-facing assistant surface (structure). */
view('layouts/header', ['title' => 'Staff Chatbot']);
?>
<div class="page-head page-head--duo">
    <div>
        <h2 class="page-head__title">Staff assistant</h2>
        <p class="page-head__sub">Give the finance team a faster way to look things up.</p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('chatbot', ['action' => 'customer'])) ?>"><?= icon('user', 'icon--sm') ?> Customer view</a>
    </div>
</div>

<div class="chat-layout">
    <section class="card chat-shell">
        <div class="chat-head">
            <span class="chat-head__avatar"><?= icon('users') ?></span>
            <div class="chat-head__text">
                <strong>Staff assistant</strong>
                <span>Being connected to the finance workspace</span>
            </div>
            <span class="badge">Preview</span>
        </div>

        <div class="chat-body" id="chat-body">
            <div class="chat-empty">
                <span class="empty__icon"><?= icon('chat') ?></span>
                <h3>The assistant is being connected</h3>
                <p>
                    This surface will let staff ask across invoices, claims and settlements.
                    The portal is authenticated and ready; the assistant service is the next
                    step.
                </p>
                <div class="chat-suggest" aria-hidden="true">
                    <span class="chip">Which invoices are overdue?</span>
                    <span class="chip">Show unmatched payments</span>
                    <span class="chip">Summarise this month's claims</span>
                </div>
            </div>
        </div>

        <div class="composer">
            <label class="sr-only" for="staff-message">Message</label>
            <textarea class="composer__input" id="staff-message" rows="1"
                      placeholder="Ask across invoices, claims and settlements..." disabled></textarea>
            <button class="composer__send" type="button" aria-label="Send message" disabled><?= icon('send') ?></button>
        </div>
    </section>

    <aside class="card">
        <div class="card__head">
            <div>
                <h3>Built for the team</h3>
                <p>Same account, same guardrails.</p>
            </div>
        </div>
        <ul class="activity">
            <li><span class="dot"></span><span><strong>Finance-wide view</strong><small>Answers drawn from the workspace</small></span></li>
            <li><span class="dot"></span><span><strong>Read-only first</strong><small>Look-ups before any write action</small></span></li>
            <li><span class="dot"></span><span><strong>Auditable</strong><small>Every request stays in the app session</small></span></li>
        </ul>
        <p class="field__hint">
            The assistant will answer from data the signed-in account is allowed to see;
            the browser never supplies the user id.
        </p>
    </aside>
</div>

<?php view('layouts/footer'); ?>
