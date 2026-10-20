<?php
/**
 * ai_settings/_google_calendar_integration_card.php - GoogleCalendarIntegrationCard.
 *
 * Table: google_calendar_integrations (google_account_email, calendar_id,
 * connection_status, default_appointment_duration_minutes, timezone,
 * is_enabled). The access/refresh tokens stay on the server.
 *
 * "Connect Google Account" asks the server for a Google sign-in URL and sends
 * the browser there (OAuth). The page never asks for a Google password. After
 * Google returns, the server's callback redirects back here with
 * ?gcal=connected (or ?gcal=error&gcal_message=...); the page then re-reads
 * the status from the server rather than trusting that parameter.
 *
 * Expects: $timezones (DateTimeZone::listIdentifiers()).
 */
$defaultTimezone = 'Asia/Kuala_Lumpur';
$durations       = [15, 30, 45, 60, 90];
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
                    <h2>Google Calendar <span class="demo-chip" data-demo-chip hidden>Demo</span></h2>
                    <p>Let the assistant check free slots and book appointments straight into your calendar.</p>
                </div>
                <?php view('ai_settings/_integration_status_badge', ['name' => 'calendar']); ?>
            </div>

            <div class="panel-body">
                <div class="gcal-account">
                    <span class="gcal-account__icon" aria-hidden="true"><i class="fa-brands fa-google"></i></span>
                    <div class="gcal-account__text">
                        <strong data-gcal-email>No Google account connected</strong>
                        <small data-gcal-account-hint>You'll sign in on Google's own page. This app never sees your Google password.</small>
                    </div>
                    <button class="primary-btn" type="button" data-action="oauth">
                        <i class="fa-brands fa-google" aria-hidden="true"></i> <span data-oauth-label>Connect Google Account</span>
                    </button>
                </div>

                <div class="form-row form-row--3">
                    <div class="field">
                        <div class="field-label-row">
                            <label for="calendar_id">Calendar</label>
                            <button class="link-btn" type="button" data-action="refresh-calendars" disabled>
                                <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i> Refresh
                            </button>
                        </div>
                        <select id="calendar_id" name="calendar_id" disabled aria-describedby="hint-calendar_id err-calendar_id">
                            <option value="">Connect an account first</option>
                        </select>
                        <p class="field-hint" id="hint-calendar_id" data-calendar-hint>Appointments are added to this calendar.</p>
                        <p class="field-error" id="err-calendar_id" data-error-for="calendar_id"></p>
                    </div>

                    <div class="field">
                        <label for="default_appointment_duration_minutes">Default appointment duration</label>
                        <select id="default_appointment_duration_minutes" name="default_appointment_duration_minutes"
                                aria-describedby="err-default_appointment_duration_minutes">
                            <?php foreach ($durations as $minutes): ?>
                                <option value="<?= (int) $minutes ?>"<?= $minutes === 30 ? ' selected' : '' ?>><?= (int) $minutes ?> minutes</option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-error" id="err-default_appointment_duration_minutes" data-error-for="default_appointment_duration_minutes"></p>
                    </div>

                    <div class="field">
                        <label for="timezone">Timezone</label>
                        <select id="timezone" name="timezone" aria-describedby="err-timezone">
                            <?php foreach ($timezones as $zone): ?>
                                <option value="<?= e($zone) ?>"<?= $zone === $defaultTimezone ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $zone)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-error" id="err-timezone" data-error-for="timezone"></p>
                    </div>
                </div>

                <div class="ai-result" data-result hidden></div>

                <div class="integration-actions">
                    <button class="danger-btn" type="button" data-action="disconnect" disabled>
                        <i class="fa-solid fa-link-slash" aria-hidden="true"></i> Disconnect
                    </button>
                    <span class="integration-actions__spacer"></span>
                    <button class="secondary-btn" type="button" data-action="test" disabled>
                        <i class="fa-regular fa-calendar-check" aria-hidden="true"></i> Test availability
                    </button>
                </div>
            </div>
        </section>

        <?php view('ai_settings/_save_button'); ?>

        <p class="field-hint gcal-keep-note">
            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
            Disconnecting stops new bookings from reaching Google Calendar. Appointment records already saved in this app are kept.
        </p>
    </form>
</div>
