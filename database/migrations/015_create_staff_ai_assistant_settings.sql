-- ============================================================================
-- 015_create_staff_ai_assistant_settings.sql
--
-- AI assistant settings for the STAFF assistant (AI Chatbot > Staff >
-- Setting & Integration). Same columns as ai_assistant_settings (the
-- customer assistant), kept in their own table so the two assistants can
-- have different names, models, keys and instructions.
--
-- Safe to rerun. Never touches `users` or ai_assistant_settings.
-- ============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS staff_ai_assistant_settings (
    id                  BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id             BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    assistant_name      VARCHAR(60)  NOT NULL,
    personality         VARCHAR(20)  NOT NULL DEFAULT 'friendly',
    tone_description    TEXT,
    system_instructions TEXT,
    is_enabled          BOOLEAN      NOT NULL DEFAULT TRUE,
    ai_model            VARCHAR(80),
    api_key_encrypted   TEXT,
    temperature         NUMERIC(3,2) NOT NULL DEFAULT 0.70,
    reply_language      VARCHAR(10)  NOT NULL DEFAULT 'auto',
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT staff_ai_assistant_settings_user_uniq UNIQUE (user_id),
    CONSTRAINT staff_ai_assistant_settings_personality_check
        CHECK (personality IN ('friendly', 'professional', 'warm', 'persuasive', 'custom')),
    CONSTRAINT staff_ai_assistant_settings_temperature_check
        CHECK (temperature >= 0 AND temperature <= 2),
    CONSTRAINT staff_ai_assistant_settings_reply_language_check
        CHECK (reply_language IN ('auto', 'en', 'ms', 'zh', 'ta'))
);

COMMIT;
