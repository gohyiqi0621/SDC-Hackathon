<?php
/**
 * ai_settings/_integration_status_badge.php - IntegrationStatusBadge.
 *
 *   view('ai_settings/_integration_status_badge', ['name' => 'telegram']);
 *
 * Starts as "Checking…"; ai-settings.js (StatusBadge.set) switches it to
 * connected / disconnected / error once the server answers. The badge only
 * ever shows "Connected" when the backend said so.
 *
 * Expects: $name. Optional: $status (checking|connected|disconnected|error).
 */
$status = $status ?? 'checking';
$states = [
    'checking'     => ['Checking…',    'fa-solid fa-spinner fa-spin'],
    'connected'    => ['Connected',    'fa-solid fa-circle-check'],
    'disconnected' => ['Disconnected', 'fa-regular fa-circle'],
    'error'        => ['Error',        'fa-solid fa-triangle-exclamation'],
];
[$label, $icon] = $states[$status] ?? $states['checking'];
?>
<span class="status-badge status-badge--<?= e($status) ?>" data-status-badge="<?= e($name) ?>" role="status" aria-live="polite">
    <i class="<?= e($icon) ?>" aria-hidden="true" data-status-icon></i>
    <span data-status-label><?= e($label) ?></span>
</span>
