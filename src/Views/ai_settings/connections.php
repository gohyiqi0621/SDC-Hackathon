<?php
/**
 * ai_settings/connections.php - Customer Assistant > Connections.
 *
 * Telegram (the chatbot customers talk to) and Google Calendar (bookings),
 * loaded and saved by assets/js/ai-settings.js like before. Linking your own
 * Telegram for staff alerts is on Staff Assistant > Connections.
 *
 * Expects: $userId, $endpoints, $timezones, $setupUrl, $staffConnectionsUrl.
 */
view('layouts/header', [
    'title'  => 'Customer Assistant Connections',
    'styles' => ['assets/css/ai-settings.css'],
]);
?>
<section class="welcome-banner welcome-banner--compact">
    <div class="welcome-text">
        <h1>Customer Assistant Connections</h1>
        <p>Where customers reach your assistant, and where their bookings go.</p>
    </div>
    <div class="welcome-actions">
        <a class="secondary-btn" href="<?= e($setupUrl) ?>"><i class="fa-solid fa-list-check"></i> Back to setup</a>
    </div>
</section>

<div class="page-body ai-settings connections-page connections-page--customer"
     data-ai-settings
     data-user-id="<?= (int) $userId ?>"
     data-endpoints="<?= e(json_encode($endpoints, JSON_UNESCAPED_SLASHES)) ?>">

    <nav class="connections-jump" aria-label="Connections on this page">
        <a href="#telegram"><i class="fa-brands fa-telegram" aria-hidden="true"></i> Telegram bot</a>
        <a href="#calendar"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i> Google Calendar</a>
    </nav>

    <section class="ai-panel connection-block" id="telegram" data-panel="telegram">
        <p class="connection-block__use"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            Used by the customer assistant: customers chat with it on Telegram, and send payment receipts there.
            Staff alerts (your own Telegram) are linked on <a href="<?= e($staffConnectionsUrl) ?>#alerts">Staff Assistant &gt; Connections</a>.</p>
        <?php view('ai_settings/_telegram_integration_card'); ?>
    </section>

    <section class="ai-panel connection-block" id="calendar" data-panel="calendar">
        <p class="connection-block__use"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            Optional. Used when customers book appointments or classes: the assistant checks free times and adds bookings.</p>
        <?php view('ai_settings/_google_calendar_integration_card', ['timezones' => $timezones]); ?>
    </section>
</div>
<?php view('layouts/footer', ['scripts' => ['assets/js/ai-settings-api.js', 'assets/js/ai-settings.js']]); ?>
