-- ============================================================================
-- all_migrations.sql - the whole Smart SME schema in ONE block (PostgreSQL)
--
-- Combines database/schema.sql + database/migrations/001 ... 016.
--
-- Safe to run on ANY database, new or old, as many times as you like:
--   * a table that does not exist yet is created (already in its final shape)
--   * a table that already exists is kept with all its data; only the
--     columns, indexes and checks it is still missing are added
--   * nothing is ever dropped or emptied
--
-- Run it (from the project folder):
--     psql -U <user> -h 127.0.0.1 -d sme_assistant -f database/all_migrations.sql
-- or paste it into pgAdmin's Query Tool and press Execute.
--
-- Everything runs in one transaction: if any statement fails, nothing changes.
-- ============================================================================

BEGIN;

-- ============================================================ 1. USERS =====

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

CREATE UNIQUE INDEX IF NOT EXISTS users_email_uniq       ON users (email);
CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_uniq ON users (LOWER(email));
CREATE INDEX        IF NOT EXISTS users_status_idx       ON users (status);

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

CREATE UNIQUE INDEX IF NOT EXISTS password_resets_token_uniq  ON password_resets (token_hash);
CREATE INDEX        IF NOT EXISTS password_resets_user_idx    ON password_resets (user_id);
CREATE INDEX        IF NOT EXISTS password_resets_created_idx ON password_resets (created_at);

-- ============================================== 2. PRODUCTS & CATEGORIES =====

CREATE TABLE IF NOT EXISTS product_categories (
    id          BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id     BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    name        VARCHAR(100) NOT NULL,
    description TEXT,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT product_categories_user_name_uniq  UNIQUE (user_id, name),
    CONSTRAINT product_categories_user_id_id_uniq UNIQUE (user_id, id)
);

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
    CONSTRAINT products_user_sku_uniq UNIQUE (user_id, sku),
    CONSTRAINT products_category_owner_fk
        FOREIGN KEY (user_id, category_id)
        REFERENCES product_categories (user_id, id)
        ON DELETE NO ACTION
);

CREATE INDEX IF NOT EXISTS products_user_category_idx ON products (user_id, category_id);
CREATE INDEX IF NOT EXISTS products_user_name_idx     ON products (user_id, LOWER(name));
CREATE INDEX IF NOT EXISTS products_user_status_idx   ON products (user_id, status);
CREATE INDEX IF NOT EXISTS products_user_created_idx  ON products (user_id, created_at DESC);

-- ================================== 3. AI SETTINGS & INTEGRATION (per user) =====

CREATE TABLE IF NOT EXISTS company_profiles (
    id                   BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id              BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    company_name         VARCHAR(150) NOT NULL,
    company_logo_url     TEXT,
    company_background   TEXT,
    company_description  TEXT,
    business_category    VARCHAR(100),
    payment_instructions TEXT,                                   -- 009
    payment_qr_url       TEXT,                                   -- 011
    created_at           TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT company_profiles_user_uniq UNIQUE (user_id)
);

ALTER TABLE company_profiles
    ADD COLUMN IF NOT EXISTS payment_instructions TEXT,
    ADD COLUMN IF NOT EXISTS payment_qr_url       TEXT;

CREATE TABLE IF NOT EXISTS ai_assistant_settings (
    id                  BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id             BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    assistant_name      VARCHAR(60)  NOT NULL,
    personality         VARCHAR(20)  NOT NULL DEFAULT 'friendly',
    tone_description    TEXT,
    system_instructions TEXT,
    is_enabled          BOOLEAN      NOT NULL DEFAULT TRUE,
    ai_model            VARCHAR(80),                                    -- 004
    api_key_encrypted   TEXT,                                           -- 004
    temperature         NUMERIC(3,2) NOT NULL DEFAULT 0.70,             -- 004
    reply_language      VARCHAR(10)  NOT NULL DEFAULT 'auto',           -- 010
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT ai_assistant_settings_user_uniq UNIQUE (user_id),
    CONSTRAINT ai_assistant_settings_personality_check
        CHECK (personality IN ('friendly', 'professional', 'warm', 'persuasive', 'custom'))
);

ALTER TABLE ai_assistant_settings
    ADD COLUMN IF NOT EXISTS ai_model          VARCHAR(80),
    ADD COLUMN IF NOT EXISTS api_key_encrypted TEXT,
    ADD COLUMN IF NOT EXISTS temperature       NUMERIC(3,2) NOT NULL DEFAULT 0.70,
    ADD COLUMN IF NOT EXISTS reply_language    VARCHAR(10)  NOT NULL DEFAULT 'auto';

CREATE TABLE IF NOT EXISTS telegram_integrations (
    id                     BIGINT      GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id                BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    bot_username           VARCHAR(64),
    bot_token_encrypted    TEXT,
    staff_telegram_chat_id VARCHAR(32),
    connection_status      VARCHAR(20) NOT NULL DEFAULT 'disconnected',
    is_enabled             BOOLEAN     NOT NULL DEFAULT FALSE,
    webhook_secret         VARCHAR(64),                                 -- 007
    webhook_url            TEXT,                                        -- 007
    webhook_set_at         TIMESTAMPTZ,                                 -- 007
    bot_name               VARCHAR(64),                                 -- 008
    staff_link_code        VARCHAR(40),                                 -- 008
    staff_link_expires_at  TIMESTAMPTZ,                                 -- 008
    staff_linked_name      VARCHAR(120),                                -- 008
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT telegram_integrations_user_uniq UNIQUE (user_id),
    CONSTRAINT telegram_integrations_status_check
        CHECK (connection_status IN ('connected', 'disconnected', 'error'))
);

ALTER TABLE telegram_integrations
    ADD COLUMN IF NOT EXISTS webhook_secret        VARCHAR(64),
    ADD COLUMN IF NOT EXISTS webhook_url           TEXT,
    ADD COLUMN IF NOT EXISTS webhook_set_at        TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS bot_name              VARCHAR(64),
    ADD COLUMN IF NOT EXISTS staff_link_code       VARCHAR(40),
    ADD COLUMN IF NOT EXISTS staff_link_expires_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS staff_linked_name     VARCHAR(120);

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
    booking_mode                         VARCHAR(10)  NOT NULL DEFAULT 'single',          -- 013
    slot_capacity                        INTEGER      NOT NULL DEFAULT 1,                 -- 013
    capacity_unit                        VARCHAR(10)  NOT NULL DEFAULT 'bookings',        -- 013
    open_time                            TIME         NOT NULL DEFAULT '09:00',           -- 013
    close_time                           TIME         NOT NULL DEFAULT '18:00',           -- 013
    open_days                            VARCHAR(20)  NOT NULL DEFAULT '1,2,3,4,5,6,7',   -- 013
    updated_at                           TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT google_calendar_integrations_user_uniq UNIQUE (user_id),
    CONSTRAINT google_calendar_integrations_status_check
        CHECK (connection_status IN ('connected', 'disconnected', 'error'))
);

ALTER TABLE google_calendar_integrations
    ADD COLUMN IF NOT EXISTS booking_mode  VARCHAR(10) NOT NULL DEFAULT 'single',
    ADD COLUMN IF NOT EXISTS slot_capacity INTEGER     NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS capacity_unit VARCHAR(10) NOT NULL DEFAULT 'bookings',
    ADD COLUMN IF NOT EXISTS open_time     TIME        NOT NULL DEFAULT '09:00',
    ADD COLUMN IF NOT EXISTS close_time    TIME        NOT NULL DEFAULT '18:00',
    ADD COLUMN IF NOT EXISTS open_days     VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7';

-- ===================================================== 4. GMAIL (dashboard) =====

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

-- ============================================ 5. FINANCIAL (invoices, claims) =====

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

    CONSTRAINT invoices_amount_check          CHECK (amount >= 0),
    CONSTRAINT invoices_status_check          CHECK (status IN ('unpaid', 'partial', 'paid')),
    CONSTRAINT invoices_currency_check        CHECK (currency ~ '^[A-Z]{3}$'),
    CONSTRAINT invoices_due_after_issue_check CHECK (due_date IS NULL OR due_date >= issue_date)
);

CREATE UNIQUE INDEX IF NOT EXISTS invoices_user_number_uniq ON invoices (user_id, LOWER(invoice_number));
CREATE INDEX        IF NOT EXISTS invoices_user_created_idx ON invoices (user_id, created_at DESC);

-- 016: details the printable template needs; amount = subtotal - discount + SST.
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

    CONSTRAINT claims_amount_check   CHECK (amount >= 0),
    CONSTRAINT claims_status_check   CHECK (status IN ('submitted', 'approved', 'rejected', 'reimbursed')),
    CONSTRAINT claims_category_check CHECK (category IN ('travel', 'meals', 'office', 'other')),
    CONSTRAINT claims_currency_check CHECK (currency ~ '^[A-Z]{3}$')
);

CREATE INDEX IF NOT EXISTS claims_user_created_idx ON claims (user_id, created_at DESC);

-- ============================================= 6. CHAT (Telegram + website) =====

CREATE TABLE IF NOT EXISTS telegram_updates (
    integration_id BIGINT      NOT NULL REFERENCES telegram_integrations (id) ON DELETE CASCADE,
    update_id      BIGINT      NOT NULL,
    received_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (integration_id, update_id)
);

CREATE TABLE IF NOT EXISTS chat_messages (
    id                BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id           BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    channel           VARCHAR(20)  NOT NULL,
    chat_id           VARCHAR(64)  NOT NULL,
    role              VARCHAR(20)  NOT NULL,
    content           TEXT         NOT NULL,
    status            VARCHAR(20)  NOT NULL DEFAULT 'ok',
    customer_name     VARCHAR(150),                                    -- 012
    customer_username VARCHAR(64),                                     -- 012
    created_at        TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chat_messages_channel_check CHECK (channel IN ('telegram', 'web')),
    CONSTRAINT chat_messages_role_check    CHECK (role IN ('customer', 'assistant')),
    CONSTRAINT chat_messages_status_check  CHECK (status IN ('ok', 'error', 'skipped'))
);

ALTER TABLE chat_messages
    ADD COLUMN IF NOT EXISTS customer_name     VARCHAR(150),
    ADD COLUMN IF NOT EXISTS customer_username VARCHAR(64);

CREATE INDEX IF NOT EXISTS chat_messages_conversation_idx ON chat_messages (user_id, channel, chat_id, created_at DESC);
CREATE INDEX IF NOT EXISTS chat_messages_user_latest_idx  ON chat_messages (user_id, created_at DESC);

-- ================================================ 7. ORDERS (buy_product) =====

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
    shipped_at          TIMESTAMPTZ,                                     -- 014
    created_at          TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT orders_user_order_no_uniq UNIQUE (user_id, order_no),
    CONSTRAINT orders_channel_check CHECK (channel IN ('telegram', 'web')),
    CONSTRAINT orders_total_check   CHECK (total >= 0)
);

ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipped_at TIMESTAMPTZ;

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

-- ============================================= 8. BOOKINGS (Google Calendar) =====

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

    CONSTRAINT bookings_user_no_uniq  UNIQUE (user_id, booking_no),
    CONSTRAINT bookings_channel_check CHECK (channel IN ('telegram', 'web')),
    CONSTRAINT bookings_pax_check     CHECK (pax BETWEEN 1 AND 1000),
    CONSTRAINT bookings_time_check    CHECK (starts_at < ends_at),
    CONSTRAINT bookings_status_check  CHECK (status IN ('confirmed', 'cancelled'))
);

CREATE INDEX IF NOT EXISTS bookings_user_start_idx ON bookings (user_id, starts_at);
CREATE INDEX IF NOT EXISTS bookings_customer_idx   ON bookings (user_id, channel, customer_chat_id, starts_at DESC);

-- ============================== 8b. STAFF AI ASSISTANT SETTINGS (015) =====

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

-- ========================================== 9. CHECKS ADDED BY LATER MIGRATIONS =====
-- Added only when missing, so an older table gets them and a re-run is a no-op.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ai_assistant_settings_temperature_check') THEN
        ALTER TABLE ai_assistant_settings ADD CONSTRAINT ai_assistant_settings_temperature_check
            CHECK (temperature >= 0 AND temperature <= 2);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ai_assistant_settings_reply_language_check') THEN
        ALTER TABLE ai_assistant_settings ADD CONSTRAINT ai_assistant_settings_reply_language_check
            CHECK (reply_language IN ('auto', 'en', 'ms', 'zh', 'ta'));
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'gcal_booking_mode_check') THEN
        ALTER TABLE google_calendar_integrations ADD CONSTRAINT gcal_booking_mode_check
            CHECK (booking_mode IN ('single', 'multi'));
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'gcal_slot_capacity_check') THEN
        ALTER TABLE google_calendar_integrations ADD CONSTRAINT gcal_slot_capacity_check
            CHECK (slot_capacity BETWEEN 1 AND 1000);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'gcal_capacity_unit_check') THEN
        ALTER TABLE google_calendar_integrations ADD CONSTRAINT gcal_capacity_unit_check
            CHECK (capacity_unit IN ('bookings', 'pax'));
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'gcal_open_hours_check') THEN
        ALTER TABLE google_calendar_integrations ADD CONSTRAINT gcal_open_hours_check
            CHECK (open_time < close_time);
    END IF;
END $$;

-- Checks whose allowed values were widened later: replaced with the final list.
-- 013: longer appointment slots (2 h, 3 h).
ALTER TABLE google_calendar_integrations DROP CONSTRAINT IF EXISTS google_calendar_integrations_duration_check;
ALTER TABLE google_calendar_integrations ADD CONSTRAINT google_calendar_integrations_duration_check
    CHECK (default_appointment_duration_minutes IN (15, 30, 45, 60, 90, 120, 180));

-- 014: "shipped" order status.
ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check;
ALTER TABLE orders ADD CONSTRAINT orders_status_check
    CHECK (status IN ('pending_payment', 'payment_submitted', 'paid', 'shipped', 'payment_rejected', 'cancelled'));

COMMIT;

-- ----------------------------------------------------------------------------
-- 018_reconcile_invoice_items_print_columns.sql
-- ----------------------------------------------------------------------------
BEGIN;
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS sku VARCHAR(100) NULL;
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS quantity NUMERIC(12,3) NULL;
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

-- 022: invoices are created from Products & Services (drafts, auto numbers),
-- payments are receipts (partial allowed), per-company SST setting.
ALTER TABLE invoices ALTER COLUMN invoice_number DROP NOT NULL;
ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check;
ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('draft', 'unpaid', 'partial', 'paid'));
ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_number_unless_draft_check;
ALTER TABLE invoices ADD CONSTRAINT invoices_number_unless_draft_check CHECK (status = 'draft' OR invoice_number IS NOT NULL);
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS amount_paid NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS issued_at   TIMESTAMPTZ   NULL;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS order_id    BIGINT        NULL REFERENCES orders (id) ON DELETE SET NULL;
ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_amount_paid_check;
ALTER TABLE invoices ADD CONSTRAINT invoices_amount_paid_check CHECK (amount_paid >= 0 AND amount_paid <= amount);
CREATE UNIQUE INDEX IF NOT EXISTS invoices_order_uniq ON invoices (order_id) WHERE order_id IS NOT NULL;
ALTER TABLE invoices ALTER COLUMN type SET DEFAULT 'sales';
UPDATE invoices SET amount_paid = amount WHERE status = 'paid' AND amount_paid = 0;
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS product_id BIGINT NULL REFERENCES products (id) ON DELETE SET NULL;

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

CREATE TABLE IF NOT EXISTS document_counters (
    user_id  BIGINT      NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    kind     VARCHAR(20) NOT NULL,
    year     SMALLINT    NOT NULL,
    last_no  INTEGER     NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, kind, year)
);

CREATE TABLE IF NOT EXISTS finance_settings (
    user_id         BIGINT       PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
    sst_registered  BOOLEAN      NOT NULL DEFAULT FALSE,
    sst_rate        NUMERIC(5,2) NOT NULL DEFAULT 8,
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT finance_settings_sst_rate_check CHECK (sst_rate >= 0 AND sst_rate <= 100)
);

-- 023: preserve uploaded files across deployments.
CREATE TABLE IF NOT EXISTS file_backups (
    area        VARCHAR(10)  NOT NULL,
    path        VARCHAR(255) NOT NULL,
    mime        VARCHAR(100) NULL,
    data        BYTEA        NOT NULL,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (area, path)
);

-- 024: retain issued invoices as voided records when an order is cancelled.
ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check;
ALTER TABLE invoices ADD CONSTRAINT invoices_status_check
    CHECK (status IN ('draft', 'unpaid', 'partial', 'paid', 'voided'));

-- 025: login sessions in the database, so a restart / redeploy does not sign users out.
CREATE TABLE IF NOT EXISTS sessions (
    id          CHAR(64)     PRIMARY KEY,
    data        TEXT         NOT NULL,
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS sessions_updated_idx ON sessions (updated_at);
COMMIT;

-- ----------------------------------------------------------------------------
-- Check the result - every table should be listed:
--
--   SELECT table_name FROM information_schema.tables
--    WHERE table_schema = 'public' ORDER BY table_name;
--
-- Expected (23): ai_assistant_settings, bookings, chat_messages, claims,
-- company_profiles, document_counters, finance_settings, gmail_connections,
-- file_backups, google_calendar_integrations, invoice_items, receipts,
-- invoices, order_items, orders, password_resets, product_categories, products,
-- sessions, staff_ai_assistant_settings, telegram_integrations, telegram_updates, users
-- ----------------------------------------------------------------------------
