<?php
/**
 * ai_settings/_company_information_form.php - CompanyInformationForm.
 *
 * Table: company_profiles (company_name, company_logo_url, company_background,
 * business_category). company_description is no longer edited here; an existing
 * value is kept as it is. Field names match the columns so
 * the server can map them directly. The logo is sent as the `company_logo`
 * file; `remove_logo=1` clears the stored one. The payment QR image works the
 * same way (`payment_qr`, `remove_payment_qr=1`).
 *
 * Expects: $businessCategories.
 */
$logoMaxMb = 2;
?>
<div class="ai-section" data-section="company">
    <?php view('ai_settings/_section_state', ['what' => 'company information']); ?>

    <form class="ai-form" data-view="ready" data-form="company" novalidate hidden enctype="multipart/form-data">

        <div class="flash flash--info ai-empty-note" role="status" data-empty-note hidden>
            <i class="fa-solid fa-building-circle-check" aria-hidden="true"></i>
            <div>
                <strong>No company profile yet</strong>
                <span>Fill in the details below. The assistant uses them to introduce your business to customers.</span>
            </div>
        </div>

        <section class="panel">
                <div class="panel-header">
                    <div>
                        <h2>Company information</h2>
                        <p>Fields marked <span aria-hidden="true">*</span><span class="sr-only">with an asterisk</span> are required.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="logo-field">
                        <div class="image-preview logo-field__preview">
                            <img src="" alt="Company logo preview" data-logo-preview hidden>
                            <span class="image-placeholder" data-logo-placeholder><i class="fa-regular fa-image" aria-hidden="true"></i></span>
                        </div>
                        <div class="logo-field__body">
                            <span class="logo-field__label" id="company_logo_label">Company logo</span>
                            <input class="sr-only ai-file-input" id="company_logo" name="company_logo" type="file"
                                   accept="image/jpeg,image/png,image/webp" data-logo-input
                                   data-max-bytes="<?= (int) ($logoMaxMb * 1024 * 1024) ?>"
                                   aria-labelledby="company_logo_label" aria-describedby="hint-company_logo err-company_logo">
                            <label class="secondary-btn logo-field__button" for="company_logo">
                                <i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> <span data-logo-choose-label>Choose image</span>
                            </label>
                            <p class="field-hint" id="hint-company_logo">JPG, PNG or WebP · up to <?= (int) $logoMaxMb ?> MB</p>
                            <span class="field-hint logo-field__file" data-logo-file-name></span>
                            <label class="checkbox" data-logo-remove-wrap hidden>
                                <input type="checkbox" name="remove_logo" value="1" data-logo-remove> Remove logo
                            </label>
                        </div>
                    </div>
                    <p class="field-error logo-field__error" id="err-company_logo" data-error-for="company_logo"></p>

                    <div class="field">
                        <label for="company_name">Company name *</label>
                        <input id="company_name" name="company_name" type="text" maxlength="150" required
                               autocomplete="organization" placeholder="e.g. Kedai Runcit Ah Hock Sdn Bhd"
                               aria-describedby="err-company_name">
                        <p class="field-error" id="err-company_name" data-error-for="company_name"></p>
                    </div>

                    <div class="field">
                        <label for="business_category">Business category *</label>
                        <select id="business_category" name="business_category" required aria-describedby="err-business_category">
                            <option value="">Choose a category</option>
                            <?php foreach ($businessCategories as $category): ?>
                                <option value="<?= e($category) ?>"><?= e($category) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-error" id="err-business_category" data-error-for="business_category"></p>
                    </div>

                    <div class="field">
                        <div class="field-label-row">
                            <label for="company_background">Company background</label>
                            <span class="char-count" data-counter-for="company_background" aria-hidden="true"></span>
                        </div>
                        <textarea id="company_background" name="company_background" rows="5" maxlength="3000"
                                  placeholder="Optional: history, founding year, number of outlets, certifications, opening hours, service areas..."
                                  aria-describedby="hint-company_background err-company_background"></textarea>
                        <p class="field-hint" id="hint-company_background">Background facts the assistant can draw on when customers ask.</p>
                        <p class="field-error" id="err-company_background" data-error-for="company_background"></p>
                    </div>

                    <div class="field">
                        <div class="field-label-row">
                            <label for="payment_instructions">How customers pay</label>
                            <span class="char-count" data-counter-for="payment_instructions" aria-hidden="true"></span>
                        </div>
                        <textarea id="payment_instructions" name="payment_instructions" rows="3" maxlength="1000"
                                  placeholder="e.g. Maybank 5123 4567 8901 (Ah Hock Trading Sdn Bhd) or DuitNow 012-345 6789. Please send the receipt in this chat."
                                  aria-describedby="hint-payment_instructions err-payment_instructions"></textarea>
                        <p class="field-hint" id="hint-payment_instructions">When a customer orders through the AI chatbot, it sends them these payment details with the order total.</p>
                        <p class="field-error" id="err-payment_instructions" data-error-for="payment_instructions"></p>
                    </div>

                    <div class="logo-field qr-field">
                        <div class="image-preview logo-field__preview qr-field__preview">
                            <img src="" alt="Payment QR code preview" data-qr-preview hidden>
                            <span class="image-placeholder" data-qr-placeholder><i class="fa-solid fa-qrcode" aria-hidden="true"></i></span>
                        </div>
                        <div class="logo-field__body">
                            <span class="logo-field__label" id="payment_qr_label">Payment QR code <span class="field-optional">(optional)</span></span>
                            <input class="sr-only ai-file-input" id="payment_qr" name="payment_qr" type="file"
                                   accept="image/jpeg,image/png,image/webp" data-qr-input
                                   data-max-bytes="<?= (int) ($logoMaxMb * 1024 * 1024) ?>"
                                   aria-labelledby="payment_qr_label" aria-describedby="hint-payment_qr err-payment_qr">
                            <label class="secondary-btn logo-field__button" for="payment_qr">
                                <i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> <span data-qr-choose-label>Choose image</span>
                            </label>
                            <p class="field-hint" id="hint-payment_qr">e.g. your DuitNow / bank QR. Sent to the customer on Telegram with the order total. JPG, PNG or WebP · up to <?= (int) $logoMaxMb ?> MB</p>
                            <span class="field-hint logo-field__file" data-qr-file-name></span>
                            <label class="checkbox" data-qr-remove-wrap hidden>
                                <input type="checkbox" name="remove_payment_qr" value="1" data-qr-remove> Remove QR code
                            </label>
                        </div>
                    </div>
                    <p class="field-error logo-field__error" id="err-payment_qr" data-error-for="payment_qr"></p>
                </div>
            </section>

        <?php view('ai_settings/_save_button'); ?>
    </form>
</div>
