-- IPTV & EPG Manager - Latest-Only Delta Migration
-- Custom Groepen & Kanaal-koppelingen

SET NAMES utf8mb4;

ALTER TABLE `m3u_groups`
    ADD COLUMN IF NOT EXISTS `is_custom` TINYINT(1) NOT NULL DEFAULT 0 AFTER `category_locked`,
    ADD COLUMN IF NOT EXISTS `source_group_id` INT UNSIGNED NULL DEFAULT NULL AFTER `is_custom`,
    ADD COLUMN IF NOT EXISTS `sync_diff_json` MEDIUMTEXT NULL AFTER `source_group_id`,
    ADD INDEX IF NOT EXISTS `idx_groups_is_custom` (`is_custom`);

CREATE TABLE IF NOT EXISTS `custom_group_channels` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `custom_group_id` INT UNSIGNED NOT NULL,
    `channel_id` INT UNSIGNED NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_ignored` TINYINT(1) NOT NULL DEFAULT 0,
    `added_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_custom_group_channel` (`custom_group_id`, `channel_id`),
    KEY `idx_cgc_group_sort` (`custom_group_id`, `sort_order`),
    CONSTRAINT `fk_cgc_custom_group`
        FOREIGN KEY (`custom_group_id`) REFERENCES `m3u_groups` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_cgc_channel`
        FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
