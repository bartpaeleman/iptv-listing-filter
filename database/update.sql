-- IPTV & EPG Manager - Latest-Only Delta Migration
-- Deduplicatie + Unique Constraints op epg_programmes

SET NAMES utf8mb4;

DELETE p1 FROM `epg_programmes` p1
INNER JOIN `epg_programmes` p2
    ON p1.epg_id = p2.epg_id AND p1.start_utc = p2.start_utc AND p1.id > p2.id;

ALTER TABLE `epg_programmes`
    ADD UNIQUE KEY `uq_epg_programmes` (`epg_id`, `start_utc`);
