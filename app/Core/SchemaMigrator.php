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
