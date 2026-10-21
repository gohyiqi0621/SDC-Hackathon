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
