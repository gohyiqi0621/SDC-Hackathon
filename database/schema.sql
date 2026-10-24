-- ============================================================================
-- schema.sql - the whole SME Smart database (PostgreSQL) in one file.
--
-- Contains everything that used to be split into database/migrations/001-024,
-- in the same order. Run by bin/migrate.php.
--
-- Safe to run on ANY database, new or old, as many times as you like:
-- tables are created only when missing and existing data is never dropped.
--
--     php bin/migrate.php
-- or: psql -h <host> -U <user> -d <database> -f database/schema.sql
-- ============================================================================

-- ===================================================== users + password resets

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


-- ===================================================== 001_create_product_categories

-- ============================================================================
-- 001_create_product_categories.sql
--
-- Product / service categories. Each user owns their own categories, so a
-- name only has to be unique per user, not across the whole app.
--
-- Safe to rerun: every statement uses IF NOT EXISTS. Never touches `users`.
-- ============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS product_categories (
    id          BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    -- Same type as users.id (BIGSERIAL = BIGINT). Deleting a user removes their
    -- categories, matching how password_resets already behaves.
    user_id     BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    name        VARCHAR(100) NOT NULL,
    description TEXT,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- A category name is unique per user.
    CONSTRAINT product_categories_user_name_uniq UNIQUE (user_id, name),

    -- Target of the composite foreign key in products, which guarantees a
    -- product can only use a category owned by the same user.
    CONSTRAINT product_categories_user_id_id_uniq UNIQUE (user_id, id)
);

-- (user_id, name) and (user_id, id) above already index every lookup by user.

COMMIT;


-- ===================================================== 002_create_products

-- ============================================================================
-- 002_create_products.sql
--
-- Products and services. Every row belongs to one user and one of that same
-- user's categories. `characteristics` is plain free text (no JSON, no extra
-- table): users can type sentences, specs or bullet points.
--
-- Safe to rerun: every statement uses IF NOT EXISTS. Never touches `users`.
-- Requires 001_create_product_categories.sql.
-- ============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS products (
    id              BIGINT        GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id         BIGINT        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    category_id     BIGINT        NOT NULL,
    name            VARCHAR(200)  NOT NULL,
    sku             VARCHAR(100)  NULL,
    description     TEXT,
    characteristics TEXT,
    image_url       TEXT,
    price           NUMERIC(12,2) NOT NULL,
    promotion_price NUMERIC(12,2) NULL,
    currency        CHAR(3)       NOT NULL DEFAULT 'MYR',
    status          VARCHAR(20)   NOT NULL DEFAULT 'active',
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT products_price_check
        CHECK (price >= 0),
    CONSTRAINT products_promotion_price_check
        CHECK (promotion_price IS NULL OR (promotion_price >= 0 AND promotion_price <= price)),
    CONSTRAINT products_status_check
        CHECK (status IN ('active', 'inactive')),
    CONSTRAINT products_currency_check
        CHECK (currency ~ '^[A-Z]{3}$'),

    -- SKU is unique per user when given; NULLs never conflict with each other,
    -- so any number of products can have no SKU.
    CONSTRAINT products_user_sku_uniq UNIQUE (user_id, sku),

    -- The category must belong to the same user as the product.
    -- NO ACTION blocks deleting a category that still has products (like
    -- RESTRICT), but is checked at the end of the statement, so deleting a
    -- user can still cascade through both their products and categories.
    CONSTRAINT products_category_owner_fk
        FOREIGN KEY (user_id, category_id)
        REFERENCES product_categories (user_id, id)
        ON DELETE NO ACTION
);

-- Listing, filtering and sorting are always scoped to one user.
CREATE INDEX IF NOT EXISTS products_user_category_idx ON products (user_id, category_id);
CREATE INDEX IF NOT EXISTS products_user_name_idx     ON products (user_id, LOWER(name));
CREATE INDEX IF NOT EXISTS products_user_status_idx   ON products (user_id, status);
CREATE INDEX IF NOT EXISTS products_user_created_idx  ON products (user_id, created_at DESC);

COMMIT;


-- ===================================================== 003_create_ai_settings_tables

-- ============================================================================
-- 003_create_ai_settings_tables.sql
--
-- Tables behind AI Chatbot > Customer > Setting & Integration:
--   company_profiles, ai_assistant_settings,
--   telegram_integrations, google_calendar_integrations
--
-- One row per user in each table (UNIQUE user_id). Columns follow the agreed
-- design exactly; nothing extra is added.
--
-- Safe to rerun: every statement uses IF NOT EXISTS, so if a teammate already
-- created these tables, they are left exactly as they are. Never touches
-- `users` or any other existing table.
--
-- Secrets: bot_token_encrypted, access_token_encrypted and
-- refresh_token_encrypted only ever hold AES-256-GCM ciphertext written by
-- Src\Support\Crypto (key: APP_ENCRYPTION_KEY in .env).
-- ============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS company_profiles (
    id                  BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id             BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    company_name        VARCHAR(150) NOT NULL,
    company_logo_url    TEXT,
    company_background  TEXT,
    company_description TEXT,
    business_category   VARCHAR(100),
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT company_profiles_user_uniq UNIQUE (user_id)
);

CREATE TABLE IF NOT EXISTS ai_assistant_settings (
    id                  BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id             BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    assistant_name      VARCHAR(60)  NOT NULL,
    personality         VARCHAR(20)  NOT NULL DEFAULT 'friendly',
    tone_description    TEXT,
    -- General behaviour + Customer Service / Booking / Salesman SOPs, together,
    -- each under a "### <Section>" heading.
    system_instructions TEXT,
    is_enabled          BOOLEAN      NOT NULL DEFAULT TRUE,
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT ai_assistant_settings_user_uniq UNIQUE (user_id),
    CONSTRAINT ai_assistant_settings_personality_check
        CHECK (personality IN ('friendly', 'professional', 'warm', 'persuasive', 'custom'))
);

CREATE TABLE IF NOT EXISTS telegram_integrations (
    id                     BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id                BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    bot_username           VARCHAR(64),
    bot_token_encrypted    TEXT,
    staff_telegram_chat_id VARCHAR(32),
    connection_status      VARCHAR(20) NOT NULL DEFAULT 'disconnected',
    is_enabled             BOOLEAN     NOT NULL DEFAULT FALSE,
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT telegram_integrations_user_uniq UNIQUE (user_id),
    CONSTRAINT telegram_integrations_status_check
        CHECK (connection_status IN ('connected', 'disconnected', 'error'))
);

CREATE TABLE IF NOT EXISTS google_calendar_integrations (
    id                                   BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id                              BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    google_account_email                 VARCHAR(320),
    calendar_id                          VARCHAR(255),
    access_token_encrypted               TEXT,
    refresh_token_encrypted              TEXT,
    connection_status                    VARCHAR(20)  NOT NULL DEFAULT 'disconnected',
    default_appointment_duration_minutes INTEGER      NOT NULL DEFAULT 30,
    timezone                             VARCHAR(64)  NOT NULL DEFAULT 'Asia/Kuala_Lumpur',
    is_enabled                           BOOLEAN      NOT NULL DEFAULT FALSE,
    updated_at                           TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT google_calendar_integrations_user_uniq UNIQUE (user_id),
    CONSTRAINT google_calendar_integrations_status_check
        CHECK (connection_status IN ('connected', 'disconnected', 'error')),
    CONSTRAINT google_calendar_integrations_duration_check
        CHECK (default_appointment_duration_minutes IN (15, 30, 45, 60, 90))
);

COMMIT;


-- ===================================================== 003_create_gmail_connections

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


-- ===================================================== 004_add_ai_model_settings

-- ============================================================================
-- 004_add_ai_model_settings.sql
--
-- AI model, provider secret key and temperature for the assistant
-- (AI Settings & Integration > AI Assistant Settings).
--
--   ai_model           model ID, e.g. claude-haiku-5-5, deepseek-v4-flash
--   api_key_encrypted  the provider secret key, AES-256-GCM ciphertext written
--                      by Src\Support\Crypto. Never sent back to the browser.
--   temperature        0.00 - 1.00 (the page allows 0 - 1; the check allows
--                      up to 2 for providers with a wider range)
--
-- Only ADDs columns; existing rows and columns are untouched. Safe to rerun.
-- ============================================================================

BEGIN;

ALTER TABLE ai_assistant_settings
    ADD COLUMN IF NOT EXISTS ai_model          VARCHAR(80),
    ADD COLUMN IF NOT EXISTS api_key_encrypted TEXT,
    ADD COLUMN IF NOT EXISTS temperature       NUMERIC(3,2) NOT NULL DEFAULT 0.70;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ai_assistant_settings_temperature_check') THEN
        ALTER TABLE ai_assistant_settings
            ADD CONSTRAINT ai_assistant_settings_temperature_check CHECK (temperature >= 0 AND temperature <= 2);
    END IF;
END $$;

COMMIT;


-- ===================================================== 005_create_invoices

-- ============================================================================
-- 005_create_invoices.sql
--
-- Invoices, added by hand or uploaded as a PDF / image. Every row belongs to
-- one user. The uploaded file lives outside public/ (storage/uploads/invoices)
-- and is only served through the app after an ownership check; `file_path`
-- is that path relative to storage/uploads.
--
-- `status` is kept by Settlement later: unpaid -> partial -> paid.
--
-- Safe to rerun: every statement uses IF NOT EXISTS. Never touches `users`.
-- ============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS invoices (
    id              BIGINT        GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id         BIGINT        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    invoice_number  VARCHAR(100)  NOT NULL,
    party_name      VARCHAR(200)  NOT NULL,
    issue_date      DATE          NOT NULL,
    due_date        DATE          NULL,
    amount          NUMERIC(12,2) NOT NULL,
    currency        CHAR(3)       NOT NULL DEFAULT 'MYR',
    status          VARCHAR(20)   NOT NULL DEFAULT 'unpaid',
    notes           TEXT,
    file_path       TEXT          NULL,
    file_name       VARCHAR(255)  NULL,
    file_mime       VARCHAR(100)  NULL,
    file_size       INTEGER       NULL,
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT invoices_amount_check
        CHECK (amount >= 0),
    CONSTRAINT invoices_status_check
        CHECK (status IN ('unpaid', 'partial', 'paid')),
    CONSTRAINT invoices_currency_check
        CHECK (currency ~ '^[A-Z]{3}$'),
    CONSTRAINT invoices_due_after_issue_check
        CHECK (due_date IS NULL OR due_date >= issue_date)
);

-- One invoice number per user (case-insensitive), so a double upload is caught.
CREATE UNIQUE INDEX IF NOT EXISTS invoices_user_number_uniq
    ON invoices (user_id, LOWER(invoice_number));

CREATE INDEX IF NOT EXISTS invoices_user_created_idx
    ON invoices (user_id, created_at DESC);

COMMIT;


-- ===================================================== 006_create_claims

-- ============================================================================
-- 006_create_claims.sql
--
-- Expense claims: a staff member spent their own money and wants it back.
-- Every row belongs to one user (the business owner using the portal). The
-- receipt, if any, lives outside public/ (storage/uploads/claims) and is only
-- served through the app after an ownership check.
--
-- Status flow: submitted -> approved -> reimbursed, or submitted -> rejected.
--
-- Safe to rerun: every statement uses IF NOT EXISTS. Never touches `users`.
-- ============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS claims (
    id              BIGINT        GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id         BIGINT        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    claimant_name   VARCHAR(150)  NOT NULL,
    title           VARCHAR(200)  NOT NULL,
    category        VARCHAR(30)   NOT NULL,
    expense_date    DATE          NOT NULL,
    amount          NUMERIC(12,2) NOT NULL,
    currency        CHAR(3)       NOT NULL DEFAULT 'MYR',
    status          VARCHAR(20)   NOT NULL DEFAULT 'submitted',
    notes           TEXT,
    file_path       TEXT          NULL,
    file_name       VARCHAR(255)  NULL,
    file_mime       VARCHAR(100)  NULL,
    file_size       INTEGER       NULL,
    decided_at      TIMESTAMPTZ   NULL,
    reimbursed_at   TIMESTAMPTZ   NULL,
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT claims_amount_check
        CHECK (amount >= 0),
    CONSTRAINT claims_status_check
        CHECK (status IN ('submitted', 'approved', 'rejected', 'reimbursed')),
    CONSTRAINT claims_category_check
        CHECK (category IN ('travel', 'meals', 'office', 'other')),
    CONSTRAINT claims_currency_check
        CHECK (currency ~ '^[A-Z]{3}$')
);

CREATE INDEX IF NOT EXISTS claims_user_created_idx
    ON claims (user_id, created_at DESC);

COMMIT;


-- ===================================================== 007_create_telegram_messaging

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


-- ===================================================== 008_telegram_connect_wizard

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


-- ===================================================== 009_create_orders

-- ============================================================================
-- 009_create_orders.sql
--
-- Orders placed by customers through the AI chatbot (Flowise tool
-- buy_product), paid by transfer and checked by staff:
--
--   pending_payment    order created, waiting for the customer's receipt
--   payment_submitted  receipt received in Telegram, waiting for staff
--   paid               staff approved the receipt (customer told on Telegram)
--   payment_rejected   staff rejected it; the customer can send a new receipt
--   cancelled          cancelled by staff
--
--   orders       one row per order; receipt_* is the customer's proof of
--                payment, stored outside public/ (storage/uploads/receipts)
--   order_items  the products in the order, with name and price copied at
--                the moment of ordering (later price changes do not alter it)
--
--   company_profiles.payment_instructions  how customers pay (bank account,
--                DuitNow ID...). buy_product gives it to the customer.
--
-- Safe to rerun. Requires users, products (002), company_profiles (003).
-- ============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS orders (
    id                  BIGINT        GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id             BIGINT        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    order_no            VARCHAR(20)   NOT NULL,
    channel             VARCHAR(20)   NOT NULL DEFAULT 'telegram',
    customer_chat_id    VARCHAR(64)   NOT NULL,
    customer_name       VARCHAR(150),
    customer_username   VARCHAR(64),
    customer_phone      VARCHAR(30),
    delivery_note       TEXT,
    currency            CHAR(3)       NOT NULL DEFAULT 'MYR',
    total               NUMERIC(12,2) NOT NULL,
    status              VARCHAR(20)   NOT NULL DEFAULT 'pending_payment',
    receipt_path        TEXT,
    receipt_mime        VARCHAR(50),
    receipt_name        VARCHAR(160),
    receipt_uploaded_at TIMESTAMPTZ,
    reviewed_at         TIMESTAMPTZ,
    review_note         VARCHAR(300),
    created_at          TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT orders_user_order_no_uniq UNIQUE (user_id, order_no),
    CONSTRAINT orders_channel_check  CHECK (channel IN ('telegram', 'web')),
    CONSTRAINT orders_total_check    CHECK (total >= 0),
    CONSTRAINT orders_status_check
        CHECK (status IN ('pending_payment', 'payment_submitted', 'paid', 'payment_rejected', 'cancelled'))
);

CREATE INDEX IF NOT EXISTS orders_user_status_idx ON orders (user_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS orders_customer_idx    ON orders (user_id, channel, customer_chat_id, created_at DESC);

CREATE TABLE IF NOT EXISTS order_items (
    id           BIGINT        GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    order_id     BIGINT        NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
    product_id   BIGINT        REFERENCES products (id) ON DELETE SET NULL,
    product_name VARCHAR(200)  NOT NULL,
    sku          VARCHAR(100),
    unit_price   NUMERIC(12,2) NOT NULL,
    quantity     INTEGER       NOT NULL,
    line_total   NUMERIC(12,2) NOT NULL,

    CONSTRAINT order_items_quantity_check CHECK (quantity BETWEEN 1 AND 999),
    CONSTRAINT order_items_price_check    CHECK (unit_price >= 0 AND line_total >= 0)
);

CREATE INDEX IF NOT EXISTS order_items_order_idx ON order_items (order_id);

ALTER TABLE company_profiles
    ADD COLUMN IF NOT EXISTS payment_instructions TEXT;

COMMIT;


-- ===================================================== 010_add_reply_language

-- ============================================================================
-- 010_add_reply_language.sql
--
-- Which language the AI assistant answers in
-- (AI Settings & Integration > AI Assistant Settings > Reply language):
--
--   auto  the language of the customer's latest message (default)
--   en    always English          ms  always Bahasa Melayu
--   zh    always Chinese          ta  always Tamil
--
-- Only ADDs a column. Safe to rerun.
-- ============================================================================

BEGIN;

ALTER TABLE ai_assistant_settings
    ADD COLUMN IF NOT EXISTS reply_language VARCHAR(10) NOT NULL DEFAULT 'auto';

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ai_assistant_settings_reply_language_check') THEN
        ALTER TABLE ai_assistant_settings
            ADD CONSTRAINT ai_assistant_settings_reply_language_check
            CHECK (reply_language IN ('auto', 'en', 'ms', 'zh', 'ta'));
    END IF;
END $$;

COMMIT;


-- ===================================================== 011_add_payment_qr

-- ============================================================================
-- 011_add_payment_qr.sql
--
-- company_profiles.payment_qr_url: the payment QR image (DuitNow / bank QR)
-- uploaded in Company Information, stored like the logo under
-- public/uploads/company/<user id>/. buy_product sends it to the customer on
-- Telegram with the order total.
--
-- Only ADDs a column. Safe to rerun.
-- ============================================================================

BEGIN;

ALTER TABLE company_profiles
    ADD COLUMN IF NOT EXISTS payment_qr_url TEXT;

COMMIT;


-- ===================================================== 012_chat_message_customer

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


-- ===================================================== 013_create_bookings

-- ============================================================================
-- 013_create_bookings.sql
--
-- Bookings made by the AI chatbot (Flowise tools check_availability and
-- make_booking), checked against and written to the business's Google
-- Calendar (AI Settings & Integration > Google Calendar).
--
--   google_calendar_integrations + booking rules:
--     booking_mode   single  one customer per time slot (office, clinic, salon)
--                    multi   many customers per slot (cafe, restaurant, class)
--     slot_capacity  multi only: how many bookings (or pax) one slot holds
--     capacity_unit  bookings | pax   what slot_capacity counts
--     open_time / close_time / open_days   when bookings are accepted
--                    (open_days: ISO weekdays, 1 = Monday ... 7 = Sunday)
--     The slot length is default_appointment_duration_minutes (003).
--
--   bookings  one row per booking, with the Google Calendar event it created.
--
-- Safe to rerun. Requires 003 (google_calendar_integrations).
-- ============================================================================

BEGIN;

ALTER TABLE google_calendar_integrations
    ADD COLUMN IF NOT EXISTS booking_mode  VARCHAR(10) NOT NULL DEFAULT 'single',
    ADD COLUMN IF NOT EXISTS slot_capacity INTEGER     NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS capacity_unit VARCHAR(10) NOT NULL DEFAULT 'bookings',
    ADD COLUMN IF NOT EXISTS open_time     TIME        NOT NULL DEFAULT '09:00',
    ADD COLUMN IF NOT EXISTS close_time    TIME        NOT NULL DEFAULT '18:00',
    ADD COLUMN IF NOT EXISTS open_days     VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7';

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'gcal_booking_mode_check') THEN
        ALTER TABLE google_calendar_integrations
            ADD CONSTRAINT gcal_booking_mode_check  CHECK (booking_mode IN ('single', 'multi')),
            ADD CONSTRAINT gcal_slot_capacity_check CHECK (slot_capacity BETWEEN 1 AND 1000),
            ADD CONSTRAINT gcal_capacity_unit_check CHECK (capacity_unit IN ('bookings', 'pax')),
            ADD CONSTRAINT gcal_open_hours_check    CHECK (open_time < close_time);
    END IF;
END $$;

-- Longer slots (2 h, 3 h) for dinners, events and classes.
ALTER TABLE google_calendar_integrations DROP CONSTRAINT IF EXISTS google_calendar_integrations_duration_check;
ALTER TABLE google_calendar_integrations
    ADD CONSTRAINT google_calendar_integrations_duration_check
        CHECK (default_appointment_duration_minutes IN (15, 30, 45, 60, 90, 120, 180));

CREATE TABLE IF NOT EXISTS bookings (
    id                BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id           BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    booking_no        VARCHAR(20)  NOT NULL,
    channel           VARCHAR(20)  NOT NULL DEFAULT 'telegram',
    customer_chat_id  VARCHAR(64)  NOT NULL,
    customer_name     VARCHAR(150),
    customer_username VARCHAR(64),
    customer_phone    VARCHAR(30),
    pax               INTEGER      NOT NULL DEFAULT 1,
    starts_at         TIMESTAMPTZ  NOT NULL,
    ends_at           TIMESTAMPTZ  NOT NULL,
    note              VARCHAR(500),
    status            VARCHAR(20)  NOT NULL DEFAULT 'confirmed',
    calendar_id       VARCHAR(255),
    google_event_id   VARCHAR(255),
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT bookings_user_no_uniq UNIQUE (user_id, booking_no),
    CONSTRAINT bookings_channel_check CHECK (channel IN ('telegram', 'web')),
    CONSTRAINT bookings_pax_check     CHECK (pax BETWEEN 1 AND 1000),
    CONSTRAINT bookings_time_check    CHECK (starts_at < ends_at),
    CONSTRAINT bookings_status_check  CHECK (status IN ('confirmed', 'cancelled'))
);

CREATE INDEX IF NOT EXISTS bookings_user_start_idx ON bookings (user_id, starts_at);
CREATE INDEX IF NOT EXISTS bookings_customer_idx   ON bookings (user_id, channel, customer_chat_id, starts_at DESC);

COMMIT;


-- ===================================================== 014_order_shipped

-- ============================================================================
-- 014_order_shipped.sql
--
-- Orders: after staff approve the payment the order is "paid" (shown as
-- "Payment successful"); staff then mark it "shipped" when it is sent out
-- (the customer is told on Telegram). shipped_at records when.
--
-- Safe to rerun. Requires 009 (orders).
-- ============================================================================

BEGIN;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipped_at TIMESTAMPTZ;

ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check;
ALTER TABLE orders
    ADD CONSTRAINT orders_status_check
        CHECK (status IN ('pending_payment', 'payment_submitted', 'paid', 'shipped', 'payment_rejected', 'cancelled'));

COMMIT;


-- ===================================================== 015_create_staff_ai_assistant_settings

-- ============================================================================
-- 015_create_staff_ai_assistant_settings.sql
--
-- AI assistant settings for the STAFF assistant (AI Chatbot > Staff >
-- Setting & Integration). Same columns as ai_assistant_settings (the
-- customer assistant), kept in their own table so the two assistants can
-- have different names, models, keys and instructions.
--
-- Safe to rerun. Never touches `users` or ai_assistant_settings.
-- ============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS staff_ai_assistant_settings (
    id                  BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id             BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    assistant_name      VARCHAR(60)  NOT NULL,
    personality         VARCHAR(20)  NOT NULL DEFAULT 'friendly',
    tone_description    TEXT,
    system_instructions TEXT,
    is_enabled          BOOLEAN      NOT NULL DEFAULT TRUE,
    ai_model            VARCHAR(80),
    api_key_encrypted   TEXT,
    temperature         NUMERIC(3,2) NOT NULL DEFAULT 0.70,
    reply_language      VARCHAR(10)  NOT NULL DEFAULT 'auto',
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT staff_ai_assistant_settings_user_uniq UNIQUE (user_id),
    CONSTRAINT staff_ai_assistant_settings_personality_check
        CHECK (personality IN ('friendly', 'professional', 'warm', 'persuasive', 'custom')),
    CONSTRAINT staff_ai_assistant_settings_temperature_check
        CHECK (temperature >= 0 AND temperature <= 2),
    CONSTRAINT staff_ai_assistant_settings_reply_language_check
        CHECK (reply_language IN ('auto', 'en', 'ms', 'zh', 'ta'))
);

COMMIT;


-- ===================================================== 016_add_invoice_details_and_items

-- ============================================================================
-- 016_add_invoice_details_and_items.sql
--
-- Everything the printable invoice template needs (agreed with the template
-- owner): purchase or sales, the other party's address / phone, payment
-- terms, a reference (GRN / PO number), discount and SST, plus the line items.
--
-- `invoices.amount` stays the grand total, so the list, stats and
-- "Mark as paid" keep working:  amount = subtotal - discount_amount + sst_amount
-- `subtotal` is NULL for an invoice saved without line items (total only).
--
-- Our own company's details for the template come from company_profiles.
--
-- Safe to rerun: every statement uses IF NOT EXISTS. Never touches `users`.
-- Requires 005_create_invoices.sql.
-- ============================================================================

BEGIN;

-- purchase = a supplier billed us (we owe); sales = we billed a customer (they owe).
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS type            VARCHAR(10)   NOT NULL DEFAULT 'purchase'
    CONSTRAINT invoices_type_check CHECK (type IN ('purchase', 'sales'));
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS party_address   TEXT          NULL;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS party_phone     VARCHAR(50)   NULL;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS payment_terms   VARCHAR(100)  NULL;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS reference_no    VARCHAR(100)  NULL;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS subtotal        NUMERIC(12,2) NULL
    CONSTRAINT invoices_subtotal_check CHECK (subtotal IS NULL OR subtotal >= 0);
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS discount_amount NUMERIC(12,2) NOT NULL DEFAULT 0
    CONSTRAINT invoices_discount_check CHECK (discount_amount >= 0);
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS sst_rate        NUMERIC(5,2)  NOT NULL DEFAULT 0
    CONSTRAINT invoices_sst_rate_check CHECK (sst_rate >= 0 AND sst_rate <= 100);
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS sst_amount      NUMERIC(12,2) NOT NULL DEFAULT 0
    CONSTRAINT invoices_sst_amount_check CHECK (sst_amount >= 0);

-- One row per line on the invoice, in the order it was entered.
CREATE TABLE IF NOT EXISTS invoice_items (
    id          BIGINT        GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id  BIGINT        NOT NULL REFERENCES invoices (id) ON DELETE CASCADE,
    position    SMALLINT      NOT NULL,
    sku         VARCHAR(100)  NULL,
    description VARCHAR(255)  NOT NULL,
    uom         VARCHAR(20)   NULL,
    quantity    NUMERIC(12,3) NOT NULL,
    unit_price  NUMERIC(12,2) NOT NULL,
    discount    NUMERIC(12,2) NOT NULL DEFAULT 0,
    amount      NUMERIC(12,2) NOT NULL,

    CONSTRAINT invoice_items_quantity_check   CHECK (quantity > 0),
    CONSTRAINT invoice_items_unit_price_check CHECK (unit_price >= 0),
    CONSTRAINT invoice_items_discount_check   CHECK (discount >= 0),
    CONSTRAINT invoice_items_amount_check     CHECK (amount >= 0)
);

CREATE INDEX IF NOT EXISTS invoice_items_invoice_idx ON invoice_items (invoice_id, position);

COMMIT;


-- ===================================================== 016_staff_assistant_functions

-- ============================================================================
-- 016_staff_assistant_functions.sql
--
-- Staff assistant: which functions it may use, chosen with checkboxes on
-- AI Chatbot > Staff > Setting & Integration (replaces free-text system
-- instructions for the staff assistant).
--
--   functions  comma-separated: check_email, check_invoice, check_claims, check_orders
--
-- Safe to rerun. Requires 015 (staff_ai_assistant_settings).
-- ============================================================================

BEGIN;

ALTER TABLE staff_ai_assistant_settings
    ADD COLUMN IF NOT EXISTS functions VARCHAR(200) NOT NULL DEFAULT 'check_email,check_invoice,check_claims,check_orders';

COMMIT;


-- ===================================================== 017_staff_chat_channel

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


-- ===================================================== 018_chat_message_error_reason

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


-- ===================================================== 018_reconcile_invoice_items_print_columns

-- Bring pre-existing invoice_items tables up to the fields used by the
-- invoice upload form and printable purchase invoice. This is additive and
-- keeps all existing rows. Older rows without a quantity remain NULL rather
-- than being assigned an invented quantity; new uploads save the entered value.
BEGIN;

ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS sku VARCHAR(100) NULL;
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS quantity NUMERIC(12,3) NULL;

-- Some earlier local schemas called this field `qty`. Preserve those values
-- when present without requiring that legacy column to exist.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND table_name = 'invoice_items'
           AND column_name = 'qty'
    ) THEN
        EXECUTE 'UPDATE invoice_items SET quantity = NULLIF(BTRIM(qty::text), '''')::numeric WHERE quantity IS NULL AND BTRIM(qty::text) ~ ''^[0-9]+([.][0-9]+)?$''';
    END IF;
END $$;

COMMIT;


-- ===================================================== 019_company_receipt_details

-- ============================================================================
-- 019_company_receipt_details.sql
--
-- Company details printed on the official receipt (Orders > Official receipt
-- PDF): registration no. (SSM), address, phone and email. All optional; edited
-- on Settings > Company Profile. CompanyProfile also adds them by itself the
-- first time the profile is saved, so this file only makes it explicit.
--
-- Safe to rerun. Requires 003 (company_profiles).
-- ============================================================================

BEGIN;

ALTER TABLE company_profiles ADD COLUMN IF NOT EXISTS registration_no VARCHAR(60);
ALTER TABLE company_profiles ADD COLUMN IF NOT EXISTS company_address TEXT;
ALTER TABLE company_profiles ADD COLUMN IF NOT EXISTS company_phone   VARCHAR(40);
ALTER TABLE company_profiles ADD COLUMN IF NOT EXISTS company_email   VARCHAR(150);

COMMIT;


-- ===================================================== 020_company_payment_method

-- ============================================================================
-- 020_company_payment_method.sql
--
-- Payment Method (Customer Assistant > Setup): the bank or e-wallet customers
-- pay to, the account number and the account holder name. The existing
-- payment_instructions column is now "Payment notes" and payment_qr_url the
-- optional payment QR. The AI chatbot gives these to customers with the order
-- total. CompanyProfile also adds these columns by itself when saving.
--
-- Safe to rerun. Requires 003, 009, 011 (company_profiles).
-- ============================================================================

BEGIN;

ALTER TABLE company_profiles ADD COLUMN IF NOT EXISTS payment_bank         VARCHAR(80);
ALTER TABLE company_profiles ADD COLUMN IF NOT EXISTS payment_account_no   VARCHAR(40);
ALTER TABLE company_profiles ADD COLUMN IF NOT EXISTS payment_account_name VARCHAR(150);

COMMIT;


-- ===================================================== 021_order_official_receipt_sent

-- ============================================================================
-- 021_order_official_receipt_sent.sql
--
-- When the official receipt PDF was last sent to the customer on Telegram
-- (after the payment is verified, again on "Mark shipped" if it was never
-- sent, or with "Send receipt" on the Orders page). OrderService also adds
-- this column by itself, so this file only makes it explicit.
--
-- Safe to rerun. Requires 009 (orders).
-- ============================================================================

BEGIN;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS official_receipt_sent_at TIMESTAMPTZ;

COMMIT;


-- ===================================================== 022_invoice_create_flow_and_receipts

-- ============================================================================
-- 022_invoice_create_flow_and_receipts.sql
--
-- Invoices are now CREATED by the business for its customers (from its own
-- Products & Services), not uploaded:
--   * status gains 'draft'; a draft has no number yet. The number
--     (INV-2026-0001) is given when the invoice is created, so numbers have
--     no gaps from abandoned drafts.
--   * amount_paid / issued_at / order_id on invoices; product_id on items.
--   * receipts: one row per payment (partial payments allowed), numbered
--     RCPT-2026-0001. An invoice with payments cannot be deleted.
--   * document_counters: per company, per kind, per year running numbers.
--   * finance_settings: SST registered? and its rate, applied to every new invoice.
--
-- Safe to rerun: IF NOT EXISTS everywhere; constraints are dropped and
-- re-added. Never touches `users`. Requires 005, 016 and 009 (orders).
-- ============================================================================

BEGIN;

-- ------------------------------------------------------------- invoices ---

ALTER TABLE invoices ALTER COLUMN invoice_number DROP NOT NULL;

ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check;
ALTER TABLE invoices ADD CONSTRAINT invoices_status_check
    CHECK (status IN ('draft', 'unpaid', 'partial', 'paid'));

ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_number_unless_draft_check;
ALTER TABLE invoices ADD CONSTRAINT invoices_number_unless_draft_check
    CHECK (status = 'draft' OR invoice_number IS NOT NULL);

ALTER TABLE invoices ADD COLUMN IF NOT EXISTS amount_paid NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS issued_at   TIMESTAMPTZ   NULL;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS order_id    BIGINT        NULL REFERENCES orders (id) ON DELETE SET NULL;

ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_amount_paid_check;
ALTER TABLE invoices ADD CONSTRAINT invoices_amount_paid_check
    CHECK (amount_paid >= 0 AND amount_paid <= amount);

-- One invoice per order.
CREATE UNIQUE INDEX IF NOT EXISTS invoices_order_uniq ON invoices (order_id) WHERE order_id IS NOT NULL;

-- Invoices created from now on are sales invoices.
ALTER TABLE invoices ALTER COLUMN type SET DEFAULT 'sales';

-- Existing paid / partial invoices (from before receipts existed) keep a sensible amount_paid.
UPDATE invoices SET amount_paid = amount WHERE status = 'paid' AND amount_paid = 0;

-- ---------------------------------------------------------------- items ---

ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS product_id BIGINT NULL REFERENCES products (id) ON DELETE SET NULL;

-- ------------------------------------------------------------- receipts ---

CREATE TABLE IF NOT EXISTS receipts (
    id              BIGINT        GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id         BIGINT        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    invoice_id      BIGINT        NOT NULL REFERENCES invoices (id) ON DELETE RESTRICT,
    receipt_number  VARCHAR(30)   NOT NULL,
    amount          NUMERIC(12,2) NOT NULL,
    currency        CHAR(3)       NOT NULL DEFAULT 'MYR',
    payment_date    DATE          NOT NULL,
    method          VARCHAR(20)   NOT NULL,
    reference       VARCHAR(100)  NULL,
    notes           TEXT          NULL,
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT receipts_amount_check CHECK (amount > 0),
    CONSTRAINT receipts_method_check CHECK (method IN ('cash', 'bank_transfer', 'duitnow', 'card', 'cheque', 'other'))
);

CREATE UNIQUE INDEX IF NOT EXISTS receipts_user_number_uniq ON receipts (user_id, receipt_number);
CREATE INDEX        IF NOT EXISTS receipts_invoice_idx      ON receipts (invoice_id, payment_date);

-- ---------------------------------------------------- running numbers ---

CREATE TABLE IF NOT EXISTS document_counters (
    user_id  BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    kind     VARCHAR(20) NOT NULL,
    year     SMALLINT    NOT NULL,
    last_no  INTEGER     NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, kind, year)
);

-- ------------------------------------------------------ finance settings ---

CREATE TABLE IF NOT EXISTS finance_settings (
    user_id         BIGINT       PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
    sst_registered  BOOLEAN      NOT NULL DEFAULT FALSE,
    sst_rate        NUMERIC(5,2) NOT NULL DEFAULT 8,
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT finance_settings_sst_rate_check CHECK (sst_rate >= 0 AND sst_rate <= 100)
);

COMMIT;


-- ===================================================== 023_file_backups

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


-- ===================================================== 024_app_sessions

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


-- ===================================================== 024_void_cancelled_order_invoices

-- Preserve issued invoice records when their linked order is cancelled.
-- Drafts remain removable because they were never issued or numbered.
BEGIN;

ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check;
ALTER TABLE invoices ADD CONSTRAINT invoices_status_check
    CHECK (status IN ('draft', 'unpaid', 'partial', 'paid', 'voided'));

COMMIT;
