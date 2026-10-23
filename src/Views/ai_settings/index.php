<?php
/**
 * ai_settings/index.php - Customer Assistant > Setup.
 *
 * The setup checklist (SetupStatus::customer) and the AI Assistant Settings
 * (model, key, personality, SOPs). Company information is on its own page
 * (Settings > Company Profile) and Telegram / Google Calendar are on Customer
 * Assistant > Connections; old links to #company, #telegram or #calendar on
 * this page are sent there.
 *
 * The form is loaded and saved by assets/js/ai-settings.js through
 * $endpoints (ChatbotController::aiSettingsEndpoints()).
 *
 * Payment Method (id="payment"): the bank / e-wallet, account no., holder name,
 * notes and optional QR customers pay with (company_profiles), saved by
 * assets/js/payment-method.js to POST ai_settings/payment_method_save.
 *
 * Expects: $userId, $endpoints, $aiModels, $chatUrl, $steps, $companyUrl,
 *          $connectionsUrl, $payment (AiSettingsController::presentPayment),
 *          $banks (CompanyProfile::BANKS), $paymentSaveUrl.
 */
view('layouts/header', [
    'title'  => 'Customer Assistant Setup',
    'styles' => ['assets/css/ai-settings.css'],
]);
?>
<script>
    // Old links: Company Information / Telegram / Google Calendar moved to their own pages.
    (function () {
        var h = (location.hash || '').replace('#', ''), q = location.search;
        if (h === 'company') { location.replace(<?= json_encode($companyUrl) ?>); }
        else if (h === 'telegram' || h === 'calendar' || /[?&]gcal=/.test(q)) {
            var to = <?= json_encode($connectionsUrl) ?>;
            var gcal = q.match(/[?&](gcal=[^&#]*(?:&gcal_message=[^&#]*)?)/);
            location.replace(to + (gcal ? '&' + gcal[1] : '') + '#' + (h || 'calendar'));
        }
    })();
</script>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Customer Assistant Setup</h1>
        <p>Get the assistant that chats with your customers ready: model, personality and instructions.</p>
    </div>
    <div class="welcome-actions">
        <a class="secondary-btn" href="<?= e($connectionsUrl) ?>"><i class="fa-solid fa-plug"></i> Connections</a>
    </div>
</section>

<div class="page-body">
    <?php view('partials/setup_checklist', ['steps' => $steps, 'tryUrl' => $chatUrl, 'tryLabel' => 'Open chats']); ?>

    <section class="panel pay-method" id="payment" data-payment-method data-save-url="<?= e($paymentSaveUrl) ?>">
        <div class="panel-header">
            <div>
                <h2><i class="fa-solid fa-building-columns" aria-hidden="true"></i> Payment Method</h2>
                <p>Where customers transfer money for their orders. The assistant gives these details with the order total.</p>
            </div>
            <span class="pay-method__status<?= $payment['summary'] !== '' ? ' is-set' : '' ?>" data-pay-status><?= $payment['summary'] !== '' ? 'Set' : 'Not set' ?></span>
        </div>
        <div class="panel-body">
            <?php if (!$payment['has_company']): ?>
                <div class="flash flash--warning" role="status">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div><strong>Save your Company Profile first</strong><span>The payment method is saved with your company. <a href="<?= e($companyUrl) ?>">Open Company Profile</a></span></div>
                </div>
            <?php endif; ?>
            <form class="pay-method__form" novalidate data-pay-form>
                <div class="form-row">
                    <div class="field">
                        <label for="payment_bank">Bank / e-wallet *</label>
                        <select id="payment_bank" name="payment_bank" required aria-describedby="err-payment_bank">
                            <option value="">Choose a bank</option>
                            <?php foreach ($banks as $group => $names): ?>
                                <optgroup label="<?= e($group) ?>">
                                    <?php foreach ($names as $bank): ?>
                                        <option value="<?= e($bank) ?>"<?= $payment['bank'] === $bank ? ' selected' : '' ?>><?= e($bank) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-error" id="err-payment_bank" data-error-for="payment_bank"></p>
                    </div>
                    <div class="field">
                        <label for="payment_account_no">Account number *</label>
                        <input id="payment_account_no" name="payment_account_no" type="text" inputmode="numeric" maxlength="40" required
                               autocomplete="off" placeholder="e.g. 5123 4567 8901" value="<?= e($payment['account_no']) ?>" aria-describedby="err-payment_account_no">
                        <p class="field-error" id="err-payment_account_no" data-error-for="payment_account_no"></p>
                    </div>
                </div>
                <div class="field">
                    <label for="payment_account_name">Account holder name *</label>
                    <input id="payment_account_name" name="payment_account_name" type="text" maxlength="150" required
                           placeholder="e.g. Ah Hock Trading Sdn Bhd" value="<?= e($payment['account_name']) ?>" aria-describedby="err-payment_account_name">
                    <p class="field-error" id="err-payment_account_name" data-error-for="payment_account_name"></p>
                </div>
                <div class="field">
                    <label for="payment_notes">Payment notes <span class="field-optional">(optional)</span></label>
                    <textarea id="payment_notes" name="payment_notes" rows="2" maxlength="500"
                              placeholder="e.g. Please use your order number as the payment reference."
                              aria-describedby="hint-payment_notes err-payment_notes"><?= e($payment['notes']) ?></textarea>
                    <p class="field-hint" id="hint-payment_notes">Extra instructions shown after the bank details.</p>
                    <p class="field-error" id="err-payment_notes" data-error-for="payment_notes"></p>
                </div>

                <div class="pay-qr">
                    <div class="pay-qr__preview">
                        <img src="<?= e((string) $payment['qr_url']) ?>" alt="Payment QR code" data-qr-img<?= $payment['qr_url'] ? '' : ' hidden' ?>>
                        <span class="pay-qr__empty" data-qr-empty<?= $payment['qr_url'] ? ' hidden' : '' ?>><i class="fa-solid fa-qrcode" aria-hidden="true"></i></span>
                    </div>
                    <div class="pay-qr__body">
                        <strong>Payment QR code <span class="field-optional">(optional)</span></strong>
                        <p class="field-hint">Your DuitNow / bank QR. Sent to the customer on Telegram with the order total. JPG, PNG or WebP, up to 2 MB.</p>
                        <div class="pay-qr__actions">
                            <label class="secondary-btn" for="payment_qr"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> <span data-qr-label><?= $payment['qr_url'] ? 'Replace QR' : 'Upload QR' ?></span></label>
                            <input class="sr-only" id="payment_qr" name="payment_qr" type="file" accept="image/jpeg,image/png,image/webp" data-qr-input>
                            <button class="link-btn pay-qr__remove" type="button" data-qr-remove<?= $payment['qr_url'] ? '' : ' hidden' ?>>Remove</button>
                        </div>
                        <p class="field-error" data-error-for="payment_qr"></p>
                    </div>
                </div>

                <div class="pay-method__foot">
                    <p class="pay-method__preview" data-pay-preview><?= $payment['summary'] !== '' ? 'Customers will see: “' . e($payment['summary']) . '”' : '' ?></p>
                    <button class="primary-btn" type="submit" data-pay-save><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save payment method</button>
                </div>
            </form>
        </div>
    </section>

    <div class="ai-settings staff-ai-settings" id="assistant"
         data-ai-settings
         data-user-id="<?= (int) $userId ?>"
         data-endpoints="<?= e(json_encode($endpoints, JSON_UNESCAPED_SLASHES)) ?>">
        <div class="staff-ai-heading">
            <h2><i class="fa-solid fa-robot" aria-hidden="true"></i> AI Assistant Settings</h2>
            <p>How the customer assistant talks, which AI model it uses, and the instructions (SOPs) it follows.</p>
        </div>
        <section class="ai-panel" data-panel="assistant">
            <?php view('ai_settings/_ai_assistant_settings_form', ['aiModels' => $aiModels]); ?>
        </section>
    </div>
</div>
<?php view('layouts/footer', ['scripts' => ['assets/js/ai-settings-api.js', 'assets/js/ai-settings.js', 'assets/js/payment-method.js']]); ?>
