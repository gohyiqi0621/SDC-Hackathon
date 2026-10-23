-- ============================================================================
-- 024_app_sessions.sql
--
-- Sign-in sessions stored in PostgreSQL (src/Support/DbSessionHandler.php),
-- so users stay signed in when Replit moves the app to another machine or
-- redeploys it. The handler also creates this table by itself. Safe to rerun.
-- ============================================================================

CREATE TABLE IF NOT EXISTS app_sessions (
    id          VARCHAR(128) PRIMARY KEY,
    data        TEXT         NOT NULL DEFAULT '',
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS app_sessions_updated_idx ON app_sessions (updated_at);
