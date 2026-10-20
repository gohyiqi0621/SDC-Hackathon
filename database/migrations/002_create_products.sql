-- ============================================================================
-- 002_create_products.sql
--
-- Products and services. Every row belongs to one user and one of that same
-- user's categories. `characteristics` is plain free text (no JSON, no extra
-- table): users can type sentences, specs or bullet points.
--
-- Safe to rerun: every statement uses IF NOT EXISTS. Never touches `users`.
-- Requires 001_create_product_categories.sql.
-- ============================================================================

BEGIN;

CREATE TABLE IF NOT EXISTS products (
    id              BIGINT        GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id         BIGINT        NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    category_id     BIGINT        NOT NULL,
    name            VARCHAR(200)  NOT NULL,
    sku             VARCHAR(100)  NULL,
    description     TEXT,
    characteristics TEXT,
    image_url       TEXT,
    price           NUMERIC(12,2) NOT NULL,
    promotion_price NUMERIC(12,2) NULL,
    currency        CHAR(3)       NOT NULL DEFAULT 'MYR',
    status          VARCHAR(20)   NOT NULL DEFAULT 'active',
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ   NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT products_price_check
        CHECK (price >= 0),
    CONSTRAINT products_promotion_price_check
        CHECK (promotion_price IS NULL OR (promotion_price >= 0 AND promotion_price <= price)),
    CONSTRAINT products_status_check
        CHECK (status IN ('active', 'inactive')),
    CONSTRAINT products_currency_check
        CHECK (currency ~ '^[A-Z]{3}$'),

    -- SKU is unique per user when given; NULLs never conflict with each other,
    -- so any number of products can have no SKU.
    CONSTRAINT products_user_sku_uniq UNIQUE (user_id, sku),

    -- The category must belong to the same user as the product.
    -- NO ACTION blocks deleting a category that still has products (like
    -- RESTRICT), but is checked at the end of the statement, so deleting a
    -- user can still cascade through both their products and categories.
    CONSTRAINT products_category_owner_fk
        FOREIGN KEY (user_id, category_id)
        REFERENCES product_categories (user_id, id)
        ON DELETE NO ACTION
);

-- Listing, filtering and sorting are always scoped to one user.
CREATE INDEX IF NOT EXISTS products_user_category_idx ON products (user_id, category_id);
CREATE INDEX IF NOT EXISTS products_user_name_idx     ON products (user_id, LOWER(name));
CREATE INDEX IF NOT EXISTS products_user_status_idx   ON products (user_id, status);
CREATE INDEX IF NOT EXISTS products_user_created_idx  ON products (user_id, created_at DESC);

COMMIT;
