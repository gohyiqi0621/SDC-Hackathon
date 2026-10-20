-- ============================================================================
-- 001_create_product_categories.sql
--
-- Product / service categories. Each user owns their own categories, so a
-- name only has to be unique per user, not across the whole app.
--
-- Safe to rerun: every statement uses IF NOT EXISTS. Never touches `users`.
-- ============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS product_categories (
    id          BIGINT       GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    -- Same type as users.id (BIGSERIAL = BIGINT). Deleting a user removes their
    -- categories, matching how password_resets already behaves.
    user_id     BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    name        VARCHAR(100) NOT NULL,
    description TEXT,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- A category name is unique per user.
    CONSTRAINT product_categories_user_name_uniq UNIQUE (user_id, name),

    -- Target of the composite foreign key in products, which guarantees a
    -- product can only use a category owned by the same user.
    CONSTRAINT product_categories_user_id_id_uniq UNIQUE (user_id, id)
);

-- (user_id, name) and (user_id, id) above already index every lookup by user.

COMMIT;
