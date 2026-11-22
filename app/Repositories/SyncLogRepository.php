<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

final class SyncLogRepository extends BaseRepository
{
    protected string $table = 'sync_logs';

    /**
     * @param array<string, mixed> $stats
     */
    public function addLog(string $type, string $status, string $message, array $stats = []): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sync_logs (sync_type, status, message, stats_json) VALUES (:type, :status, :msg, :stats)'
        );
        $stmt->execute([
            ':type' => $type,
            ':status' => $status,
            ':msg' => mb_substr($message, 0, 500),
            ':stats' => json_encode($stats, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 15): array
    {
        $stmt = $this->pdo->prepare('SELECT id, sync_type, status, message, stats_json, created_at FROM sync_logs ORDER BY id DESC LIMIT :lim');
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function clearAll(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM sync_logs');
        $stmt->execute();
        return $stmt->rowCount();
    }
}
