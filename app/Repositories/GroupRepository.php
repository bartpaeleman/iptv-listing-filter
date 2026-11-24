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
            'asc' => 'ORDER BY g.original_name ASC, g.id ASC',
            'desc' => 'ORDER BY g.original_name DESC, g.id DESC',
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

    /**
     * @param array<int, string> $contentTypes
     * @return array<int, array<string, mixed>>
     */
    public function activeWithCounts(string $sortMode = 'asc', array $contentTypes = []): array
    {
        $orderBy = 'ORDER BY g.original_name ASC, g.id ASC';

        if ($contentTypes !== []) {
            $placeholders = implode(', ', array_fill(0, count($contentTypes), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT g.id, g.original_name, g.custom_name, g.is_enabled, g.sort_order, g.import_order, g.content_type,
                        g.category, g.category_locked, g.is_custom, g.source_group_id, g.sync_diff_json,
                        CASE WHEN g.is_custom = 1 THEN
                            (SELECT COUNT(*) FROM custom_group_channels cgc WHERE cgc.custom_group_id = g.id AND cgc.is_ignored = 0)
                        ELSE
                            COUNT(c.id)
                        END AS channel_count,
                        CASE WHEN g.is_custom = 1 THEN
                            (SELECT COUNT(*) FROM custom_group_channels cgc INNER JOIN channels cc ON cc.id = cgc.channel_id WHERE cgc.custom_group_id = g.id AND cgc.is_ignored = 0 AND cc.is_enabled = 1)
                        ELSE
                            SUM(CASE WHEN c.is_enabled = 1 THEN 1 ELSE 0 END)
                        END AS active_channel_count
                 FROM m3u_groups g
                 LEFT JOIN channels c ON c.group_id = g.id AND g.is_custom = 0
                 WHERE g.is_enabled = 1 AND g.content_type IN ({$placeholders})
                 GROUP BY g.id
                 {$orderBy}"
            );
            $stmt->execute(array_values($contentTypes));
            return $stmt->fetchAll();
        }

        return $this->pdo->query(
            "SELECT g.id, g.original_name, g.custom_name, g.is_enabled, g.sort_order, g.import_order, g.content_type,
                    g.category, g.category_locked, g.is_custom, g.source_group_id, g.sync_diff_json,
                    CASE WHEN g.is_custom = 1 THEN
                        (SELECT COUNT(*) FROM custom_group_channels cgc WHERE cgc.custom_group_id = g.id AND cgc.is_ignored = 0)
                    ELSE
                        COUNT(c.id)
                    END AS channel_count,
                    CASE WHEN g.is_custom = 1 THEN
                        (SELECT COUNT(*) FROM custom_group_channels cgc INNER JOIN channels cc ON cc.id = cgc.channel_id WHERE cgc.custom_group_id = g.id AND cgc.is_ignored = 0 AND cc.is_enabled = 1)
                    ELSE
                        SUM(CASE WHEN c.is_enabled = 1 THEN 1 ELSE 0 END)
                    END AS active_channel_count
             FROM m3u_groups g
             LEFT JOIN channels c ON c.group_id = g.id AND g.is_custom = 0
             WHERE g.is_enabled = 1
             GROUP BY g.id
             {$orderBy}"
        )->fetchAll();
    }

    /**
     * @param array<int, string> $contentTypes
     * @param array<int, string> $categories
     * @return array{items: array<int, array<string, mixed>>, has_more: bool, next_offset: int}
     */
    public function searchGroups(string $query, array $contentTypes = [], string $status = 'disabled', int $limit = 100, int $offset = 0, array $categories = []): array
    {
        $where = ['g.is_custom = 0'];
        $params = [];

        if ($query !== '') {
            if (preg_match('/^(?:\(([^)]+)\)|\[([^\]]+)\]|\\|([^|]+)\\|)\s*(.*)$/u', $query, $m)) {
                $tag = trim($m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : $m[3]));
                $rest = trim($m[4] ?? '');
                if ($tag !== '') {
                    $where[] = '(g.original_name LIKE :t1 OR g.original_name LIKE :t2 OR g.original_name LIKE :t3 OR g.original_name LIKE :t4 OR g.original_name LIKE :t5)';
                    $params[':t1'] = '%[' . $tag . ']%';
                    $params[':t2'] = '%|' . $tag . '|%';
                    $params[':t3'] = '%(' . $tag . ')%';
                    $params[':t4'] = $tag . '|%';
                    $params[':t5'] = $tag . ' -%';
                }
                if ($rest !== '') {
                    $where[] = '(g.original_name LIKE :q1 OR g.custom_name LIKE :q2)';
                    $like = '%' . $rest . '%';
                    $params[':q1'] = $like;
                    $params[':q2'] = $like;
                }
            } else {
                $where[] = '(g.original_name LIKE :q1 OR g.custom_name LIKE :q2)';
                $like = '%' . $query . '%';
                $params[':q1'] = $like;
                $params[':q2'] = $like;
            }
        }
        if ($status === 'disabled') {
            $where[] = 'g.is_enabled = 0';
        } elseif ($status === 'enabled') {
            $where[] = 'g.is_enabled = 1';
        }
        if ($contentTypes !== []) {
            $ph = [];
            foreach (array_values($contentTypes) as $idx => $ct) {
                $key = ":ct_{$idx}";
                $ph[] = $key;
                $params[$key] = $ct;
            }
            $where[] = 'g.content_type IN (' . implode(', ', $ph) . ')';
        }
        if ($categories !== []) {
            $catPh = [];
            foreach (array_values($categories) as $idx => $cat) {
                $key = ":cat_{$idx}";
                $catPh[] = $key;
                $params[$key] = $cat;
            }
            $where[] = 'g.category IN (' . implode(', ', $catPh) . ')';
        }

        $safeLimit = max(1, min(200, $limit));
        $safeOffset = max(0, $offset);
        $limitPlusOne = $safeLimit + 1;
        $whereSql = implode(' AND ', $where);
        $stmt = $this->pdo->prepare(
            "SELECT g.id, g.original_name, g.custom_name, g.is_enabled, g.sort_order, g.import_order, g.content_type,
                    g.category, g.category_locked
             FROM m3u_groups g
             WHERE {$whereSql}
             ORDER BY g.original_name ASC, g.id ASC
             LIMIT :limitPlusOne OFFSET :offset"
        );
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limitPlusOne', $limitPlusOne, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $safeOffset, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        $hasMore = count($rows) > $safeLimit;
        $items = array_slice($rows, 0, $safeLimit);

        return [
            'items' => $items,
            'has_more' => $hasMore,
            'next_offset' => $safeOffset + count($items),
        ];
    }

    public function updateCategory(int $groupId, string $category, bool $lock = true): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE m3u_groups SET category = :cat, category_locked = :locked WHERE id = :id'
        );
        $stmt->execute([
            ':cat' => $category,
            ':locked' => $lock ? 1 : 0,
            ':id' => $groupId,
        ]);
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
