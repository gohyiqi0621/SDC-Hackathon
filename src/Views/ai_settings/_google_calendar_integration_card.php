<?php
/**
 * ai_settings/_google_calendar_integration_card.php - GoogleCalendarIntegrationCard.
 *
 * Table: google_calendar_integrations (google_account_email, calendar_id,
 * connection_status, timezone, is_enabled). The access/refresh tokens stay on
 * the server. default_appointment_duration_minutes is the booking length
 * ("Each booking lasts", block 3).
 *
 * Layout: 1. Google account (sign in / switch / disconnect)
 *         2. Booking settings: calendar (one row), timezone (one row)
 *         3. Booking rules (migration 013): one customer per slot or many,
 *            capacity, slot length, opening hours and days. The Flowise tools
 *            check_availability / make_booking follow these (BookingService).
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

                <!-- 3. Booking rules -->
                <div class="gcal-block" data-gcal-rules>
                    <h3 class="gcal-block__title"><span class="gcal-step">3</span> Booking rules</h3>
                    <p class="field-hint gcal-block__intro">The AI checks these rules and your Google Calendar before it books. Events you add in Google Calendar yourself also fill slots; an all-day event called "Closed" or "Cuti" closes that day.</p>

                    <fieldset class="field booking-mode">
                        <legend>How many customers can book the same time?</legend>
                        <div class="booking-mode__options">
                            <label class="booking-mode__card">
                                <input type="radio" name="booking_mode" value="single" checked>
                                <span class="booking-mode__body">
                                    <i class="fa-solid fa-user" aria-hidden="true"></i>
                                    <strong>One customer per slot</strong>
                                    <small>Office, clinic, salon, consultation. A slot is taken as soon as anything is in your calendar at that time.</small>
                                </span>
                            </label>
                            <label class="booking-mode__card">
                                <input type="radio" name="booking_mode" value="multi">
                                <span class="booking-mode__body">
                                    <i class="fa-solid fa-users" aria-hidden="true"></i>
                                    <strong>Many customers per slot</strong>
                                    <small>Cafe, restaurant, class, tour. Each slot takes bookings until it is full.</small>
                                </span>
                            </label>
                        </div>
                    </fieldset>

                    <div class="field-row" data-capacity-fields hidden>
                        <div class="field">
                            <label for="slot_capacity">Slot is full at</label>
                            <input id="slot_capacity" name="slot_capacity" type="number" min="2" max="1000" step="1" inputmode="numeric" value="10"
                                   aria-describedby="hint-slot_capacity err-slot_capacity">
                            <p class="field-hint" id="hint-slot_capacity" data-capacity-hint>For example 40 seats, or 10 tables.</p>
                            <p class="field-error" id="err-slot_capacity" data-error-for="slot_capacity"></p>
                        </div>
                        <div class="field">
                            <label for="capacity_unit">Count by</label>
                            <select id="capacity_unit" name="capacity_unit">
                                <option value="pax">People (pax), e.g. seats</option>
                                <option value="bookings">Bookings, e.g. tables</option>
                            </select>
                            <p class="field-hint">"People" adds up the pax of each booking. "Bookings" counts each booking as one.</p>
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label for="default_appointment_duration_minutes">Each booking lasts</label>
                            <select id="default_appointment_duration_minutes" name="default_appointment_duration_minutes"
                                    aria-describedby="err-default_appointment_duration_minutes">
                                <?php foreach ([15 => '15 minutes', 30 => '30 minutes', 45 => '45 minutes', 60 => '1 hour', 90 => '1 hour 30 minutes', 120 => '2 hours', 180 => '3 hours'] as $minutes => $label): ?>
                                    <option value="<?= $minutes ?>"<?= $minutes === 60 ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="field-error" id="err-default_appointment_duration_minutes" data-error-for="default_appointment_duration_minutes"></p>
                        </div>
                        <div class="field">
                            <label for="open_time">Opens</label>
                            <input id="open_time" name="open_time" type="time" value="09:00" step="900" aria-describedby="err-open_time">
                            <p class="field-error" id="err-open_time" data-error-for="open_time"></p>
                        </div>
                        <div class="field">
                            <label for="close_time">Closes</label>
                            <input id="close_time" name="close_time" type="time" value="18:00" step="900" aria-describedby="err-close_time">
                            <p class="field-error" id="err-close_time" data-error-for="close_time"></p>
                        </div>
                    </div>

                    <fieldset class="field">
                        <legend>Open days</legend>
                        <div class="day-chips">
                            <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $day => $label): ?>
                                <label class="day-chip"><input type="checkbox" name="open_day_<?= $day ?>" checked> <span><?= $label ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <p class="field-error" data-error-for="open_days"></p>
                    </fieldset>

                    <p class="booking-summary" data-booking-summary aria-live="polite"></p>
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
