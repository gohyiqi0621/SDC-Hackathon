-- ============================================================================
-- 012_chat_message_customer.sql
--
-- Who the customer is, on each customer message (Telegram first name and
-- @username), so Chat Interface > Customer conversations can list them by
-- name. Older messages keep NULL and are shown by chat ID.
--
-- Only ADDs columns and an index. Safe to rerun. Requires 007.
-- ============================================================================

BEGIN;

ALTER TABLE chat_messages
    ADD COLUMN IF NOT EXISTS customer_name     VARCHAR(150),
    ADD COLUMN IF NOT EXISTS customer_username VARCHAR(64);

CREATE INDEX IF NOT EXISTS chat_messages_user_latest_idx
    ON chat_messages (user_id, created_at DESC);

COMMIT;
