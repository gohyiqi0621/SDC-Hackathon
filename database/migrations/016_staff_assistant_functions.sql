-- ============================================================================
-- 016_staff_assistant_functions.sql
--
-- Staff assistant: which functions it may use, chosen with checkboxes on
-- AI Chatbot > Staff > Setting & Integration (replaces free-text system
-- instructions for the staff assistant).
--
--   functions  comma-separated: check_email, check_invoice, check_claims, check_orders
--
-- Safe to rerun. Requires 015 (staff_ai_assistant_settings).
-- ============================================================================

BEGIN;

ALTER TABLE staff_ai_assistant_settings
    ADD COLUMN IF NOT EXISTS functions VARCHAR(200) NOT NULL DEFAULT 'check_email,check_invoice,check_claims,check_orders';

COMMIT;
