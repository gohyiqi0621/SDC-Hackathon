-- Bring pre-existing invoice_items tables up to the fields used by the
-- invoice upload form and printable purchase invoice. This is additive and
-- keeps all existing rows. Older rows without a quantity remain NULL rather
-- than being assigned an invented quantity; new uploads save the entered value.
BEGIN;

ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS sku VARCHAR(100) NULL;
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS quantity NUMERIC(12,3) NULL;

-- Some earlier local schemas called this field `qty`. Preserve those values
-- when present without requiring that legacy column to exist.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND table_name = 'invoice_items'
           AND column_name = 'qty'
    ) THEN
        EXECUTE 'UPDATE invoice_items SET quantity = NULLIF(BTRIM(qty::text), '''')::numeric WHERE quantity IS NULL AND BTRIM(qty::text) ~ ''^[0-9]+([.][0-9]+)?$''';
    END IF;
END $$;

COMMIT;
