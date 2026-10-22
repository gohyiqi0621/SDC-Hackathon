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
 * Expects: $userId, $endpoints, $aiModels, $chatUrl, $steps, $companyUrl,
 *          $connectionsUrl.
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
<?php view('layouts/footer', ['scripts' => ['assets/js/ai-settings-api.js', 'assets/js/ai-settings.js']]); ?>
