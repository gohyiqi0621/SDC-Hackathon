-- settings.first4 — show the first four characters of a saved secret in the
-- Key page mask (first4 ············ last4), not only the last four.
-- Fresh imports of database.sql already have this column; this file is for
-- databases created before 2026-10-16.
--
-- Rows saved before this migration keep first4 = NULL; the UI renders a
-- placeholder run of dots for them. Re-save a key once to pick up its prefix.

ALTER TABLE `settings`
    ADD COLUMN `first4` CHAR(4) NULL AFTER `is_secret`;

INSERT INTO schema_migrations (filename)
VALUES ('gohyiqi0621/migration_20261016_190500.sql');
