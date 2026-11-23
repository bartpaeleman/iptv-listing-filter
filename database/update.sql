-- IPTV & EPG Manager - Latest-Only Delta Migration
-- Groep-categorieën (Live TV, Sport, Animatie, TV Series, Film) + eigen regels

SET NAMES utf8mb4;

ALTER TABLE `m3u_groups`
    ADD COLUMN IF NOT EXISTS `category` VARCHAR(32) NOT NULL DEFAULT 'Live TV' AFTER `content_type`,
    ADD COLUMN IF NOT EXISTS `category_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `category`,
    ADD INDEX IF NOT EXISTS `idx_groups_category` (`category`);

CREATE TABLE IF NOT EXISTS `group_category_rules` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pattern` VARCHAR(100) NOT NULL,
    `category` VARCHAR(32) NOT NULL DEFAULT 'Live TV',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_group_cat_pattern` (`pattern`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
