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
