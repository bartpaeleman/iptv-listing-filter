-- IPTV & EPG Manager - Rolling 2-Updates Database Migration
-- Idempotent statements only (safe for existing QNAP installations)

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `epg_sources` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `url` TEXT NOT NULL,
    `priority` INT NOT NULL DEFAULT 10,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `last_sync_at` DATETIME NULL,
    `last_status` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    KEY `idx_epg_sources_enabled_prio` (`is_enabled`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `is_encrypted`) VALUES
    ('source_mode', 'xtream', 0),
    ('xtream_host', '', 0),
    ('xtream_username', '', 1),
    ('xtream_password', '', 1),
    ('cache_ttl_minutes', '360', 0);

ALTER TABLE `channels`
    MODIFY COLUMN `tvg_id` VARCHAR(255) NULL,
    MODIFY COLUMN `tvg_name` VARCHAR(255) NOT NULL DEFAULT '',
    MODIFY COLUMN `tvg_logo` TEXT NULL,
    MODIFY COLUMN `custom_name` VARCHAR(255) NULL,
    MODIFY COLUMN `epg_channel_id` VARCHAR(255) NULL;

ALTER TABLE `m3u_groups`
    MODIFY COLUMN `original_name` VARCHAR(255) NOT NULL,
    MODIFY COLUMN `custom_name` VARCHAR(255) NULL;

ALTER TABLE `epg_channels`
    MODIFY COLUMN `epg_id` VARCHAR(255) NOT NULL,
    MODIFY COLUMN `display_name` VARCHAR(255) NOT NULL DEFAULT '',
    MODIFY COLUMN `icon_url` TEXT NULL;
