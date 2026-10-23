-- Preserve issued invoice records when their linked order is cancelled.
-- Drafts remain removable because they were never issued or numbered.
BEGIN;

ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check;
ALTER TABLE invoices ADD CONSTRAINT invoices_status_check
    CHECK (status IN ('draft', 'unpaid', 'partial', 'paid', 'voided'));

COMMIT;
