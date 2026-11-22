<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;
use App\Security\CryptoService;
use PDO;

final class EpgSourceRepository extends BaseRepository
{
    protected string $table = 'epg_sources';
    private CryptoService $crypto;

    public function __construct(?PDO $pdo = null, ?CryptoService $crypto = null)
    {
        parent::__construct($pdo);
        $this->crypto = $crypto ?? new CryptoService();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allDecrypted(bool $enabledOnly = false): array
    {
        $sql = 'SELECT id, name, url, priority, is_enabled, last_sync_at, last_status FROM epg_sources';
        if ($enabledOnly) {
            $sql .= ' WHERE is_enabled = 1';
        }
        $sql .= ' ORDER BY priority ASC, id ASC';

        $rows = $this->pdo->query($sql)->fetchAll();
        foreach ($rows as &$row) {
            $raw = (string) ($row['url'] ?? '');
            try {
                $row['url'] = $raw !== '' ? $this->crypto->decrypt($raw) : '';
            } catch (\Throwable) {
                $row['url'] = '';
            }
        }
        return $rows;
    }

    public function addOrUpdate(string $name, string $url, int $priority = 10, ?int $id = null): int
    {
        $encUrl = $this->crypto->encrypt(trim($url));
        if ($id !== null && $id > 0) {
            $stmt = $this->pdo->prepare('UPDATE epg_sources SET name = :n, url = :u, priority = :p WHERE id = :id');
            $stmt->execute([':n' => trim($name), ':u' => $encUrl, ':p' => $priority, ':id' => $id]);
            return $id;
        }

        $stmt = $this->pdo->prepare('INSERT INTO epg_sources (name, url, priority, is_enabled) VALUES (:n, :u, :p, 1)');
        $stmt->execute([':n' => trim($name), ':u' => $encUrl, ':p' => $priority]);
        return (int) $this->pdo->lastInsertId();
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE epg_sources SET is_enabled = :e WHERE id = :id');
        $stmt->execute([':e' => $enabled ? 1 : 0, ':id' => $id]);
    }

    public function updateStatus(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE epg_sources SET last_sync_at = :dt, last_status = :st WHERE id = :id');
        $stmt->execute([
            ':dt' => gmdate('Y-m-d H:i:s'),
            ':st' => mb_substr($status, 0, 255),
            ':id' => $id,
        ]);
    }
}
