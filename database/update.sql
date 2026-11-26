-- Latest-Only Delta: show_in_epg on m3u_groups
ALTER TABLE `m3u_groups`
    ADD COLUMN IF NOT EXISTS `show_in_epg` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_enabled`;

UPDATE `m3u_groups`
SET `show_in_epg` = 0
WHERE `category` IN ('Film', 'TV Series');

ALTER TABLE `channels`
    MODIFY COLUMN `epg_match_type` ENUM('none', 'auto', 'close_match', 'manual') NOT NULL DEFAULT 'none';
