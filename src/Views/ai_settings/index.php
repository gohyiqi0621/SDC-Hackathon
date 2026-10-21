<?php
/**
 * ai_settings/index.php - AI Settings & Integration.
 *
 * Reached from AI Chatbot > Customer > Setting & Integration
 * (/?page=customer&action=settings). Four tabs, each one a reusable partial:
 *
 *   _company_information_form.php       company_profiles
 *   _ai_assistant_settings_form.php     ai_assistant_settings
 *   _telegram_integration_card.php      telegram_integrations
 *   _google_calendar_integration_card.php  google_calendar_integrations
 *
 * The HTML is rendered empty; assets/js/ai-settings.js loads each section
 * through assets/js/ai-settings-api.js. $endpoints comes from
 * ChatbotController::aiSettingsEndpoints(), so every URL lives in one place.
 *
 * Expects: $user, $userId, $endpoints, $businessCategories, $timezones, $aiModels,
 *          $chatUrl.
 */
$tabs = [
    'company'   => ['Company Information',         'fa-solid fa-building'],
    'assistant' => ['AI Assistant Settings',       'fa-solid fa-robot'],
    'telegram'  => ['Telegram Integration',        'fa-brands fa-telegram'],
    'calendar'  => ['Google Calendar Integration', 'fa-regular fa-calendar-check'],
];

view('layouts/header', [
    'title'  => 'AI Settings & Integration',
    'styles' => ['assets/css/ai-settings.css'],
]);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>AI Settings &amp; Integration</h1>
        <p>Tell the assistant about your business, shape how it talks, and connect the channels it works in.</p>
    </div>
    <div class="welcome-actions">
        <a class="primary-btn" href="<?= e($chatUrl) ?>">
            <i class="fa-regular fa-comments"></i> Open chat interface
        </a>
    </div>
</section>

<div class="page-body ai-settings"
     data-ai-settings
     data-user-id="<?= (int) $userId ?>"
     data-endpoints="<?= e(json_encode($endpoints, JSON_UNESCAPED_SLASHES)) ?>">

    <div class="ai-tabs page-tabs" role="tablist" aria-label="AI settings sections">
        <?php foreach ($tabs as $key => [$label, $icon]): ?>
            <button class="page-tab<?= $key === 'company' ? ' active' : '' ?>" type="button" role="tab"
                    id="ai-tab-<?= e($key) ?>" aria-controls="ai-panel-<?= e($key) ?>"
                    aria-selected="<?= $key === 'company' ? 'true' : 'false' ?>"
                    tabindex="<?= $key === 'company' ? '0' : '-1' ?>" data-tab="<?= e($key) ?>">
                <i class="<?= e($icon) ?>" aria-hidden="true"></i>
                <span><?= e($label) ?></span>
                <span class="ai-tab-dot" data-tab-dirty="<?= e($key) ?>" hidden title="Unsaved changes"></span>
            </button>
        <?php endforeach; ?>
    </div>

    <section class="ai-panel" role="tabpanel" id="ai-panel-company" aria-labelledby="ai-tab-company" tabindex="0" data-panel="company">
        <?php view('ai_settings/_company_information_form', ['businessCategories' => $businessCategories]); ?>
    </section>

    <section class="ai-panel" role="tabpanel" id="ai-panel-assistant" aria-labelledby="ai-tab-assistant" tabindex="0" data-panel="assistant" hidden>
        <?php view('ai_settings/_ai_assistant_settings_form', ['aiModels' => $aiModels]); ?>
    </section>

    <section class="ai-panel" role="tabpanel" id="ai-panel-telegram" aria-labelledby="ai-tab-telegram" tabindex="0" data-panel="telegram" hidden>
        <?php view('ai_settings/_telegram_integration_card'); ?>
    </section>

    <section class="ai-panel" role="tabpanel" id="ai-panel-calendar" aria-labelledby="ai-tab-calendar" tabindex="0" data-panel="calendar" hidden>
        <?php view('ai_settings/_google_calendar_integration_card', ['timezones' => $timezones]); ?>
    </section>

</div>
<?php view('layouts/footer', ['scripts' => ['assets/js/ai-settings-api.js', 'assets/js/ai-settings.js']]); ?>
