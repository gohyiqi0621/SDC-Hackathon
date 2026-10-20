<?php
/**
 * ai_settings/_ai_assistant_settings_form.php - AIAssistantSettingsForm.
 *
 * Table: ai_assistant_settings (assistant_name, personality, tone_description,
 * system_instructions, is_enabled).
 *
 * The four instruction sections (general behaviour and three SOPs) are edited
 * separately but saved together in the single `system_instructions` field,
 * each under a "### <Section>" heading. ai-settings.js splits the stored text
 * back into the four boxes when it loads. No extra columns are needed.
 */
$personalities = [
    'friendly'     => ['Friendly',     'fa-regular fa-face-smile',  'Casual, upbeat and easy to talk to.'],
    'professional' => ['Professional', 'fa-solid fa-briefcase',     'Clear, polite and to the point.'],
    'warm'         => ['Warm',         'fa-solid fa-hand-holding-heart', 'Caring, patient and reassuring.'],
    'persuasive'   => ['Persuasive',   'fa-solid fa-bullhorn',      'Confident, highlights benefits and next steps.'],
    'custom'       => ['Custom',       'fa-solid fa-wand-magic-sparkles', 'Describe the exact tone yourself.'],
];

$sections = [
    'general'  => ['General AI behavior',   'fa-solid fa-sliders',        'How the assistant should behave in every conversation: language, what it must never say, when to hand over to a person.'],
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
                            <h2>Assistant <span class="demo-chip" data-demo-chip hidden>Demo</span></h2>
                            <p>Its name and personality, as customers will experience it.</p>
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
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>System instructions</h2>
                            <p>Rules the assistant follows. All four sections are saved together as one set of instructions.</p>
                        </div>
                    </div>
                    <div class="panel-body">
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
                                            <button class="link-btn" type="button" data-sop-template="<?= e($key) ?>">
                                                <i class="fa-solid fa-file-lines" aria-hidden="true"></i> Insert starter template
                                            </button>
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
                                <details class="sop-combined">
                                    <summary>Show the combined text that will be saved</summary>
                                    <pre data-sop-combined></pre>
                                </details>
                            </div>
                            <p class="field-error" data-error-for="system_instructions"></p>
                        </div>
                    </div>
                </section>
        </div>

        <?php view('ai_settings/_save_button'); ?>
    </form>
</div>
