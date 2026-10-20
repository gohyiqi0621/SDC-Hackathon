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
