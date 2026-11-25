-- IPTV & EPG Manager - Latest-Only Delta Migration
-- Custom Groepen Snapshot & Relink Engine (ON DELETE SET NULL)

SET NAMES utf8mb4;

ALTER TABLE `custom_group_channels`
    DROP FOREIGN KEY IF EXISTS `fk_cgc_channel`;

ALTER TABLE `custom_group_channels`
    MODIFY COLUMN `channel_id` INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `channel_tvg_name` VARCHAR(255) NULL DEFAULT NULL AFTER `channel_id`,
    ADD COLUMN IF NOT EXISTS `channel_tvg_id` VARCHAR(255) NULL DEFAULT NULL AFTER `channel_tvg_name`,
    ADD COLUMN IF NOT EXISTS `source_group_name` VARCHAR(255) NULL DEFAULT NULL AFTER `channel_tvg_id`,
    ADD COLUMN IF NOT EXISTS `saved_epg_channel_id` VARCHAR(255) NULL DEFAULT NULL AFTER `source_group_name`,
    ADD COLUMN IF NOT EXISTS `saved_custom_name` VARCHAR(255) NULL DEFAULT NULL AFTER `saved_epg_channel_id`;

ALTER TABLE `custom_group_channels`
    ADD CONSTRAINT `fk_cgc_channel`
        FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`)
        ON DELETE SET NULL;
