<?php
/**
 * ai_settings/_company_information_form.php - CompanyInformationForm.
 *
 * Table: company_profiles (company_name, company_logo_url, company_background,
 * business_category, and for the official receipt: registration_no,
 * company_phone, company_email, company_address). company_description is no longer edited here; an existing
 * value is kept as it is. Field names match the columns so
 * the server can map them directly. The logo is sent as the `company_logo`
 * file; `remove_logo=1` clears the stored one. Payment details (bank, account,
 * QR) are on Customer Assistant > Setup > Payment Method.
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
                            <label for="company_background">Company background *</label>
                            <span class="char-count" data-counter-for="company_background" aria-hidden="true"></span>
                        </div>
                        <textarea id="company_background" name="company_background" rows="5" maxlength="3000" required
                                  placeholder="What the business does, history, founding year, number of outlets, certifications, opening hours, service areas..."
                                  aria-describedby="hint-company_background err-company_background"></textarea>
                        <p class="field-hint" id="hint-company_background">What your business does and background facts. Both assistants rely on it, and "Generate with AI" writes the instructions from it.</p>
                        <p class="field-error" id="err-company_background" data-error-for="company_background"></p>
                    </div>

                    <div class="field">
                        <label for="company_address">Company address *</label>
                        <textarea id="company_address" name="company_address" rows="3" maxlength="500" autocomplete="street-address" required
                                  placeholder="e.g. 12, Jalan Ampang,&#10;50450 Kuala Lumpur" aria-describedby="hint-company_address err-company_address"></textarea>
                        <p class="field-hint" id="hint-company_address">Printed on your official receipts.</p>
                        <p class="field-error" id="err-company_address" data-error-for="company_address"></p>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label for="registration_no">Registration no. (SSM) *</label>
                            <input id="registration_no" name="registration_no" type="text" maxlength="60" required placeholder="e.g. 202301012345 (1501234-A)"
                                   aria-describedby="err-registration_no">
                            <p class="field-error" id="err-registration_no" data-error-for="registration_no"></p>
                        </div>
                        <div class="field">
                            <label for="company_phone">Phone *</label>
                            <input id="company_phone" name="company_phone" type="tel" maxlength="40" autocomplete="tel" required placeholder="e.g. 03-1234 5678"
                                   aria-describedby="err-company_phone">
                            <p class="field-error" id="err-company_phone" data-error-for="company_phone"></p>
                        </div>
                    </div>

                    <div class="field">
                        <label for="company_email">Email *</label>
                        <input id="company_email" name="company_email" type="email" maxlength="150" autocomplete="email" required placeholder="e.g. hello@yourshop.my"
                               aria-describedby="err-company_email">
                        <p class="field-error" id="err-company_email" data-error-for="company_email"></p>
                    </div>

                </div>
            </section>

        <?php view('ai_settings/_save_button'); ?>
    </form>
</div>
