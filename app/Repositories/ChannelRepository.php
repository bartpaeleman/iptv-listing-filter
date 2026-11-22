<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

final class ChannelRepository extends BaseRepository
{
    protected string $table = 'channels';

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function filter(
        ?int $groupId,
        string $search,
        string $epgStatus,
        bool $activeOnly,
        int $limit,
        int $offset,
        string $countryTagId = '',
        string $status = 'all',
        string $sortBy = 'number'
    ): array {
        $where = ['1=1'];
        $params = [];

        if ($groupId !== null) {
            $where[] = 'c.group_id = :gid';
            $params[':gid'] = $groupId;
        }
        if ($search !== '') {
            $where[] = '(c.tvg_name LIKE :q OR c.custom_name LIKE :q OR c.tvg_id LIKE :q OR c.epg_channel_id LIKE :q)';
            $params[':q'] = '%' . $search . '%';
        }
        if ($activeOnly || $status === 'enabled') {
            $where[] = 'g.is_enabled = 1 AND c.is_enabled = 1';
        } elseif ($status === 'disabled') {
            $where[] = 'c.is_enabled = 0';
        }
        if ($epgStatus === 'mapped' || $epgStatus === 'yes') {
            $where[] = "c.epg_channel_id IS NOT NULL AND c.epg_channel_id <> ''";
        } elseif ($epgStatus === 'unmapped' || $epgStatus === 'no') {
            $where[] = "(c.epg_channel_id IS NULL OR c.epg_channel_id = '')";
        }
        if ($countryTagId === 'untagged') {
            $where[] = 'c.country_tag_id IS NULL';
        } elseif ($countryTagId !== '' && ctype_digit($countryTagId)) {
            $where[] = 'c.country_tag_id = :ctid';
            $params[':ctid'] = (int) $countryTagId;
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM channels c INNER JOIN m3u_groups g ON g.id = c.group_id WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $subOrder = match ($sortBy) {
            'name_asc' => "COALESCE(NULLIF(c.custom_name, ''), c.tvg_name) ASC, c.id ASC",
            'name_desc' => "COALESCE(NULLIF(c.custom_name, ''), c.tvg_name) DESC, c.id DESC",
            'import' => 'c.id ASC',
            default => '(c.channel_number IS NULL) ASC, c.channel_number ASC, c.id ASC',
        };
        $orderBy = "ORDER BY c.is_enabled DESC, {$subOrder}";

        $sql = "SELECT c.id, c.tvg_id, c.tvg_name, c.tvg_logo, c.group_id, c.custom_name,
                       c.channel_number, c.epg_channel_id, c.epg_match_type, c.is_enabled, c.sort_order,
                       COALESCE(NULLIF(g.custom_name, ''), g.original_name) AS group_name,
                       ec.display_name AS epg_display_name, ec.icon_url AS epg_icon_url
                FROM channels c
                INNER JOIN m3u_groups g ON g.id = c.group_id
                LEFT JOIN epg_channels ec ON ec.epg_id = c.epg_channel_id
                WHERE {$whereSql}
                {$orderBy}
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateChannel(int $id, array $data): void
    {
        $fields = [];
        $params = [':id' => $id];

        if (isset($data['is_enabled'])) {
            $fields[] = 'is_enabled = :enabled';
            $params[':enabled'] = (int) $data['is_enabled'] ? 1 : 0;
        }
        if (array_key_exists('custom_name', $data)) {
            $cname = mb_substr(trim((string) $data['custom_name']), 0, 250, 'UTF-8');
            $fields[] = 'custom_name = :cname';
            $params[':cname'] = $cname !== '' ? $cname : null;
        }
        if (array_key_exists('channel_number', $data)) {
            $chno = $data['channel_number'];
            $fields[] = 'channel_number = :chno';
            $params[':chno'] = ($chno !== null && $chno !== '') ? (int) $chno : null;
        }
        if (array_key_exists('epg_channel_id', $data)) {
            $epgId = mb_substr(trim((string) $data['epg_channel_id']), 0, 250, 'UTF-8');
            $fields[] = 'epg_channel_id = :epg_id';
            $fields[] = 'epg_match_type = :mtype';
            $params[':epg_id'] = $epgId !== '' ? $epgId : null;
            $params[':mtype'] = $epgId !== '' ? 'manual' : 'none';
        }

        if ($fields !== []) {
            $stmt = $this->pdo->prepare('UPDATE channels SET ' . implode(', ', $fields) . ' WHERE id = :id');
            $stmt->execute($params);
        }
    }

    /**
     * @param array<int, int> $ids
     */
    public function bulkSetEnabled(bool $enabled, array $ids = [], ?int $groupId = null, string $search = ''): void
    {
        $val = $enabled ? 1 : 0;
        if ($ids !== []) {
            $cleanIds = array_map('intval', $ids);
            $ph = implode(',', array_fill(0, count($cleanIds), '?'));
            $stmt = $this->pdo->prepare("UPDATE channels SET is_enabled = ? WHERE id IN ({$ph})");
            $stmt->execute(array_merge([$val], $cleanIds));
            return;
        }

        $where = ['1=1'];
        $params = [':val' => $val];
        if ($groupId !== null) {
            $where[] = 'group_id = :gid';
            $params[':gid'] = $groupId;
        }
        if ($search !== '') {
            $where[] = '(tvg_name LIKE :q OR custom_name LIKE :q OR tvg_id LIKE :q)';
            $params[':q'] = '%' . $search . '%';
        }
        $stmt = $this->pdo->prepare('UPDATE channels SET is_enabled = :val WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function reorderChannelInGroup(int $channelId, int $newPosition): array
    {
        $this->pdo->beginTransaction();
        try {
            $grpStmt = $this->pdo->prepare('SELECT group_id FROM channels WHERE id = :id LIMIT 1');
            $grpStmt->execute([':id' => $channelId]);
            $groupId = $grpStmt->fetchColumn();
            if ($groupId === false) {
                $this->pdo->commit();
                return [];
            }
            $groupId = (int) $groupId;

            $listStmt = $this->pdo->prepare(
                'SELECT id FROM channels
                 WHERE group_id = :gid AND is_enabled = 1
                 ORDER BY (channel_number IS NULL) ASC, channel_number ASC, sort_order ASC, id ASC'
            );
            $listStmt->execute([':gid' => $groupId]);
            $ids = array_map('intval', $listStmt->fetchAll(\PDO::FETCH_COLUMN));

            $filtered = array_values(array_filter($ids, static fn(int $id): bool => $id !== $channelId));
            $total = count($filtered) + 1;
            $pos = max(1, min($total, $newPosition));
            array_splice($filtered, $pos - 1, 0, [$channelId]);

            $updStmt = $this->pdo->prepare('UPDATE channels SET channel_number = :num WHERE id = :id');
            foreach ($filtered as $idx => $id) {
                $updStmt->execute([
                    ':num' => $idx + 1,
                    ':id' => $id,
                ]);
            }

            $this->pdo->commit();

            return $this->filter($groupId, '', '', false, 200, 0, '', 'all', 'number')['items'];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
