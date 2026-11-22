<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\SettingsRepository;
use PDO;

final class SchemaMigrator
{
    private PDO $pdo;
    private SettingsRepository $settings;

    public function __construct(?PDO $pdo = null, ?SettingsRepository $settings = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
        $this->settings = $settings ?? new SettingsRepository($this->pdo);
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
