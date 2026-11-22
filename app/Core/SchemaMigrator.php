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

    public static function ensureSchemaUpToDate(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME, LOWER(DATA_TYPE) AS data_type
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND ((TABLE_NAME = 'channels' AND COLUMN_NAME = 'tvg_logo')
                 OR (TABLE_NAME = 'epg_channels' AND COLUMN_NAME = 'icon_url'))"
        );
        $stmt->execute();

        $needsMigration = false;
        foreach ($stmt->fetchAll() as $row) {
            if (!in_array($row['data_type'], ['mediumtext', 'longtext'], true)) {
                $needsMigration = true;
                break;
            }
        }

        if (!$needsMigration) {
            return;
        }

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
