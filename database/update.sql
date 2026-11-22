-- IPTV & EPG Manager - Latest-Only Delta Migration
-- Bevat uitsluitend de SQL-statements van de laatste schema-wijziging.

SET NAMES utf8mb4;

ALTER TABLE `channels` MODIFY COLUMN `tvg_logo` MEDIUMTEXT NULL;
ALTER TABLE `channels` MODIFY COLUMN `stream_url` TEXT NOT NULL;
ALTER TABLE `channels`
    MODIFY COLUMN `tvg_id` VARCHAR(255) NULL,
    MODIFY COLUMN `tvg_name` VARCHAR(255) NOT NULL DEFAULT '',
    MODIFY COLUMN `custom_name` VARCHAR(255) NULL,
    MODIFY COLUMN `epg_channel_id` VARCHAR(255) NULL;
ALTER TABLE `epg_channels`
    MODIFY COLUMN `icon_url` MEDIUMTEXT NULL,
    MODIFY COLUMN `display_name` VARCHAR(255) NOT NULL DEFAULT '';
