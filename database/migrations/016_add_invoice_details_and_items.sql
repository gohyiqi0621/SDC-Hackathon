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
