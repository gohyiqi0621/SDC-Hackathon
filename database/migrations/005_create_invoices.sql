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
