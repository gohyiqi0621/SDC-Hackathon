-- migration_2026_10_14_notifications.sql - multi-recipient pushes + stored names.
--
-- Run once per database (recorded in `schema_migrations`). Portability note:
-- the `IF NOT EXISTS` clauses on ADD COLUMN / ADD KEY are MariaDB-only and
-- error on MySQL 8 (Railway), so they are omitted here.
--
-- Two things change:
--   * `audience` gains 'users', holding a comma-separated id list in
--     audience_value, so one row can address several people (the composer's
--     "Selected users" mode). The old 'user'/'role' values stay because the
--     bell's audience filter and historical rows still use them.
--   * `audience_label` and `created_by_name` freeze the human names at send
--     time. created_by is ON DELETE SET NULL, so without a stored name the
--     audit log would lose the sender the moment an account was removed.
USE `sdc_app`;

ALTER TABLE `notifications`
    MODIFY `audience` ENUM('all','user','users','role') NOT NULL DEFAULT 'all',
    ADD COLUMN `audience_label`  VARCHAR(255) NULL AFTER `audience_value`,
    ADD COLUMN `created_by_name` VARCHAR(120) NULL AFTER `created_by`,
    ADD KEY `idx_notifications_scheduled` (`scheduled_at`);

-- Backfill the names for rows that predate the columns.
UPDATE `notifications` n
  LEFT JOIN `users` u ON u.id = n.created_by
   SET n.created_by_name = u.name
 WHERE n.created_by_name IS NULL;

UPDATE `notifications` n
  LEFT JOIN `users` u ON u.id = n.audience_value
   SET n.audience_label = CASE n.audience
        WHEN 'all'   THEN 'Everyone'
        WHEN 'role'  THEN CONCAT(UPPER(LEFT(n.audience_value, 1)), SUBSTRING(n.audience_value, 2), 's')
        WHEN 'user'  THEN COALESCE(u.name, CONCAT('User #', n.audience_value))
        ELSE n.audience_label
     END
 WHERE n.audience_label IS NULL;

-- `scheduled_at` used to be written as UTC while every other datetime column in
-- this schema holds the app's wall clock (MySQL runs time_zone = SYSTEM, and
-- NOW() matches PHP's clock). That mismatch made a push scheduled for 23:02
-- local render as 15:02. Move the old rows onto the one convention. The offset
-- is derived from the server rather than hardcoded, assuming a whole-hour one.
--
-- NOT idempotent - running it twice would shift the times twice. It is only
-- correct on a database that still holds the old UTC values.
UPDATE `notifications`
   SET `scheduled_at` = TIMESTAMPADD(HOUR, TIMESTAMPDIFF(HOUR, UTC_TIMESTAMP(), NOW()), `scheduled_at`)
 WHERE `scheduled_at` IS NOT NULL;
