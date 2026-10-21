-- ============================================================================
-- 010_add_reply_language.sql
--
-- Which language the AI assistant answers in
-- (AI Settings & Integration > AI Assistant Settings > Reply language):
--
--   auto  the language of the customer's latest message (default)
--   en    always English          ms  always Bahasa Melayu
--   zh    always Chinese          ta  always Tamil
--
-- Only ADDs a column. Safe to rerun.
-- ============================================================================

BEGIN;

ALTER TABLE ai_assistant_settings
    ADD COLUMN IF NOT EXISTS reply_language VARCHAR(10) NOT NULL DEFAULT 'auto';

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ai_assistant_settings_reply_language_check') THEN
        ALTER TABLE ai_assistant_settings
            ADD CONSTRAINT ai_assistant_settings_reply_language_check
            CHECK (reply_language IN ('auto', 'en', 'ms', 'zh', 'ta'));
    END IF;
END $$;

COMMIT;
