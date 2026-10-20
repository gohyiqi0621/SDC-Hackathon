-- ============================================================================
-- Smart SME - PostgreSQL schema (users + password resets)
--
-- The application is PostgreSQL-only. Run this once against the database in
-- .env (default: sme_assistant):
--
--     psql -U yiqi -h 127.0.0.1 -d sme_assistant -f database/schema.sql
--
-- or, from the project root:
--
--     php bin/migrate.php
--
-- The statements are idempotent: running this file twice does not drop data.
-- ============================================================================

BEGIN;

-- ---------------------------------------------------------------- users ----
-- One kind of account: a regular user. There is no role column and no admin
-- portal, so nothing in the schema can grant elevated access.
CREATE TABLE IF NOT EXISTS users (
    id            BIGSERIAL    PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'active'
                  CHECK (status IN ('active', 'disabled')),
    last_login_at TIMESTAMPTZ  NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Emails are stored lower-cased by the app; these unique indexes are the last
-- line of defence against a duplicate account (including case variants).
CREATE UNIQUE INDEX IF NOT EXISTS users_email_uniq ON users (email);
CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_uniq ON users (LOWER(email));

CREATE INDEX IF NOT EXISTS users_status_idx ON users (status);

-- ------------------------------------------------------- password resets ---
-- A row is one issued reset link. Only the SHA-256 hash of the random token is
-- stored, never the token itself. The token is single-use (`used_at`) and
-- expires (`expires_at`), and `created_at` powers the request rate limiter.
CREATE TABLE IF NOT EXISTS password_resets (
    id           BIGSERIAL    PRIMARY KEY,
    user_id      BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    email        VARCHAR(190) NOT NULL,
    token_hash   CHAR(64)     NOT NULL,
    expires_at   TIMESTAMPTZ  NOT NULL,
    used_at      TIMESTAMPTZ  NULL,
    requested_ip VARCHAR(45)  NULL,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS password_resets_token_uniq ON password_resets (token_hash);
CREATE INDEX IF NOT EXISTS password_resets_user_idx ON password_resets (user_id);
CREATE INDEX IF NOT EXISTS password_resets_created_idx ON password_resets (created_at);

COMMIT;

-- ----------------------------------------------------------------------------
-- Create the first account with the CLI helper (it hashes the password):
--
--     php bin/create-user.php "Your Name" you@example.com
--
-- It prints a generated password once if you do not supply one. No demo
-- account is seeded, so a fresh database has no known credentials.
-- ----------------------------------------------------------------------------
