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
