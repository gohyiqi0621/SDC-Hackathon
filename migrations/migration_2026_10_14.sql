-- migration_2026_10_14.sql - upgrade to the chat/key/avatar schema.
--
-- Run once per database. Portability note: MySQL 8 (Railway) does NOT support
-- `ADD COLUMN IF NOT EXISTS` / `ADD KEY IF NOT EXISTS` - that is MariaDB-only
-- syntax and errors out on a MySQL 8 server. Run this file only on a database
-- that has not had it applied yet; the `schema_migrations` table records it.
USE `sdc_app`;

-- users: avatar, google link, nullable password (Google-only accounts)
ALTER TABLE `users`
    MODIFY `password_hash` VARCHAR(255) NULL,
    ADD COLUMN `avatar_path` VARCHAR(255) NULL AFTER `password_hash`,
    ADD COLUMN `google_id`   VARCHAR(64)  NULL AFTER `avatar_path`,
    ADD UNIQUE KEY `uniq_users_google` (`google_id`);

-- api_keys
CREATE TABLE IF NOT EXISTS `api_keys` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `label`            VARCHAR(100) NOT NULL,
    `secret_encrypted` TEXT         NOT NULL,
    `last4`            CHAR(4)      NOT NULL,
    `created_by`       INT UNSIGNED NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_api_keys_creator` (`created_by`),
    CONSTRAINT `fk_api_keys_user` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- chat_sessions
CREATE TABLE IF NOT EXISTS `chat_sessions` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `title`      VARCHAR(190) NOT NULL DEFAULT 'New chat',
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_chat_sessions_user` (`user_id`),
    CONSTRAINT `fk_chat_sessions_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- chat_messages
CREATE TABLE IF NOT EXISTS `chat_messages` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`  INT UNSIGNED NOT NULL,
    `role`        ENUM('user','assistant') NOT NULL DEFAULT 'user',
    `body`        TEXT         NOT NULL,
    `image_name`  VARCHAR(190) NULL,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_chat_messages_session` (`session_id`),
    CONSTRAINT `fk_chat_messages_session` FOREIGN KEY (`session_id`)
        REFERENCES `chat_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
