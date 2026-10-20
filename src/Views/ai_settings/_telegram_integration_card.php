<?php
/**
 * ai_settings/_telegram_integration_card.php - TelegramIntegrationCard.
 *
 * Table: telegram_integrations (bot_username, bot_token_encrypted,
 * staff_telegram_chat_id, connection_status, is_enabled).
 *
 * Security: the bot token is typed here and sent once to the server, which
 * checks it with the Telegram Bot API and stores it encrypted. The server
 * never sends it back - it only reports `has_bot_token`. The field is cleared
 * after every request, so the token does not linger in the page.
 */
?>
<div class="ai-section" data-section="telegram">
    <?php view('ai_settings/_section_state', ['what' => 'the Telegram integration']); ?>

    <form class="ai-form" data-view="ready" data-form="telegram" novalidate hidden autocomplete="off">
        <section class="panel integration-card">
            <div class="integration-head">
                <span class="integration-logo integration-logo--telegram" aria-hidden="true">
                    <i class="fa-brands fa-telegram"></i>
                </span>
                <div class="integration-title">
                    <h2>Telegram <span class="demo-chip" data-demo-chip hidden>Demo</span></h2>
                    <p>Let customers chat with your assistant on Telegram, and send staff alerts to your team chat.</p>
                </div>
                <?php view('ai_settings/_integration_status_badge', ['name' => 'telegram']); ?>
            </div>

            <div class="panel-body">
                <div class="form-row">
                    <div class="field">
                        <label for="bot_username">Bot username *</label>
                        <input id="bot_username" name="bot_username" type="text" maxlength="33"
                               placeholder="@YourShopBot" spellcheck="false" autocapitalize="off"
                               aria-describedby="hint-bot_username err-bot_username">
                        <p class="field-hint" id="hint-bot_username">The name you gave the bot in @BotFather. It ends in "bot".</p>
                        <p class="field-error" id="err-bot_username" data-error-for="bot_username"></p>
                    </div>

                    <div class="field">
                        <label for="staff_telegram_chat_id">Staff Telegram chat ID <span class="field-optional">(optional)</span></label>
                        <input id="staff_telegram_chat_id" name="staff_telegram_chat_id" type="text" inputmode="numeric"
                               maxlength="20" placeholder="e.g. -1001234567890" spellcheck="false"
                               aria-describedby="hint-staff_telegram_chat_id err-staff_telegram_chat_id">
                        <p class="field-hint" id="hint-staff_telegram_chat_id">Optional. Where staff alerts and hand-overs go. Leave empty to skip. Group IDs start with a minus sign.</p>
                        <p class="field-error" id="err-staff_telegram_chat_id" data-error-for="staff_telegram_chat_id"></p>
                    </div>
                </div>

                <div class="field">
                    <label for="bot_token">Bot token <span data-token-required>*</span></label>
                    <div class="input-wrap">
                        <input id="bot_token" name="bot_token" type="password" autocomplete="new-password"
                               spellcheck="false" autocapitalize="off" maxlength="100"
                               placeholder="123456789:AAH...from @BotFather"
                               aria-describedby="hint-bot_token err-bot_token">
                        <button class="input-btn" type="button" data-toggle-password="bot_token" aria-label="Show token" aria-controls="bot_token">
                            <i class="fa-regular fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <p class="field-hint" id="hint-bot_token" data-token-hint>
                        Hidden by default. It is sent to the server once, verified with Telegram, and stored encrypted. It is never shown again.
                    </p>
                    <p class="field-error" id="err-bot_token" data-error-for="bot_token"></p>
                </div>

                <dl class="integration-meta">
                    <div><dt>Stored token</dt><dd data-meta="token">—</dd></div>
                    <div><dt>Last updated</dt><dd data-meta="updated">—</dd></div>
                </dl>

                <div class="ai-result" data-result hidden></div>

                <div class="integration-actions">
                    <button class="danger-btn" type="button" data-action="disconnect" disabled>
                        <i class="fa-solid fa-link-slash" aria-hidden="true"></i> Disconnect
                    </button>
                    <span class="integration-actions__spacer"></span>
                    <button class="secondary-btn" type="button" data-action="test">
                        <i class="fa-solid fa-plug-circle-check" aria-hidden="true"></i> Test connection
                    </button>
                    <button class="primary-btn" type="submit" data-action="connect">
                        <i class="fa-solid fa-link" aria-hidden="true"></i> <span data-connect-label>Connect</span>
                    </button>
                </div>
            </div>
        </section>

        <details class="panel ai-help">
            <summary><i class="fa-regular fa-circle-question" aria-hidden="true"></i> How do I get a bot token and chat ID?</summary>
            <ol>
                <li>In Telegram, open <strong>@BotFather</strong>, send <code>/newbot</code> and follow the steps. It replies with the bot token.</li>
                <li>Add the bot to your staff group (or message it yourself) and send any message.</li>
                <li>Ask your developer for the chat ID from the bot's updates, or use a trusted ID bot. Group IDs look like <code>-100…</code>.</li>
            </ol>
        </details>
    </form>
</div>
