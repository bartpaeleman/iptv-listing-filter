-- IPTV & EPG Manager - Latest-Only Delta Migration
-- Centrale Emoji- & Tag-database: emoji_rules

SET NAMES utf8mb4;

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
