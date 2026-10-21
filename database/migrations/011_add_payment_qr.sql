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
