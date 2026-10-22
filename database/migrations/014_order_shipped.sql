-- ============================================================================
-- 014_order_shipped.sql
--
-- Orders: after staff approve the payment the order is "paid" (shown as
-- "Payment successful"); staff then mark it "shipped" when it is sent out
-- (the customer is told on Telegram). shipped_at records when.
--
-- Safe to rerun. Requires 009 (orders).
-- ============================================================================

BEGIN;

ALTER TABLE orders ADD COLUMN IF NOT EXISTS shipped_at TIMESTAMPTZ;

ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check;
ALTER TABLE orders
    ADD CONSTRAINT orders_status_check
        CHECK (status IN ('pending_payment', 'payment_submitted', 'paid', 'shipped', 'payment_rejected', 'cancelled'));

COMMIT;
