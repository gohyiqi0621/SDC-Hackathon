-- ============================================================================
-- 008_telegram_connect_wizard.sql
--
-- Telegram connect flow on AI Settings & Integration:
--
--   bot_name               the chatbot's display name (set on Telegram with
--                          setMyName when connecting)
--   staff_link_code        one-time code in the "Link my Telegram" button
--   staff_link_expires_at  (t.me/<bot>?start=staff_<code>); when the owner
--                          presses Start, the webhook saves that chat as
--                          staff_telegram_chat_id and clears the code
--   staff_linked_name      who linked it (Telegram name), shown on the page
--
-- Only ADDs columns. Safe to rerun. Requires 003 and 007.
-- ============================================================================

BEGIN;

ALTER TABLE telegram_integrations
    ADD COLUMN IF NOT EXISTS bot_name              VARCHAR(64),
    ADD COLUMN IF NOT EXISTS staff_link_code       VARCHAR(40),
    ADD COLUMN IF NOT EXISTS staff_link_expires_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS staff_linked_name     VARCHAR(120);

COMMIT;
