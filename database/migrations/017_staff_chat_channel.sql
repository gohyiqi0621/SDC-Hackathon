-- ============================================================================
-- 017_staff_chat_channel.sql
--
-- The Staff Chat Interface talks to the staff AI assistant (Flowise) and keeps
-- its history in chat_messages with channel 'staff' (chat_id "staff-<user id>").
-- Staff chats are not shown in Customer > Chat Interface.
--
-- Safe to rerun. Requires 007 (chat_messages).
-- ============================================================================

BEGIN;

ALTER TABLE chat_messages DROP CONSTRAINT IF EXISTS chat_messages_channel_check;
ALTER TABLE chat_messages
    ADD CONSTRAINT chat_messages_channel_check CHECK (channel IN ('telegram', 'web', 'staff'));

COMMIT;
