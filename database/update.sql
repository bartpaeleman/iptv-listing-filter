-- IPTV & EPG Manager - Latest-Only Delta Migration
-- Groepssortering (import_order + group_sort_mode)

SET NAMES utf8mb4;

ALTER TABLE `m3u_groups`
    ADD COLUMN IF NOT EXISTS `import_order` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `sort_order`;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `is_encrypted`)
VALUES ('group_sort_mode', 'import', 0);
