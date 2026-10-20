<?php
/**
 * ai_settings/_save_button.php - SaveButton: the footer of every settings form.
 *
 *   view('ai_settings/_save_button', ['label' => 'Save changes']);
 *
 * Unsaved-changes pill, "Reset unsaved changes" and the submit button.
 * FormController in ai-settings.js enables both buttons only when the form
 * differs from what was last loaded or saved.
 *
 * Optional: $label (submit text), $resetLabel.
 */
$label      = $label ?? 'Save changes';
$resetLabel = $resetLabel ?? 'Reset unsaved changes';
?>
<div class="ai-form-footer">
    <p class="ai-form-status">
        <span class="dirty-pill" data-dirty-indicator hidden>
            <i class="fa-solid fa-circle" aria-hidden="true"></i> Unsaved changes
        </span>
        <span class="saved-meta" data-saved-meta></span>
    </p>
    <div class="ai-form-actions">
        <button class="secondary-btn" type="button" data-reset disabled>
            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i> <?= e($resetLabel) ?>
        </button>
        <button class="primary-btn" type="submit" data-save disabled>
            <i class="fa-regular fa-floppy-disk" aria-hidden="true"></i> <?= e($label) ?>
        </button>
    </div>
</div>
