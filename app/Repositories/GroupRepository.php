<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

final class GroupRepository extends BaseRepository
{
    protected string $table = 'm3u_groups';

    /**
     * @param array<int, string> $contentTypes
     * @return array<int, array<string, mixed>>
     */
    public function allWithCounts(string $sortMode = 'import', array $contentTypes = []): array
    {
        $orderBy = match ($sortMode) {
            'asc' => "ORDER BY COALESCE(NULLIF(g.custom_name, ''), g.original_name) ASC, g.id ASC",
            'desc' => "ORDER BY COALESCE(NULLIF(g.custom_name, ''), g.original_name) DESC, g.id DESC",
            default => 'ORDER BY g.import_order ASC, g.id ASC',
        };

        if ($contentTypes !== []) {
            $placeholders = implode(', ', array_fill(0, count($contentTypes), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT g.id, g.original_name, g.custom_name, g.is_enabled, g.sort_order, g.import_order, g.content_type,
                        COUNT(c.id) AS channel_count,
                        SUM(CASE WHEN c.is_enabled = 1 THEN 1 ELSE 0 END) AS active_channel_count
                 FROM m3u_groups g
                 INNER JOIN channels c ON c.group_id = g.id AND c.content_type IN ({$placeholders})
                 GROUP BY g.id
                 {$orderBy}"
            );
            $stmt->execute(array_values($contentTypes));
            return $stmt->fetchAll();
        }

        return $this->pdo->query(
            "SELECT g.id, g.original_name, g.custom_name, g.is_enabled, g.sort_order, g.import_order, g.content_type,
                    COUNT(c.id) AS channel_count,
                    SUM(CASE WHEN c.is_enabled = 1 THEN 1 ELSE 0 END) AS active_channel_count
             FROM m3u_groups g
             LEFT JOIN channels c ON c.group_id = g.id
             GROUP BY g.id
             {$orderBy}"
        )->fetchAll();
    }

    public function updateGroup(int $id, ?int $isEnabled, ?string $customName, ?int $sortOrder): void
    {
        $fields = [];
        $params = [':id' => $id];

        if ($isEnabled !== null) {
            $fields[] = 'is_enabled = :enabled';
            $params[':enabled'] = $isEnabled ? 1 : 0;
        }
        if ($customName !== null) {
            $fields[] = 'custom_name = :cname';
            $params[':cname'] = $customName !== '' ? $customName : null;
        }
        if ($sortOrder !== null) {
            $fields[] = 'sort_order = :sort';
            $params[':sort'] = $sortOrder;
        }

        if ($fields !== []) {
            $stmt = $this->pdo->prepare('UPDATE m3u_groups SET ' . implode(', ', $fields) . ' WHERE id = :id');
            $stmt->execute($params);
        }
    }

    /**
     * @param array<int, int> $ids
     */
    public function bulkSetEnabled(bool $enabled, array $ids = []): void
    {
        $val = $enabled ? 1 : 0;
        if ($ids !== []) {
            $cleanIds = array_map('intval', $ids);
            $ph = implode(',', array_fill(0, count($cleanIds), '?'));
            $stmt = $this->pdo->prepare("UPDATE m3u_groups SET is_enabled = ? WHERE id IN ({$ph})");
            $stmt->execute(array_merge([$val], $cleanIds));
        } else {
            $stmt = $this->pdo->prepare('UPDATE m3u_groups SET is_enabled = ?');
            $stmt->execute([$val]);
        }
    }
}
