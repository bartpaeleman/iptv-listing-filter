-- IPTV & EPG Manager - Latest-Only Delta Migration
-- Custom groepsnaam ondersteuning op m3u_groups

SET NAMES utf8mb4;

ALTER TABLE `m3u_groups`
    ADD COLUMN IF NOT EXISTS `custom_name` VARCHAR(255) NULL DEFAULT NULL AFTER `original_name`;
