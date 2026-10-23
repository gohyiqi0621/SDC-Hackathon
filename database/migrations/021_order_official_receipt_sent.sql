-- ============================================================================
-- 021_order_official_receipt_sent.sql
--
-- When the official receipt PDF was last sent to the customer on Telegram
-- (after the payment is verified, again on "Mark shipped" if it was never
-- sent, or with "Send receipt" on the Orders page). OrderService also adds
-- this column by itself, so this file only makes it explicit.
--
-- Safe to rerun. Requires 009 (orders).
-- ============================================================================

BEGIN;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS official_receipt_sent_at TIMESTAMPTZ;

COMMIT;
