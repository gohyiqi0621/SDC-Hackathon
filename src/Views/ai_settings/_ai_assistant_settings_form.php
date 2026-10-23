<?php
/**
 * ai_settings/_ai_assistant_settings_form.php - AIAssistantSettingsForm.
 *
 * Table: ai_assistant_settings (assistant_name, personality, tone_description,
 * system_instructions, is_enabled, ai_model, api_key_encrypted, temperature).
 *
 * The three SOP sections are edited separately but saved together in the
 * single `system_instructions` field, each under a "### <Section>" heading.
 * ai-settings.js splits the stored text back into the boxes when it loads.
 *
 * "Generate instruction with AI" asks Agnes AI (POST ai_settings/generate_sops) for
 * three SOPs written from the saved company information, five steps each,
 * and fills the three boxes. Nothing is saved until the owner clicks Save.
 *
 * The secret key is sent once and stored encrypted; the page is only told
 * whether one is saved (has_api_key), never the key itself.
 *
 * Expects: $aiModels (AiSettingsController::AI_MODELS: id => [label, provider]).
 * Optional: $audience ('customer' default, or 'staff' on Staff Setting & Integration).
 * Staff: the System instructions (SOP) panel is replaced by "Functions"
 * checkboxes (check email / invoice / claims / orders), saved as `functions`.
 */
$audience = ($audience ?? 'customer') === 'staff' ? 'staff' : 'customer';
$byProvider = [];
foreach ($aiModels as $id => [$label, $provider]) {
    $byProvider[$provider][$id] = $label;
}
$personalities = [
    'friendly'     => ['Friendly',     'fa-regular fa-face-smile',  'Casual, upbeat and easy to talk to.'],
    'professional' => ['Professional', 'fa-solid fa-briefcase',     'Clear, polite and to the point.'],
    'warm'         => ['Warm',         'fa-solid fa-hand-holding-heart', 'Caring, patient and reassuring.'],
    'persuasive'   => ['Persuasive',   'fa-solid fa-bullhorn',      'Confident, highlights benefits and next steps.'],
    'custom'       => ['Custom',       'fa-solid fa-wand-magic-sparkles', 'Describe the exact tone yourself.'],
];

// Staff assistant: functions it may use (staff_ai_assistant_settings.functions).
$staffFunctions = \Src\Controllers\AiSettingsController::STAFF_FUNCTIONS;

$sections = [
    'service'  => ['Customer Service SOP',  'fa-solid fa-headset',        'Steps for complaints, order checks, refunds and returns.'],
    'booking'  => ['Booking SOP',           'fa-regular fa-calendar-plus','How to take an appointment: what to ask, opening hours, how far ahead, cancellations.'],
    'sales'    => ['Salesman SOP',          'fa-solid fa-hand-holding-dollar', 'How to recommend products, upsell, quote prices and close a sale.'],
];
?>
<div class="ai-section" data-section="assistant">
    <?php view('ai_settings/_section_state', ['what' => 'AI assistant settings']); ?>

    <form class="ai-form" data-view="ready" data-form="assistant" novalidate hidden>

        <div class="stack">
                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Assistant</h2>
                            <p>Its name and personality, as <?= $audience === 'staff' ? 'your team' : 'customers' ?> will experience it.</p>
                        </div>
                        <label class="switch">
                            <input type="checkbox" role="switch" id="is_enabled" name="is_enabled" value="1">
                            <span class="switch-track" aria-hidden="true"></span>
                            <span class="switch-label" data-switch-label>Enabled</span>
                        </label>
                    </div>
                    <div class="panel-body">
                        <div class="field">
                            <label for="assistant_name">Assistant name *</label>
                            <input id="assistant_name" name="assistant_name" type="text" maxlength="60" required
                                   placeholder="e.g. Aisyah from Ah Hock Store" aria-describedby="err-assistant_name">
                            <p class="field-error" id="err-assistant_name" data-error-for="assistant_name"></p>
                        </div>

                        <fieldset class="field">
                            <legend>Personality *</legend>
                            <div class="personality-grid">
                                <?php foreach ($personalities as $value => [$label, $icon, $hint]): ?>
                                    <label class="personality-option">
                                        <input type="radio" name="personality" value="<?= e($value) ?>">
                                        <span class="personality-card">
                                            <i class="<?= e($icon) ?>" aria-hidden="true"></i>
                                            <strong><?= e($label) ?></strong>
                                            <small><?= e($hint) ?></small>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="field-error" data-error-for="personality"></p>
                        </fieldset>

                        <div class="field">
                            <div class="field-label-row">
                                <label for="tone_description">Tone description <span data-tone-required hidden>*</span></label>
                                <span class="char-count" data-counter-for="tone_description" aria-hidden="true"></span>
                            </div>
                            <textarea id="tone_description" name="tone_description" rows="3" maxlength="500"
                                      placeholder="e.g. Speak like a helpful kakak at the shop: short sentences, mix in simple Malay greetings, use emoji sparingly."
                                      aria-describedby="hint-tone_description err-tone_description"></textarea>
                            <p class="field-hint" id="hint-tone_description" data-tone-hint>Optional. Fine-tunes the personality above. Required when you choose Custom.</p>
                            <p class="field-error" id="err-tone_description" data-error-for="tone_description"></p>
                        </div>

                        <div class="form-row">
                            <div class="field">
                                <label for="ai_model">AI model *</label>
                                <select id="ai_model" name="ai_model" required aria-describedby="hint-ai_model err-ai_model">
                                    <?php foreach ($byProvider as $provider => $models): ?>
                                        <optgroup label="<?= e($provider) ?>">
                                            <?php foreach ($models as $id => $label): ?>
                                                <option value="<?= e($id) ?>" data-provider="<?= e($provider) ?>"><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <p class="field-hint" id="hint-ai_model">The model that writes the assistant's replies.</p>
                                <p class="field-error" id="err-ai_model" data-error-for="ai_model"></p>
                            </div>

                            <div class="field">
                                <div class="field-label-row">
                                    <label for="temperature">Temperature</label>
                                    <output class="temperature-value" for="temperature" data-temperature-value>0.7</output>
                                </div>
                                <input id="temperature" name="temperature" type="range" min="0" max="1" step="0.1" value="0.7"
                                       class="temperature-range" aria-describedby="hint-temperature err-temperature">
                                <div class="temperature-scale" aria-hidden="true"><span>0 · precise</span><span>1 · creative</span></div>
                                <p class="field-hint" id="hint-temperature">Lower gives steadier, more consistent answers. Higher gives more varied wording.</p>
                                <p class="field-error" id="err-temperature" data-error-for="temperature"></p>
                            </div>
                        </div>

                        <div class="field">
                            <label for="reply_language">Reply language</label>
                            <select id="reply_language" name="reply_language" aria-describedby="hint-reply_language err-reply_language">
                                <?php foreach (\Src\Controllers\AiSettingsController::REPLY_LANGUAGES as $code => $label): ?>
                                    <option value="<?= e($code) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="field-hint" id="hint-reply_language">"Same language as the customer" answers Malay with Malay, English with English, Chinese with Chinese. Choose a fixed language to always reply in it.</p>
                            <p class="field-error" id="err-reply_language" data-error-for="reply_language"></p>
                        </div>

                        <div class="field">
                            <label for="api_key">Secret key <span data-api-key-provider>(Anthropic)</span></label>
                            <div class="input-wrap">
                                <input id="api_key" name="api_key" type="password" autocomplete="new-password"
                                       spellcheck="false" autocapitalize="off" maxlength="300"
                                       placeholder="sk-ant-..." data-api-key
                                       aria-describedby="hint-api_key err-api_key">
                                <button class="input-btn" type="button" data-toggle-password="api_key" aria-label="Show key" aria-controls="api_key">
                                    <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <p class="field-hint" id="hint-api_key" data-api-key-hint>Hidden by default. Sent to the server once and stored encrypted. It is never shown again.</p>
                            <p class="field-error" id="err-api_key" data-error-for="api_key"></p>
                        </div>
                    </div>
                </section>

<?php if ($audience === 'staff'): ?>
                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Functions</h2>
                            <p>What the staff assistant is allowed to look up for your team.</p>
                        </div>
                    </div>
                    <div class="panel-body">
                        <fieldset class="field">
                            <legend class="sr-only">Functions</legend>
                            <div class="function-grid">
                                <?php foreach ($staffFunctions as $key => [$label, $icon, $hint]): ?>
                                    <label class="function-option">
                                        <input type="checkbox" name="fn_<?= e($key) ?>" data-function="<?= e($key) ?>">
                                        <span class="function-card">
                                            <span class="function-icon"><i class="<?= e($icon) ?>" aria-hidden="true"></i></span>
                                            <span class="function-text">
                                                <strong><?= e($label) ?></strong>
                                                <small><?= e($hint) ?></small>
                                            </span>
                                            <span class="function-check" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="field-error" data-error-for="functions"></p>
                        </fieldset>
                    </div>
                </section>
<?php else: ?>
                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>System instructions</h2>
                            <p>Rules the assistant follows. All sections are saved together as one set of instructions.</p>
                        </div>
                        <button class="primary-btn sop-generate-btn" type="button" data-sop-generate>
                            <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Generate instruction with AI
                        </button>
                    </div>
                    <div class="panel-body">
                        <div class="sop-ai-note" data-sop-ai-note>
                            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                            <p>Agnes AI reads your saved <strong>Company Information</strong> and products, picks the three SOPs your
                                business needs most and writes 5 simple steps for each. Review and edit them, then click Save.</p>
                        </div>
                        <div class="sop-ai-result" data-sop-ai-result hidden aria-live="polite"></div>
                        <div class="sop-editor" data-sop-editor>
                            <div class="sop-tabs" role="tablist" aria-label="Instruction sections">
                                <?php $first = true; foreach ($sections as $key => [$label, $icon]): ?>
                                    <button class="sop-tab<?= $first ? ' active' : '' ?>" type="button" role="tab"
                                            id="sop-tab-<?= e($key) ?>" aria-controls="sop-panel-<?= e($key) ?>"
                                            aria-selected="<?= $first ? 'true' : 'false' ?>" tabindex="<?= $first ? '0' : '-1' ?>"
                                            data-sop-tab="<?= e($key) ?>">
                                        <i class="<?= e($icon) ?>" aria-hidden="true"></i>
                                        <span><?= e($label) ?></span>
                                        <span class="sop-filled" data-sop-filled="<?= e($key) ?>" hidden title="Has instructions">
                                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i><span class="sr-only">(filled in)</span>
                                        </span>
                                    </button>
                                <?php $first = false; endforeach; ?>
                            </div>

                            <?php $first = true; foreach ($sections as $key => [$label, $icon, $hint]): ?>
                                <div class="sop-panel" role="tabpanel" id="sop-panel-<?= e($key) ?>"
                                     aria-labelledby="sop-tab-<?= e($key) ?>" data-sop-panel="<?= e($key) ?>"<?= $first ? '' : ' hidden' ?>>
                                    <div class="field">
                                        <div class="field-label-row">
                                            <label for="sop-<?= e($key) ?>"><?= e($label) ?></label>
                                        </div>
                                        <textarea id="sop-<?= e($key) ?>" rows="10" class="sop-textarea" data-sop="<?= e($key) ?>"
                                                  data-sop-label="<?= e($label) ?>" spellcheck="true"
                                                  placeholder="<?= e($hint) ?>&#10;&#10;Tip: one rule per line, e.g.&#10;- Always greet the customer by name if known.&#10;- Never promise a delivery date."></textarea>
                                    </div>
                                </div>
                            <?php $first = false; endforeach; ?>

                            <input type="hidden" name="system_instructions" data-sop-output>

                            <div class="sop-footer">
                                <span class="char-count" data-sop-count></span>
                            </div>
                            <p class="field-error" data-error-for="system_instructions"></p>
                        </div>
                    </div>
                </section>
<?php endif; ?>
        </div>

        <?php view('ai_settings/_save_button'); ?>
    </form>
</div>
