-- ============================================================================
-- 018_chat_message_error_reason.sql
--
-- When the AI could not answer (the customer got the "not available" reply),
-- keep the technical reason with that message, so Chat Interface can show
-- staff why it happened and how to fix it.
--
-- Safe to rerun. Requires 007 (chat_messages).
-- ============================================================================

ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS error_reason TEXT;
