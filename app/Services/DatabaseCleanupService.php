<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class DatabaseCleanupService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    /**
     * @return array{channels: int, m3u_groups: int}
     */
    public function clearM3uData(): array
    {
        $this->pdo->beginTransaction();
        try {
            $deletedChannels = (int) $this->pdo->exec('DELETE FROM channels');
            $deletedGroups = (int) $this->pdo->exec('DELETE FROM m3u_groups');

            $stmt = $this->pdo->prepare('UPDATE settings SET setting_value = :val WHERE setting_key = :key');
            $stmt->execute([
                ':val' => '',
                ':key' => 'last_m3u_sync',
            ]);

            $this->pdo->commit();

            return [
                'channels' => $deletedChannels,
                'm3u_groups' => $deletedGroups,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{epg_programmes: int, epg_channels: int}
     */
    public function clearEpgData(): array
    {
        $this->pdo->beginTransaction();
        try {
            $deletedProgrammes = (int) $this->pdo->exec('DELETE FROM epg_programmes');
            $deletedEpgChannels = (int) $this->pdo->exec('DELETE FROM epg_channels');

            $this->pdo->exec('UPDATE channels SET epg_channel_id = NULL, epg_match_type = NULL');

            $stmt = $this->pdo->prepare('UPDATE settings SET setting_value = :val WHERE setting_key = :key');
            $stmt->execute([
                ':val' => '',
                ':key' => 'last_epg_sync',
            ]);

            $this->pdo->commit();

            return [
                'epg_programmes' => $deletedProgrammes,
                'epg_channels' => $deletedEpgChannels,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
