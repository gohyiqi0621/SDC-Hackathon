<?php
/**
 * ai_settings/_google_calendar_integration_card.php - GoogleCalendarIntegrationCard.
 *
 * Table: google_calendar_integrations (google_account_email, calendar_id,
 * connection_status, timezone, is_enabled). The access/refresh tokens stay on
 * the server. default_appointment_duration_minutes is kept in the table but
 * no longer edited here (the server keeps the saved value, 30 by default).
 *
 * Layout: 1. Google account (sign in / switch / disconnect)
 *         2. Booking settings: calendar (one row), timezone (one row)
 *
 * "Connect Google account" asks the server for a Google sign-in URL and sends
 * the browser there (OAuth). The page never asks for a Google password. After
 * Google returns, the server's callback redirects back here with
 * ?gcal=connected (or ?gcal=error&gcal_message=...); the page then re-reads
 * the status from the server rather than trusting that parameter.
 *
 * Expects: $timezones (DateTimeZone::listIdentifiers()).
 */
$defaultTimezone = 'Asia/Kuala_Lumpur';

// Timezones grouped by region ("Asia", "Europe", ...) so the list is easy to scan.
$zonesByRegion = [];
foreach ($timezones as $zone) {
    $region = str_contains($zone, '/') ? strstr($zone, '/', true) : 'Other';
    $zonesByRegion[$region][] = $zone;
}
?>
<div class="ai-section" data-section="calendar">
    <?php view('ai_settings/_section_state', ['what' => 'the Google Calendar integration']); ?>

    <form class="ai-form" data-view="ready" data-form="calendar" novalidate hidden>
        <section class="panel integration-card">
            <div class="integration-head">
                <span class="integration-logo integration-logo--google" aria-hidden="true">
                    <i class="fa-regular fa-calendar-days"></i>
                </span>
                <div class="integration-title">
                    <h2>Google Calendar</h2>
                    <p>Your AI chatbot checks your free times and books customer appointments straight into Google Calendar.</p>
                </div>
                <?php view('ai_settings/_integration_status_badge', ['name' => 'calendar']); ?>
            </div>

            <div class="panel-body">

                <!-- 1. Google account -->
                <div class="gcal-block">
                    <h3 class="gcal-block__title"><span class="gcal-step">1</span> Google account</h3>

                    <div class="gcal-account" data-gcal-account>
                        <span class="gcal-account__icon" aria-hidden="true"><i class="fa-brands fa-google"></i></span>
                        <div class="gcal-account__text">
                            <strong data-gcal-email>Not connected</strong>
                            <small data-gcal-account-hint>Sign in on Google's own page. This app never sees your Google password.</small>
                        </div>
                        <div class="gcal-account__actions">
                            <button class="primary-btn" type="button" data-action="oauth">
                                <i class="fa-brands fa-google" aria-hidden="true"></i> <span data-oauth-label>Connect Google account</span>
                            </button>
                            <button class="danger-btn gcal-disconnect" type="button" data-action="disconnect" hidden>
                                <i class="fa-solid fa-link-slash" aria-hidden="true"></i> Disconnect
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 2. Booking settings -->
                <div class="gcal-block" data-gcal-settings>
                    <h3 class="gcal-block__title"><span class="gcal-step">2</span> Booking settings</h3>

                    <div class="field">
                        <div class="field-label-row">
                            <label for="calendar_id">Calendar for bookings</label>
                            <button class="link-btn" type="button" data-action="refresh-calendars" disabled>
                                <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i> Refresh list
                            </button>
                        </div>
                        <select id="calendar_id" name="calendar_id" disabled aria-describedby="hint-calendar_id err-calendar_id">
                            <option value="">Connect your Google account first</option>
                        </select>
                        <p class="field-hint" id="hint-calendar_id" data-calendar-hint>New appointments are added to this calendar.</p>
                        <p class="field-error" id="err-calendar_id" data-error-for="calendar_id"></p>
                    </div>

                    <div class="field">
                        <label for="timezone">Timezone</label>
                        <select id="timezone" name="timezone" aria-describedby="hint-timezone err-timezone">
                            <?php foreach ($zonesByRegion as $region => $zones): ?>
                                <optgroup label="<?= e($region) ?>">
                                    <?php foreach ($zones as $zone): ?>
                                        <option value="<?= e($zone) ?>"<?= $zone === $defaultTimezone ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $zone)) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-hint" id="hint-timezone">Your business's local time. Free times and booking times are read in this timezone.</p>
                        <p class="field-error" id="err-timezone" data-error-for="timezone"></p>
                    </div>
                </div>

                <div class="ai-result" data-result hidden></div>

                <p class="field-hint gcal-keep-note" data-gcal-keep-note hidden>
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                    Disconnecting stops new bookings from going to Google Calendar. Appointments already saved in this app are kept.
                </p>
            </div>
        </section>

        <?php view('ai_settings/_save_button', ['label' => 'Save booking settings']); ?>
    </form>
</div>
