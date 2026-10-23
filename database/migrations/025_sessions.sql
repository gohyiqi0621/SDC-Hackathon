-- ============================================================================
-- 025_sessions.sql
--
-- Login sessions, kept in the database instead of files on the server's disk,
-- so users stay signed in when the host restarts or redeploys (Replit
-- autoscale resets the disk) or runs more than one instance. See
-- src/Support/DatabaseSessionHandler.php, which also creates this table by
-- itself. Safe to rerun.
-- ============================================================================

CREATE TABLE IF NOT EXISTS sessions (
    id          CHAR(64)     PRIMARY KEY,   -- SHA-256 of the session id (the cookie value is never stored)
    data        TEXT         NOT NULL,      -- base64 of PHP's serialized $_SESSION
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS sessions_updated_idx ON sessions (updated_at);
