-- ============================================================================
-- 007_create_telegram_messaging.sql
--
-- Telegram -> Website -> Flowise -> Telegram message flow.
--
--   telegram_integrations  + webhook_secret / webhook_url / webhook_set_at
--       webhook_secret is sent by Telegram in the
--       X-Telegram-Bot-Api-Secret-Token header on every webhook call, so the
--       website can prove a call really came from Telegram for that bot.
--   telegram_updates   update_ids already handled, so a Telegram retry is
--                      never answered twice.
--   chat_messages      every customer message and assistant reply (Telegram
--                      and the website's Chat Interface), for history/debug.
--
-- Only ADDs columns / creates new tables. Safe to rerun. Never touches `users`.
-- Requires 003_create_ai_settings_tables.sql.
-- ============================================================================

BEGIN;

ALTER TABLE telegram_integrations
    ADD COLUMN IF NOT EXISTS webhook_secret VARCHAR(64),
    ADD COLUMN IF NOT EXISTS webhook_url    TEXT,
    ADD COLUMN IF NOT EXISTS webhook_set_at TIMESTAMPTZ;

CREATE TABLE IF NOT EXISTS telegram_updates (
    integration_id BIGINT      NOT NULL REFERENCES telegram_integrations (id) ON DELETE CASCADE,
    update_id      BIGINT      NOT NULL,
    received_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (integration_id, update_id)
);

CREATE TABLE IF NOT EXISTS chat_messages (
    id         BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id    BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    channel    VARCHAR(20) NOT NULL,
    chat_id    VARCHAR(64) NOT NULL,
    role       VARCHAR(20) NOT NULL,
    content    TEXT        NOT NULL,
    status     VARCHAR(20) NOT NULL DEFAULT 'ok',
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chat_messages_channel_check CHECK (channel IN ('telegram', 'web')),
    CONSTRAINT chat_messages_role_check    CHECK (role IN ('customer', 'assistant')),
    CONSTRAINT chat_messages_status_check  CHECK (status IN ('ok', 'error', 'skipped'))
);

CREATE INDEX IF NOT EXISTS chat_messages_conversation_idx
    ON chat_messages (user_id, channel, chat_id, created_at DESC);

COMMIT;
