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
