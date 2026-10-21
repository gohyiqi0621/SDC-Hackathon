<?php
/**
 * ai_settings/_telegram_integration_card.php - TelegramIntegrationCard.
 *
 * Table: telegram_integrations. Three views, chosen by ai-settings.js:
 *
 *   choose     a chatbot is saved on this account but not connected:
 *              "Use @saved_bot" or "Create a new chatbot"
 *   create     step-by-step: name the chatbot, make it in @BotFather,
 *              paste the token, Connect (no saved bot = this view directly)
 *   connected  summary: chatbot name, username, staff chat auto-link.
 *              The status pill turns to Error when Telegram cannot reach
 *              this app (webhook missing, old address, delivery error).
 *
 * The bot username is read from Telegram (getMe), never typed. The token is
 * sent once, stored encrypted and never sent back (only has_bot_token).
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
                    <h2>Telegram</h2>
                    <p>Let customers chat with your AI chatbot on Telegram, and get staff alerts in your own Telegram.</p>
                </div>
                <?php view('ai_settings/_integration_status_badge', ['name' => 'telegram']); ?>
            </div>

            <div class="panel-body">

                <!-- ============ choose: a saved chatbot exists ============ -->
                <div class="tg-step" data-tg-view="choose" hidden>
                    <h3 class="tg-step-title">Choose a chatbot</h3>
                    <p class="field-hint">This account already has a chatbot. Use it, or create a new one.</p>

                    <div class="tg-choice-grid">
                        <div class="tg-choice tg-choice--saved">
                            <span class="tg-choice-icon"><i class="fa-solid fa-robot" aria-hidden="true"></i></span>
                            <div class="tg-choice-text">
                                <strong data-tg="saved-name">—</strong>
                                <span data-tg="saved-username">@—</span>
                            </div>
                            <button class="primary-btn" type="button" data-action="use-saved">
                                <i class="fa-solid fa-link" aria-hidden="true"></i> Use this chatbot
                            </button>
                            <button class="link-btn tg-forget" type="button" data-action="forget">Remove it from this account</button>
                        </div>

                        <div class="tg-choice">
                            <span class="tg-choice-icon"><i class="fa-solid fa-plus" aria-hidden="true"></i></span>
                            <div class="tg-choice-text">
                                <strong>Create a new chatbot</strong>
                                <span>Make a new bot in @BotFather (about 1 minute).</span>
                            </div>
                            <button class="secondary-btn" type="button" data-action="show-create">
                                <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Create new
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ============ create: new chatbot via @BotFather ============ -->
                <div class="tg-step" data-tg-view="create" hidden>
                    <div class="tg-step-head">
                        <h3 class="tg-step-title">Create a new AI chatbot</h3>
                        <button class="link-btn" type="button" data-action="back-choose" hidden>
                            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back
                        </button>
                    </div>
                    <p class="field-hint" data-tg="none-note">No chatbot is saved on this account yet. Telegram only lets you create bots in its own @BotFather chat, so follow these 3 steps.</p>

                    <ol class="tg-steps">
                        <li>
                            <div class="field">
                                <label for="bot_name">Chatbot name</label>
                                <input id="bot_name" name="bot_name" type="text" maxlength="64"
                                       placeholder="e.g. Aisyah from Ah Hock Store" aria-describedby="hint-bot_name err-bot_name">
                                <p class="field-hint" id="hint-bot_name">What customers see at the top of the chat. We set it on Telegram for you when you connect.</p>
                                <p class="field-error" id="err-bot_name" data-error-for="bot_name"></p>
                            </div>
                        </li>
                        <li>
                            <p><strong>Create the bot in Telegram.</strong> Open @BotFather, send <code>/newbot</code>, then send:</p>
                            <ul class="tg-copy-list">
                                <li>the name: <code data-tg="copy-name">—</code>
                                    <button class="link-btn" type="button" data-copy="name"><i class="fa-regular fa-copy" aria-hidden="true"></i> Copy</button></li>
                                <li>a username ending in <code>bot</code>, e.g. <code data-tg="copy-username">my_shop_bot</code>
                                    <button class="link-btn" type="button" data-copy="username"><i class="fa-regular fa-copy" aria-hidden="true"></i> Copy</button></li>
                            </ul>
                            <a class="secondary-btn tg-botfather" href="https://t.me/BotFather" target="_blank" rel="noopener">
                                <i class="fa-brands fa-telegram" aria-hidden="true"></i> Open @BotFather
                            </a>
                        </li>
                        <li>
                            <div class="field">
                                <label for="bot_token">Paste the token @BotFather sends you *</label>
                                <div class="input-wrap">
                                    <input id="bot_token" name="bot_token" type="password" autocomplete="new-password"
                                           spellcheck="false" autocapitalize="off" maxlength="100"
                                           placeholder="123456789:AAH..." aria-describedby="hint-bot_token err-bot_token">
                                    <button class="input-btn" type="button" data-toggle-password="bot_token" aria-label="Show token" aria-controls="bot_token">
                                        <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <p class="field-hint" id="hint-bot_token">Sent to the server once, checked with Telegram and stored encrypted. Never shown again.</p>
                                <p class="field-error" id="err-bot_token" data-error-for="bot_token"></p>
                            </div>
                        </li>
                    </ol>

                    <div class="integration-actions">
                        <span class="integration-actions__spacer"></span>
                        <button class="primary-btn" type="submit" data-action="connect">
                            <i class="fa-solid fa-link" aria-hidden="true"></i> Connect chatbot
                        </button>
                    </div>
                </div>

                <!-- ============ connected: same layout as Google Calendar ============ -->
                <div class="tg-step" data-tg-view="connected" hidden>
                    <div class="gcal-account">
                        <span class="gcal-account__icon tg-account__icon" aria-hidden="true"><i class="fa-brands fa-telegram"></i></span>
                        <div class="gcal-account__text">
                            <strong data-tg="bot-name">—</strong>
                            <small><a data-tg="bot-link" href="#" target="_blank" rel="noopener">@—</a></small>
                        </div>
                        <button class="primary-btn" type="button" data-action="reconnect">
                            <i class="fa-solid fa-plug" aria-hidden="true"></i> Reconnect
                        </button>
                    </div>

                    <p class="tg-alert" data-tg="webhook-error" role="alert" hidden>
                        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                        <span data-tg="webhook-error-text"></span>
                    </p>

                    <div class="tg-fields">
                        <div class="field">
                            <label for="tg_bot_name_view">Chatbot name</label>
                            <input id="tg_bot_name_view" type="text" readonly data-tg="field-name">
                            <p class="field-hint">What customers see at the top of the chat.</p>
                        </div>

                        <div class="field">
                            <label for="tg_bot_username_view">Bot username</label>
                            <input id="tg_bot_username_view" type="text" readonly data-tg="field-username">
                            <p class="field-hint">Customers find your chatbot with this username.</p>
                        </div>

                        <div class="field" data-tg="staff-field">
                            <div class="field-label-row">
                                <label for="tg_staff_view">Staff chat</label>
                                <span class="tg-staff-actions">
                                    <button class="link-btn" type="button" data-action="staff-link">
                                        <i class="fa-brands fa-telegram" aria-hidden="true"></i> <span data-tg="staff-link-label">Link my Telegram</span>
                                    </button>
                                    <button class="link-btn" type="button" data-action="staff-unlink" hidden>Unlink</button>
                                </span>
                            </div>
                            <input id="tg_staff_view" type="text" readonly data-tg="field-staff">
                            <p class="field-hint" data-tg="staff-text">Link your own Telegram to receive staff alerts and hand-overs.</p>
                        </div>
                    </div>

                    <div class="integration-actions">
                        <button class="danger-btn" type="button" data-action="disconnect">
                            <i class="fa-solid fa-link-slash" aria-hidden="true"></i> Disconnect
                        </button>
                        <span class="integration-actions__spacer"></span>
                        <button class="secondary-btn" type="button" data-action="show-create">
                            <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i> Use a different chatbot
                        </button>
                    </div>
                </div>

                <p class="field-error" data-error-for="form"></p>
                <div class="ai-result" data-result hidden></div>
            </div>
        </section>
    </form>
</div>
