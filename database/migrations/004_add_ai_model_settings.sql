-- ============================================================================
-- 004_add_ai_model_settings.sql
--
-- AI model, provider secret key and temperature for the assistant
-- (AI Settings & Integration > AI Assistant Settings).
--
--   ai_model           model ID, e.g. claude-haiku-5-5, deepseek-v4-flash
--   api_key_encrypted  the provider secret key, AES-256-GCM ciphertext written
--                      by Src\Support\Crypto. Never sent back to the browser.
--   temperature        0.00 - 1.00 (the page allows 0 - 1; the check allows
--                      up to 2 for providers with a wider range)
--
-- Only ADDs columns; existing rows and columns are untouched. Safe to rerun.
-- ============================================================================

BEGIN;

ALTER TABLE ai_assistant_settings
    ADD COLUMN IF NOT EXISTS ai_model          VARCHAR(80),
    ADD COLUMN IF NOT EXISTS api_key_encrypted TEXT,
    ADD COLUMN IF NOT EXISTS temperature       NUMERIC(3,2) NOT NULL DEFAULT 0.70;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ai_assistant_settings_temperature_check') THEN
        ALTER TABLE ai_assistant_settings
            ADD CONSTRAINT ai_assistant_settings_temperature_check CHECK (temperature >= 0 AND temperature <= 2);
    END IF;
END $$;

COMMIT;
