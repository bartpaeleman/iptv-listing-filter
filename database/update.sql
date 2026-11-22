-- IPTV & EPG Manager - Latest-Only Delta Migration
-- Bevat uitsluitend de SQL-statements van de laatste schema-wijziging.

SET NAMES utf8mb4;

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
