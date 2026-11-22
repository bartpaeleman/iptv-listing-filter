-- IPTV & EPG Manager - Latest-Only Delta Migration
-- Xtream API Content Types (live / movie / series)

SET NAMES utf8mb4;

ALTER TABLE `m3u_groups`
    ADD COLUMN IF NOT EXISTS `content_type` ENUM('live','movie','series') NOT NULL DEFAULT 'live' AFTER `import_order`,
    ADD INDEX IF NOT EXISTS `idx_groups_content_type` (`content_type`);

ALTER TABLE `channels`
    ADD COLUMN IF NOT EXISTS `content_type` ENUM('live','movie','series') NOT NULL DEFAULT 'live' AFTER `group_id`,
    ADD INDEX IF NOT EXISTS `idx_channels_content_type` (`content_type`);
