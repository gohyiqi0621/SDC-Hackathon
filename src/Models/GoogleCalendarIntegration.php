<?php
/**
 * GoogleCalendarIntegration.php - google_calendar_integrations (one row per user). See UserSettingsRow for the query rules.
 *
 * The token columns hold Crypto ciphertext only and are never sent to the browser.
 * access_token_encrypted stores {token, expires_at} as JSON, so no extra
 * expiry column is needed.
 */
declare(strict_types=1);

namespace Src\Models;

final class GoogleCalendarIntegration extends UserSettingsRow
{
    protected function table(): string
    {
        return 'google_calendar_integrations';
    }

    protected function writable(): array
    {
        return ['google_account_email', 'calendar_id', 'access_token_encrypted', 'refresh_token_encrypted',
                'connection_status', 'default_appointment_duration_minutes', 'timezone', 'is_enabled',
                'booking_mode', 'slot_capacity', 'capacity_unit', 'open_time', 'close_time', 'open_days'];
    }
}
