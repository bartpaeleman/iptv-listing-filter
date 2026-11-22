-- IPTV & EPG Manager - Latest-Only Delta Migration
-- Zelfherstellende tabellen: epg_sources, country_tags + channels.country_tag_id

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

ALTER TABLE `channels`
    ADD COLUMN IF NOT EXISTS `country_tag_id` INT UNSIGNED NULL AFTER `epg_match_type`;
