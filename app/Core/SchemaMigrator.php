<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\SettingsRepository;
use PDO;
use RuntimeException;

final class SchemaMigrator
{
    private PDO $pdo;
    private SettingsRepository $settings;

    public function __construct(?PDO $pdo = null, ?SettingsRepository $settings = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
        $this->settings = $settings ?? new SettingsRepository($this->pdo);
    }

    public static function ensureSchemaUpToDate(PDO $pdo, bool $force = false): void
    {
        if (!$force) {
            $tblCount = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name IN ('epg_sources', 'country_tags', 'emoji_rules')"
            )->fetchColumn();
            $flag = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_integrity_v7' LIMIT 1")->fetchColumn();
            if ($tblCount === 3 && (string) $flag === '1') {
                return;
            }
        }
        $schemaFile = dirname(__DIR__, 2) . '/database/schema.sql';
        if (is_file($schemaFile) && is_readable($schemaFile)) {
            $sql = (string) file_get_contents($schemaFile);
            foreach (self::splitSqlStatements($sql) as $stmtSql) {
                if (preg_match('/^(CREATE TABLE IF NOT EXISTS|INSERT IGNORE INTO)/i', $stmtSql)) {
                    try {
                        $pdo->exec($stmtSql);
                    } catch (\Throwable) {
                        // Ignore non-fatal seed/table warnings
                    }
                }
            }
        }

        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME, LOWER(DATA_TYPE) AS data_type
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND ((TABLE_NAME = 'channels' AND COLUMN_NAME IN ('tvg_logo', 'country_tag_id'))
                 OR (TABLE_NAME = 'epg_channels' AND COLUMN_NAME = 'icon_url'))"
        );
        $stmt->execute();

        $needsMediumText = false;
        $hasCountryTagId = false;
        foreach ($stmt->fetchAll() as $row) {
            if ($row['COLUMN_NAME'] === 'country_tag_id') {
                $hasCountryTagId = true;
            } elseif (!in_array($row['data_type'], ['mediumtext', 'longtext'], true)) {
                $needsMediumText = true;
            }
        }

        if (!$hasCountryTagId) {
            try {
                $pdo->exec('ALTER TABLE `channels` ADD COLUMN `country_tag_id` INT UNSIGNED NULL AFTER `epg_match_type`, ADD KEY `idx_channels_country_tag` (`country_tag_id`)');
            } catch (\Throwable) {
                // Ignore if already exists
            }
        }

        if ($needsMediumText) {
            $statements = [
                'ALTER TABLE `channels` MODIFY COLUMN `tvg_logo` MEDIUMTEXT NULL',
                'ALTER TABLE `channels` MODIFY COLUMN `stream_url` TEXT NOT NULL',
                'ALTER TABLE `channels` MODIFY COLUMN `tvg_id` VARCHAR(255) NULL, MODIFY COLUMN `tvg_name` VARCHAR(255) NOT NULL DEFAULT \'\', MODIFY COLUMN `custom_name` VARCHAR(255) NULL, MODIFY COLUMN `epg_channel_id` VARCHAR(255) NULL',
                'ALTER TABLE `epg_channels` MODIFY COLUMN `icon_url` MEDIUMTEXT NULL, MODIFY COLUMN `display_name` VARCHAR(255) NOT NULL DEFAULT \'\'',
            ];
            foreach ($statements as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (\Throwable $e) {
                    throw new RuntimeException(
                        "Database migratie mislukt ({$e->getMessage()}). Voer handmatig uit als DB-admin: {$sql}",
                        0,
                        $e
                    );
                }
            }
        }

        $idxStmt = $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'epg_programmes' AND INDEX_NAME = 'uq_epg_programmes'"
        );
        if ((int) $idxStmt->fetchColumn() === 0) {
            try {
                $pdo->exec('DELETE p1 FROM `epg_programmes` p1 INNER JOIN `epg_programmes` p2 ON p1.epg_id = p2.epg_id AND p1.start_utc = p2.start_utc AND p1.id > p2.id');
                $pdo->exec('ALTER TABLE `epg_programmes` ADD UNIQUE KEY `uq_epg_programmes` (`epg_id`, `start_utc`)');
            } catch (\Throwable) {
                // Ignore if already exists or insufficient privileges
            }
        }
        $grpColStmt = $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'm3u_groups' AND COLUMN_NAME = 'custom_name'"
        );
        if ((int) $grpColStmt->fetchColumn() === 0) {
            try {
                $pdo->exec('ALTER TABLE `m3u_groups` ADD COLUMN `custom_name` VARCHAR(255) NULL DEFAULT NULL AFTER `original_name`');
            } catch (\Throwable) {
                // Ignore if already exists or insufficient privileges
            }
        }

        $grpImportStmt = $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'm3u_groups' AND COLUMN_NAME = 'import_order'"
        );
        if ((int) $grpImportStmt->fetchColumn() === 0) {
            try {
                $pdo->exec('ALTER TABLE `m3u_groups` ADD COLUMN `import_order` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `sort_order`');
            } catch (\Throwable) {
                // Ignore if already exists or insufficient privileges
            }
        }

        $grpContentTypeStmt = $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'm3u_groups' AND COLUMN_NAME = 'content_type'"
        );
        if ((int) $grpContentTypeStmt->fetchColumn() === 0) {
            try {
                $pdo->exec("ALTER TABLE `m3u_groups` ADD COLUMN `content_type` ENUM('live','movie','series') NOT NULL DEFAULT 'live' AFTER `import_order`, ADD INDEX `idx_groups_content_type` (`content_type`)");
            } catch (\Throwable) {
                // Ignore if already exists or insufficient privileges
            }
        }

        $chContentTypeStmt = $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'channels' AND COLUMN_NAME = 'content_type'"
        );
        if ((int) $chContentTypeStmt->fetchColumn() === 0) {
            try {
                $pdo->exec("ALTER TABLE `channels` ADD COLUMN `content_type` ENUM('live','movie','series') NOT NULL DEFAULT 'live' AFTER `group_id`, ADD INDEX `idx_channels_content_type` (`content_type`)");
            } catch (\Throwable) {
                // Ignore if already exists or insufficient privileges
            }
        }

        $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value, is_encrypted) VALUES ('group_sort_mode', 'import', 0)");
        if (!self::columnExists($pdo, 'm3u_groups', 'category')) {
            $pdo->exec("ALTER TABLE `m3u_groups` ADD COLUMN `category` VARCHAR(32) NOT NULL DEFAULT 'Live TV' AFTER `content_type`");
        }
        if (!self::columnExists($pdo, 'm3u_groups', 'category_locked')) {
            $pdo->exec("ALTER TABLE `m3u_groups` ADD COLUMN `category_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `category`");
        }
        if (!self::indexExists($pdo, 'm3u_groups', 'idx_groups_category')) {
            $pdo->exec('ALTER TABLE `m3u_groups` ADD INDEX `idx_groups_category` (`category`)');
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `group_category_rules` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `pattern` VARCHAR(100) NOT NULL,
                `category` VARCHAR(32) NOT NULL DEFAULT 'Live TV',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_group_cat_pattern` (`pattern`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        if (!self::columnExists($pdo, 'm3u_groups', 'is_custom')) {
            $pdo->exec('ALTER TABLE `m3u_groups` ADD COLUMN `is_custom` TINYINT(1) NOT NULL DEFAULT 0 AFTER `category_locked`');
        }
        if (!self::columnExists($pdo, 'm3u_groups', 'source_group_id')) {
            $pdo->exec('ALTER TABLE `m3u_groups` ADD COLUMN `source_group_id` INT UNSIGNED NULL DEFAULT NULL AFTER `is_custom`');
        }
        if (!self::columnExists($pdo, 'm3u_groups', 'sync_diff_json')) {
            $pdo->exec('ALTER TABLE `m3u_groups` ADD COLUMN `sync_diff_json` MEDIUMTEXT NULL AFTER `source_group_id`');
        }
        if (!self::indexExists($pdo, 'm3u_groups', 'idx_groups_is_custom')) {
            $pdo->exec('ALTER TABLE `m3u_groups` ADD INDEX `idx_groups_is_custom` (`is_custom`)');
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `custom_group_channels` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `custom_group_id` INT UNSIGNED NOT NULL,
                `channel_id` INT UNSIGNED NULL DEFAULT NULL,
                `channel_tvg_name` VARCHAR(255) NULL DEFAULT NULL,
                `channel_tvg_id` VARCHAR(255) NULL DEFAULT NULL,
                `source_group_name` VARCHAR(255) NULL DEFAULT NULL,
                `saved_epg_channel_id` VARCHAR(255) NULL DEFAULT NULL,
                `saved_custom_name` VARCHAR(255) NULL DEFAULT NULL,
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
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $fkStmt = $pdo->prepare(
            "SELECT rc.DELETE_RULE
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
               AND rc.TABLE_NAME = 'custom_group_channels'
               AND rc.CONSTRAINT_NAME = 'fk_cgc_channel'
             LIMIT 1"
        );
        $fkStmt->execute();
        $deleteRule = $fkStmt->fetchColumn();
        if ($deleteRule !== false && strtoupper((string) $deleteRule) !== 'SET NULL') {
            $pdo->exec('ALTER TABLE `custom_group_channels` DROP FOREIGN KEY `fk_cgc_channel`');
            $pdo->exec('ALTER TABLE `custom_group_channels` MODIFY COLUMN `channel_id` INT UNSIGNED NULL DEFAULT NULL');
            $pdo->exec(
                'ALTER TABLE `custom_group_channels`
                 ADD CONSTRAINT `fk_cgc_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`) ON DELETE SET NULL'
            );
        }

        foreach (['channel_tvg_name', 'channel_tvg_id', 'source_group_name', 'saved_epg_channel_id', 'saved_custom_name'] as $col) {
            if (!self::columnExists($pdo, 'custom_group_channels', $col)) {
                $pdo->exec("ALTER TABLE `custom_group_channels` ADD COLUMN `{$col}` VARCHAR(255) NULL DEFAULT NULL");
            }
        }

        $pdo->exec("INSERT INTO settings (setting_key, setting_value, is_encrypted) VALUES ('schema_integrity_v9', '1', 0) ON DUPLICATE KEY UPDATE setting_value = '1'");
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
        );
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @return array<int, string>
     */
    private static function splitSqlStatements(string $sql): array
    {
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $statements = [];
        $current = '';
        $inSingle = false;
        $inDouble = false;
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($ch === "'" && !$inDouble && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $inDouble = !$inDouble;
            } elseif ($ch === ';' && !$inSingle && !$inDouble) {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }
            $current .= $ch;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    public function migrateIfNeeded(): bool
    {
        $updateFile = dirname(__DIR__, 2) . '/database/update.sql';
        if (!is_file($updateFile) || !is_readable($updateFile)) {
            return false;
        }

        $currentHash = hash_file('sha256', $updateFile);
        if ($currentHash === false) {
            return false;
        }

        $lastHash = (string) ($this->settings->get('last_update_sql_hash', '') ?? '');
        if ($lastHash !== '' && hash_equals($lastHash, $currentHash)) {
            return false;
        }

        $sql = file_get_contents($updateFile);
        if ($sql === false || trim($sql) === '') {
            return false;
        }

        $this->pdo->exec($sql);
        $this->settings->set('last_update_sql_hash', $currentHash, false);

        return true;
    }
}
