-- ============================================================================
-- 003_create_ai_settings_tables.sql
--
-- Tables behind AI Chatbot > Customer > Setting & Integration:
--   company_profiles, ai_assistant_settings,
--   telegram_integrations, google_calendar_integrations
--
-- One row per user in each table (UNIQUE user_id). Columns follow the agreed
-- design exactly; nothing extra is added.
--
-- Safe to rerun: every statement uses IF NOT EXISTS, so if a teammate already
-- created these tables, they are left exactly as they are. Never touches
-- `users` or any other existing table.
--
-- Secrets: bot_token_encrypted, access_token_encrypted and
-- refresh_token_encrypted only ever hold AES-256-GCM ciphertext written by
-- Src\Support\Crypto (key: APP_ENCRYPTION_KEY in .env).
-- ============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS company_profiles (
    id                  BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id             BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    company_name        VARCHAR(150) NOT NULL,
    company_logo_url    TEXT,
    company_background  TEXT,
    company_description TEXT,
    business_category   VARCHAR(100),
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT company_profiles_user_uniq UNIQUE (user_id)
);

CREATE TABLE IF NOT EXISTS ai_assistant_settings (
    id                  BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id             BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    assistant_name      VARCHAR(60)  NOT NULL,
    personality         VARCHAR(20)  NOT NULL DEFAULT 'friendly',
    tone_description    TEXT,
    -- General behaviour + Customer Service / Booking / Salesman SOPs, together,
    -- each under a "### <Section>" heading.
    system_instructions TEXT,
    is_enabled          BOOLEAN      NOT NULL DEFAULT TRUE,
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT ai_assistant_settings_user_uniq UNIQUE (user_id),
    CONSTRAINT ai_assistant_settings_personality_check
        CHECK (personality IN ('friendly', 'professional', 'warm', 'persuasive', 'custom'))
);

CREATE TABLE IF NOT EXISTS telegram_integrations (
    id                     BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id                BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    bot_username           VARCHAR(64),
    bot_token_encrypted    TEXT,
    staff_telegram_chat_id VARCHAR(32),
    connection_status      VARCHAR(20) NOT NULL DEFAULT 'disconnected',
    is_enabled             BOOLEAN     NOT NULL DEFAULT FALSE,
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT telegram_integrations_user_uniq UNIQUE (user_id),
    CONSTRAINT telegram_integrations_status_check
        CHECK (connection_status IN ('connected', 'disconnected', 'error'))
);

CREATE TABLE IF NOT EXISTS google_calendar_integrations (
    id                                   BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id                              BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    google_account_email                 VARCHAR(320),
    calendar_id                          VARCHAR(255),
    access_token_encrypted               TEXT,
    refresh_token_encrypted              TEXT,
    connection_status                    VARCHAR(20)  NOT NULL DEFAULT 'disconnected',
    default_appointment_duration_minutes INTEGER      NOT NULL DEFAULT 30,
    timezone                             VARCHAR(64)  NOT NULL DEFAULT 'Asia/Kuala_Lumpur',
    is_enabled                           BOOLEAN      NOT NULL DEFAULT FALSE,
    updated_at                           TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT google_calendar_integrations_user_uniq UNIQUE (user_id),
    CONSTRAINT google_calendar_integrations_status_check
        CHECK (connection_status IN ('connected', 'disconnected', 'error')),
    CONSTRAINT google_calendar_integrations_duration_check
        CHECK (default_appointment_duration_minutes IN (15, 30, 45, 60, 90))
);

COMMIT;
