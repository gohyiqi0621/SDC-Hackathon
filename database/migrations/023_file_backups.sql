-- ============================================================================
-- 023_file_backups.sql
--
-- A copy of every uploaded file (logo, payment QR, product images, bank
-- references, invoice / claim files), so files survive a redeploy on hosts
-- whose disk is reset (Replit autoscale). See src/Support/FileBackup.php,
-- which also creates this table by itself. Safe to rerun.
-- ============================================================================

CREATE TABLE IF NOT EXISTS file_backups (
    area        VARCHAR(10)  NOT NULL,      -- 'public' (public/) or 'private' (storage/uploads/)
    path        VARCHAR(255) NOT NULL,      -- relative path, e.g. uploads/company/3/ab12.jpg
    mime        VARCHAR(100) NULL,
    data        BYTEA        NOT NULL,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (area, path)
);
