-- ============================================================================
-- 013_create_bookings.sql
--
-- Bookings made by the AI chatbot (Flowise tools check_availability and
-- make_booking), checked against and written to the business's Google
-- Calendar (AI Settings & Integration > Google Calendar).
--
--   google_calendar_integrations + booking rules:
--     booking_mode   single  one customer per time slot (office, clinic, salon)
--                    multi   many customers per slot (cafe, restaurant, class)
--     slot_capacity  multi only: how many bookings (or pax) one slot holds
--     capacity_unit  bookings | pax   what slot_capacity counts
--     open_time / close_time / open_days   when bookings are accepted
--                    (open_days: ISO weekdays, 1 = Monday ... 7 = Sunday)
--     The slot length is default_appointment_duration_minutes (003).
--
--   bookings  one row per booking, with the Google Calendar event it created.
--
-- Safe to rerun. Requires 003 (google_calendar_integrations).
-- ============================================================================

BEGIN;

ALTER TABLE google_calendar_integrations
    ADD COLUMN IF NOT EXISTS booking_mode  VARCHAR(10) NOT NULL DEFAULT 'single',
    ADD COLUMN IF NOT EXISTS slot_capacity INTEGER     NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS capacity_unit VARCHAR(10) NOT NULL DEFAULT 'bookings',
    ADD COLUMN IF NOT EXISTS open_time     TIME        NOT NULL DEFAULT '09:00',
    ADD COLUMN IF NOT EXISTS close_time    TIME        NOT NULL DEFAULT '18:00',
    ADD COLUMN IF NOT EXISTS open_days     VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7';

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'gcal_booking_mode_check') THEN
        ALTER TABLE google_calendar_integrations
            ADD CONSTRAINT gcal_booking_mode_check  CHECK (booking_mode IN ('single', 'multi')),
            ADD CONSTRAINT gcal_slot_capacity_check CHECK (slot_capacity BETWEEN 1 AND 1000),
            ADD CONSTRAINT gcal_capacity_unit_check CHECK (capacity_unit IN ('bookings', 'pax')),
            ADD CONSTRAINT gcal_open_hours_check    CHECK (open_time < close_time);
    END IF;
END $$;

-- Longer slots (2 h, 3 h) for dinners, events and classes.
ALTER TABLE google_calendar_integrations DROP CONSTRAINT IF EXISTS google_calendar_integrations_duration_check;
ALTER TABLE google_calendar_integrations
    ADD CONSTRAINT google_calendar_integrations_duration_check
        CHECK (default_appointment_duration_minutes IN (15, 30, 45, 60, 90, 120, 180));

CREATE TABLE IF NOT EXISTS bookings (
    id                BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id           BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    booking_no        VARCHAR(20)  NOT NULL,
    channel           VARCHAR(20)  NOT NULL DEFAULT 'telegram',
    customer_chat_id  VARCHAR(64)  NOT NULL,
    customer_name     VARCHAR(150),
    customer_username VARCHAR(64),
    customer_phone    VARCHAR(30),
    pax               INTEGER      NOT NULL DEFAULT 1,
    starts_at         TIMESTAMPTZ  NOT NULL,
    ends_at           TIMESTAMPTZ  NOT NULL,
    note              VARCHAR(500),
    status            VARCHAR(20)  NOT NULL DEFAULT 'confirmed',
    calendar_id       VARCHAR(255),
    google_event_id   VARCHAR(255),
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT bookings_user_no_uniq UNIQUE (user_id, booking_no),
    CONSTRAINT bookings_channel_check CHECK (channel IN ('telegram', 'web')),
    CONSTRAINT bookings_pax_check     CHECK (pax BETWEEN 1 AND 1000),
    CONSTRAINT bookings_time_check    CHECK (starts_at < ends_at),
    CONSTRAINT bookings_status_check  CHECK (status IN ('confirmed', 'cancelled'))
);

CREATE INDEX IF NOT EXISTS bookings_user_start_idx ON bookings (user_id, starts_at);
CREATE INDEX IF NOT EXISTS bookings_customer_idx   ON bookings (user_id, channel, customer_chat_id, starts_at DESC);

COMMIT;
