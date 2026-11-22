-- IPTV & EPG Manager - Latest-Only Delta Migration
-- Land-Tags & Vlaggen Beheer

SET NAMES utf8mb4;

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

ALTER TABLE `channels`
    ADD COLUMN IF NOT EXISTS `country_tag_id` INT UNSIGNED NULL AFTER `epg_match_type`;
