-- Latest-Only Delta: custom_group_channels ON DELETE SET NULL + snapshot columns
ALTER TABLE `custom_group_channels`
    MODIFY COLUMN `channel_id` INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS `channel_tvg_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `channel_id`,
    ADD COLUMN IF NOT EXISTS `channel_tvg_id` VARCHAR(255) NULL AFTER `channel_tvg_name`,
    ADD COLUMN IF NOT EXISTS `source_group_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `channel_tvg_id`,
    ADD COLUMN IF NOT EXISTS `saved_epg_channel_id` VARCHAR(255) NULL AFTER `source_group_name`,
    ADD COLUMN IF NOT EXISTS `saved_custom_name` VARCHAR(255) NULL AFTER `saved_epg_channel_id`;
