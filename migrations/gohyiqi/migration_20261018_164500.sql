-- password_resets - one-time codes for "Forgot password".
--
-- The code itself is NEVER stored: otp_hash holds a password_hash() of the
-- 6-digit code, exactly like a user password, so a database dump cannot be used
-- to reset anyone's account. `attempts` caps brute force per issued code and
-- `expires_at` caps its lifetime (10 minutes by default).
--
-- Run on every existing database (local XAMPP + Railway), then record it:
--   INSERT INTO schema_migrations (filename) VALUES ('gohyiqi/migration_20261018_164500.sql');

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED     NOT NULL,
    `email`      VARCHAR(190)     NOT NULL,   -- address the code was sent to (frozen at request time)
    `otp_hash`   VARCHAR(255)     NOT NULL,   -- password_hash() of the 6-digit code
    `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` DATETIME         NOT NULL,   -- app wall clock, like every other datetime here
    `used_at`    DATETIME         NULL,       -- set when the code was redeemed
    `created_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pwreset_user` (`user_id`),
    KEY `idx_pwreset_created` (`created_at`),
    CONSTRAINT `fk_pwreset_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
