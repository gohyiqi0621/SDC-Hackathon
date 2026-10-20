-- ============================================================================
-- 003_create_gmail_connections.sql
--
-- One Gmail connection per user (read-only access to their inbox), so the
-- dashboard stays connected across sign-ins. Deleting a user removes their
-- connection, matching password_resets.
--
-- Safe to rerun: every statement uses IF NOT EXISTS. Never touches `users`.
-- ============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS gmail_connections (
    user_id       BIGINT       PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
    google_email  VARCHAR(190) NOT NULL,
    access_token  TEXT         NOT NULL,
    refresh_token TEXT,
    expires_at    TIMESTAMPTZ  NOT NULL,
    scope         TEXT,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMIT;
