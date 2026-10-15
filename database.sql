-- database.sql - schema + demo data for the SDC frame.
-- Import with:  mysql -u root < database.sql
-- or paste into phpMyAdmin > SQL.

CREATE DATABASE IF NOT EXISTS `sdc_app`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `sdc_app`;

-- Drop children before parents (foreign keys).
DROP TABLE IF EXISTS `notification_reads`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `chat_messages`;
DROP TABLE IF EXISTS `chat_sessions`;
DROP TABLE IF EXISTS `api_keys`;
DROP TABLE IF EXISTS `ai_logs`;
DROP TABLE IF EXISTS `items`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `schema_migrations`;

CREATE TABLE `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(100)  NOT NULL,
    `email`         VARCHAR(190)  NOT NULL,
    `password_hash` VARCHAR(255)  NULL,          -- NULL for Google-only accounts
    `avatar_path`   VARCHAR(255)  NULL,          -- relative to public/, e.g. uploads/avatars/u1_x.png
    `google_id`     VARCHAR(64)   NULL,          -- Google "sub" claim, once linked
    `role`          ENUM('admin','user')      NOT NULL DEFAULT 'user',
    `status`        ENUM('active','disabled') NOT NULL DEFAULT 'active',
    `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_users_email` (`email`),
    UNIQUE KEY `uniq_users_google` (`google_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `items` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NOT NULL,
    `title`       VARCHAR(190) NOT NULL,
    `description` TEXT         NULL,
    `status`      ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_items_user`   (`user_id`),
    KEY `idx_items_status` (`status`),
    CONSTRAINT `fk_items_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ai_logs` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED NULL,
    `input_text`  TEXT         NOT NULL,
    `output_text` TEXT         NULL,
    `status`      ENUM('success','error') NOT NULL DEFAULT 'success',
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ai_logs_user` (`user_id`),
    CONSTRAINT `fk_ai_logs_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API keys (the admin "Key" page). The secret is stored AES-256-GCM encrypted
-- with APP_KEY; the plaintext is never written and never shown again. `last4`
-- exists only so the UI can render the sk-................XXXX mask.
CREATE TABLE `api_keys` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `label`            VARCHAR(100) NOT NULL,
    `secret_encrypted` TEXT         NOT NULL,   -- base64(iv || tag || ciphertext)
    `last4`            CHAR(4)      NOT NULL,
    `created_by`       INT UNSIGNED NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_api_keys_creator` (`created_by`),
    CONSTRAINT `fk_api_keys_user` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chat sessions: one per user conversation thread.
CREATE TABLE `chat_sessions` (
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

-- Individual chat messages. `role` is who spoke; `image_name` records that a
-- photo was attached (prototype: preview only, the file is not persisted).
CREATE TABLE `chat_messages` (
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

-- -------------------------------------------------------------- settings ---
-- Admin-managed config values (API keys etc). Secrets are AES-256-GCM sealed;
-- non-secrets (the OneSignal App ID) are stored in the clear so the browser can
-- read them. This replaces editing .env for keys that change at runtime.
CREATE TABLE `settings` (
    `key`             VARCHAR(60)  NOT NULL,
    `value_encrypted` TEXT         NULL,          -- base64(iv || tag || ciphertext), or NULL
    `value_plain`     TEXT         NULL,          -- non-secret values only
    `is_secret`       TINYINT(1)   NOT NULL DEFAULT 0,
    `last4`           CHAR(4)      NULL,
    `updated_by`      INT UNSIGNED NULL,
    `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`),
    CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- notifications ---
-- Admin-sent pushes. Doubles as the audit log (who sent what, to whom, when).
-- `status` covers instant sends and OneSignal-held schedules alike.
CREATE TABLE `notifications` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`          VARCHAR(190) NOT NULL,
    `body`           TEXT         NOT NULL,
    `url`            VARCHAR(500) NULL,
    `audience`       ENUM('all','user','users','role') NOT NULL DEFAULT 'all',
    `audience_value` VARCHAR(190) NULL,          -- user id, comma-separated ids, or role name
    `audience_label` VARCHAR(255) NULL,          -- recipient names frozen at send time
    `scheduled_at`   DATETIME     NULL,          -- app wall clock; NULL = send immediately
    `status`         ENUM('queued','sent','scheduled','failed') NOT NULL DEFAULT 'queued',
    `error`          TEXT         NULL,
    `onesignal_id`   VARCHAR(64)  NULL,
    `recipients`     INT UNSIGNED NULL,          -- OneSignal's reported recipient count
    `created_by`     INT UNSIGNED NULL,
    `created_by_name` VARCHAR(120) NULL,         -- sender name frozen at send time
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_status` (`status`),
    KEY `idx_notifications_created` (`created_at`),
    KEY `idx_notifications_scheduled` (`scheduled_at`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user read marks, so unread counts need no fan-out rows at send time.
CREATE TABLE `notification_reads` (
    `user_id`         INT UNSIGNED NOT NULL,
    `notification_id` INT UNSIGNED NOT NULL,
    `read_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `notification_id`),
    CONSTRAINT `fk_nreads_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nreads_notification` FOREIGN KEY (`notification_id`)
        REFERENCES `notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------ schema_migrations ---
-- Records which migrations/*.sql files have been run against this database, so
-- no one re-runs one twice. This file is the single source of truth for the
-- schema: it creates every table, and it already contains the effect of every
-- migration shipped so far, so no migration file needs to run on a fresh
-- import. It starts empty; add a row when you apply a new migration file.
CREATE TABLE `schema_migrations` (
    `filename`   VARCHAR(190) NOT NULL,
    `applied_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ demo ---
-- Passwords below are bcrypt hashes of "admin123" and "user123".
-- Change them before any real deployment.
INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `status`) VALUES
(1, 'Admin User', 'admin@sdc.local', '$2y$10$uWZ.lwqJwbpElDncu1GjW.eZw1bbbW8bJtI.yoB8wfLZ7FyJbWMjG', 'admin', 'active'),
(2, 'Demo User',  'user@sdc.local',  '$2y$10$iy4MhC09jt9829zgCxwJjeVUpDRQ01gqpICHpKXuhtF3fVBjhMyke', 'user',  'active');

-- Items for both users on purpose: a fresh import should show the role scoping
-- straight away (admin sees 11 rows, the demo user sees only their own 6).
INSERT INTO `items` (`user_id`, `title`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Sample item one',   'First placeholder record for the demo table.',   'active',   DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY)),
(1, 'Sample item two',   'Second placeholder record for the demo table.',  'active',   DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY)),
(1, 'Sample item three', 'Third placeholder record for the demo table.',   'draft',    DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1, 'Sample item four',  'Fourth placeholder record for the demo table.',  'draft',    DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1, 'Sample item five',  'Fifth placeholder record for the demo table.',   'archived', NOW(),                           NOW()),
(2, 'My draft idea',     'Owned by the demo user, still a draft.',         'draft',    DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY)),
(2, 'My active task',    'Owned by the demo user and in progress.',        'active',   DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY)),
(2, 'My second task',    'Another item the demo user can edit and delete.','active',   DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),
(2, 'Old experiment',    'Archived item owned by the demo user.',          'archived', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2, 'Notes to self',     'Draft notes owned by the demo user.',            'draft',    DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),
(2, 'Something fresh',   'Created today by the demo user.',                'active',   NOW(),                           NOW());

-- Spread over the last week so the dashboard chart has a real shape on a
-- fresh import. Replace these with genuine logs once the AI panel is wired up.
INSERT INTO `ai_logs` (`user_id`, `input_text`, `output_text`, `status`, `created_at`) VALUES
(1, 'Summarise the project brief.',        'Sample output from the dummy service.', 'success', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(1, 'List three risks for the prototype.', 'Sample output from the dummy service.', 'success', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(1, 'Draft a one-line pitch.',             'Sample output from the dummy service.', 'success', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(2, 'Suggest a colour palette.',           'Sample output from the dummy service.', 'success', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(2, 'Turn this paragraph into bullet points.', '',                                   'error',   DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1, 'Explain the SDG we picked.',          'Sample output from the dummy service.', 'success', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(2, 'Rewrite the abstract shorter.',       'Sample output from the dummy service.', 'success', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1, 'Generate five demo questions.',       'Sample output from the dummy service.', 'success', NOW());

-- Seed the public OneSignal App ID so the bell and push switch render on a
-- fresh import. The REST API key is a SECRET and is NOT seeded on purpose:
-- sign in as an admin, open Key, and paste it once (it is sealed with
-- AES-256-GCM before it ever touches the database).
INSERT INTO `settings` (`key`, `value_plain`, `is_secret`) VALUES
('onesignal_app_id', '52e6e951-7e36-49f0-837c-4f3c2d29510e', 0);

