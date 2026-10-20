<?php
/**
 * ai_settings/_company_information_form.php - CompanyInformationForm.
 *
 * Table: company_profiles (company_name, company_logo_url, company_background,
 * company_description, business_category). Field names match the columns so
 * the server can map them directly. The logo is sent as the `company_logo`
 * file; `remove_logo=1` clears the stored one.
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
                        <h2>Company information <span class="demo-chip" data-demo-chip hidden>Demo</span></h2>
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
                            <label for="company_description">Company introduction / description *</label>
                            <span class="char-count" data-counter-for="company_description" aria-hidden="true"></span>
                        </div>
                        <textarea id="company_description" name="company_description" rows="5" maxlength="2000" required
                                  placeholder="What you sell or do, who it is for, and what makes you different. This is how the assistant introduces you."
                                  aria-describedby="hint-company_description err-company_description"></textarea>
                        <p class="field-hint" id="hint-company_description">Customers may see this almost word for word. Keep it friendly and factual.</p>
                        <p class="field-error" id="err-company_description" data-error-for="company_description"></p>
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
                </div>
            </section>

        <?php view('ai_settings/_save_button'); ?>
    </form>
</div>
