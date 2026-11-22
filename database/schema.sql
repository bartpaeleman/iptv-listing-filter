-- IPTV & EPG Manager Database Schema
-- Compatible with MariaDB 10.3+ / MySQL 8.0+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NULL,
    `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `m3u_groups` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `original_name` VARCHAR(255) NOT NULL,
    `custom_name` VARCHAR(255) NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_m3u_groups_original_name` (`original_name`),
    KEY `idx_m3u_groups_enabled_sort` (`is_enabled`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `channels` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `stream_hash` CHAR(64) NOT NULL,
    `tvg_id` VARCHAR(255) NULL,
    `tvg_name` VARCHAR(255) NOT NULL DEFAULT '',
    `tvg_logo` TEXT NULL,
    `group_id` INT UNSIGNED NOT NULL,
    `stream_url` TEXT NOT NULL,
    `custom_name` VARCHAR(255) NULL,
    `channel_number` INT NULL,
    `epg_channel_id` VARCHAR(255) NULL,
    `epg_match_type` ENUM('none', 'auto', 'manual') NOT NULL DEFAULT 'none',
    `country_tag_id` INT UNSIGNED NULL,
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `last_seen_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_channels_stream_hash` (`stream_hash`),
    KEY `idx_channels_group` (`group_id`),
    KEY `idx_channels_epg_id` (`epg_channel_id`),
    KEY `idx_channels_country_tag` (`country_tag_id`),
    KEY `idx_channels_enabled_sort` (`is_enabled`, `sort_order`, `channel_number`),
    CONSTRAINT `fk_channels_group`
        FOREIGN KEY (`group_id`) REFERENCES `m3u_groups` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `epg_channels` (
    `epg_id` VARCHAR(255) NOT NULL,
    `display_name` VARCHAR(255) NOT NULL DEFAULT '',
    `icon_url` TEXT NULL,
    PRIMARY KEY (`epg_id`),
    KEY `idx_epg_channels_display_name` (`display_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `epg_programmes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `epg_id` VARCHAR(255) NOT NULL,
    `start_utc` DATETIME NOT NULL,
    `stop_utc` DATETIME NOT NULL,
    `title` VARCHAR(500) NOT NULL DEFAULT '',
    `description` TEXT NULL,
    `category` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_epg_programmes` (`epg_id`, `start_utc`),
    KEY `idx_epg_prog_start` (`start_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sync_type` VARCHAR(32) NOT NULL,
    `status` ENUM('success', 'error', 'skipped') NOT NULL,
    `message` VARCHAR(500) NOT NULL DEFAULT '',
    `stats_json` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sync_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `country_tags` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tag_pattern` VARCHAR(50) NOT NULL,
    `country_code` CHAR(2) NOT NULL,
    `country_name` VARCHAR(100) NOT NULL,
    `flag_emoji` VARCHAR(16) NOT NULL,
    `auto_group` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_country_tags_pattern` (`tag_pattern`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `emoji_rules` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `match_type` ENUM('country', 'keyword') NOT NULL,
    `pattern` VARCHAR(100) NOT NULL,
    `emoji` VARCHAR(32) NOT NULL,
    `label` VARCHAR(150) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_emoji_rules_type_pattern` (`match_type`, `pattern`),
    KEY `idx_emoji_rules_active_type` (`is_active`, `match_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `country_tags` (`tag_pattern`, `country_code`, `country_name`, `flag_emoji`, `sort_order`) VALUES
    ('[BE]', 'BE', 'België', '🇧🇪', 1),
    ('[NL]', 'NL', 'Nederland', '🇳🇱', 2),
    ('[EN]', 'GB', 'Verenigd Koninkrijk', '🇬🇧', 3),
    ('[UK]', 'GB', 'Verenigd Koninkrijk', '🇬🇧', 4),
    ('[US]', 'US', 'Verenigde Staten', '🇺🇸', 5),
    ('[DE]', 'DE', 'Duitsland', '🇩🇪', 6),
    ('[FR]', 'FR', 'Frankrijk', '🇫🇷', 7),
    ('[ES]', 'ES', 'Spanje', '🇪🇸', 8),
    ('[IT]', 'IT', 'Italië', '🇮🇹', 9);

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `is_encrypted`) VALUES
    ('source_mode', 'xtream', 0),
    ('xtream_host', '', 0),
    ('xtream_username', '', 1),
    ('xtream_password', '', 1),
    ('m3u_source_url', '', 1),
    ('epg_source_url', '', 1),
    ('sync_interval_hours', '72', 0),
    ('sync_preferred_hour', '3', 0),
    ('cache_ttl_minutes', '360', 0),
    ('last_m3u_sync', '', 0),
    ('last_epg_sync', '', 0),
    ('output_token', '', 0),
    ('enforce_lan_ip', '1', 0);

SET FOREIGN_KEY_CHECKS = 1;
