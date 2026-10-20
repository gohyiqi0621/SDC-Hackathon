<?php
/**
 * ai_settings/_section_state.php - the loading skeleton and load-error box
 * every section shows before its data arrives.
 *
 *   view('ai_settings/_section_state', ['what' => 'company information']);
 *
 * ai-settings.js shows exactly one of [data-view="loading" | "error" | "ready"].
 */
$what = $what ?? 'settings';
?>
<div class="ai-skeleton" data-view="loading" aria-busy="true" aria-label="Loading <?= e($what) ?>">
    <span class="sk sk--title"></span>
    <span class="sk sk--line"></span>
    <span class="sk sk--input"></span>
    <span class="sk sk--line sk--short"></span>
    <span class="sk sk--input"></span>
    <span class="sk sk--area"></span>
</div>

<div class="flash flash--error ai-load-error" role="alert" data-view="error" hidden>
    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
    <div>
        <strong>Could not load <?= e($what) ?></strong>
        <span data-error-text>Something went wrong while contacting the server.</span>
    </div>
    <button class="secondary-btn" type="button" data-retry>
        <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i> Try again
    </button>
</div>
