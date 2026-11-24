<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

final class ChannelRepository extends BaseRepository
{
    protected string $table = 'channels';

    /**
     * @param array<int, string> $contentTypes
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
        string $sortBy = 'number',
        array $contentTypes = [],
        bool $liveOnly = false
    ): array {
        $isCustomGroup = false;
        if ($groupId !== null) {
            $cgStmt = $this->pdo->prepare('SELECT is_custom FROM m3u_groups WHERE id = ? LIMIT 1');
            $cgStmt->execute([$groupId]);
            $isCustomGroup = (int) $cgStmt->fetchColumn() === 1;
        }

        $where = ['1=1'];
        $params = [];

        if ($groupId !== null && !$isCustomGroup) {
            $where[] = 'c.group_id = :gid';
            $params[':gid'] = $groupId;
        } elseif ($isCustomGroup) {
            $params[':gid'] = $groupId;
        }
        if ($search !== '') {
            $where[] = '(c.tvg_name LIKE :q1 OR c.custom_name LIKE :q2 OR c.tvg_id LIKE :q3 OR c.epg_channel_id LIKE :q4)';
            $like = '%' . $search . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
        }
        if ($activeOnly || $status === 'enabled') {
            $where[] = 'g.is_enabled = 1 AND c.is_enabled = 1';
        } elseif ($status === 'disabled') {
            $where[] = 'c.is_enabled = 0';
        }
        if ($epgStatus === 'mapped' || $epgStatus === 'coupled' || $epgStatus === 'yes') {
            $where[] = "c.epg_channel_id IS NOT NULL AND c.epg_channel_id <> ''";
        } elseif ($epgStatus === 'unmapped' || $epgStatus === 'uncoupled' || $epgStatus === 'no') {
            $where[] = "(c.epg_channel_id IS NULL OR c.epg_channel_id = '')";
        }
        if ($countryTagId === 'untagged') {
            $where[] = 'c.country_tag_id IS NULL';
        } elseif ($countryTagId !== '' && ctype_digit($countryTagId)) {
            $where[] = 'c.country_tag_id = :ctid';
            $params[':ctid'] = (int) $countryTagId;
        }
        if ($contentTypes !== []) {
            $ctPlaceholders = [];
            foreach (array_values($contentTypes) as $idx => $ct) {
                $key = ":ct_{$idx}";
                $ctPlaceholders[] = $key;
                $params[$key] = $ct;
            }
            $where[] = 'c.content_type IN (' . implode(', ', $ctPlaceholders) . ')';
        }
        if ($liveOnly) {
            $where[] = "c.is_enabled = 1 AND g.is_enabled = 1 AND COALESCE(g.category, 'Live TV') NOT IN ('Film', 'TV Series')";
        }

        $whereSql = implode(' AND ', $where);
        $joinSql = $isCustomGroup
            ? 'INNER JOIN custom_group_channels cgc ON cgc.channel_id = c.id AND cgc.custom_group_id = :gid AND cgc.is_ignored = 0
               INNER JOIN m3u_groups g ON g.id = cgc.custom_group_id'
            : 'INNER JOIN m3u_groups g ON g.id = c.group_id';

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM channels c {$joinSql} WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        if ($isCustomGroup && $sortBy === 'number') {
            $orderBy = 'ORDER BY c.is_enabled DESC, cgc.sort_order ASC, cgc.id ASC';
        } elseif ($isCustomGroup && $sortBy === 'number_desc') {
            $orderBy = 'ORDER BY c.is_enabled DESC, cgc.sort_order DESC, cgc.id DESC';
        } else {
            $subOrder = match ($sortBy) {
                'name_asc' => "COALESCE(NULLIF(c.custom_name, ''), c.tvg_name) ASC, c.id ASC",
                'name_desc' => "COALESCE(NULLIF(c.custom_name, ''), c.tvg_name) DESC, c.id DESC",
                'epg_status_asc' => "(c.epg_channel_id IS NOT NULL AND c.epg_channel_id <> '') ASC, COALESCE(NULLIF(c.custom_name, ''), c.tvg_name) ASC, c.id ASC",
                'epg_status_desc' => "(c.epg_channel_id IS NOT NULL AND c.epg_channel_id <> '') DESC, COALESCE(NULLIF(c.custom_name, ''), c.tvg_name) ASC, c.id ASC",
                'epg_name_asc' => "(ec.display_name IS NULL OR ec.display_name = '') ASC, ec.display_name ASC, c.id ASC",
                'epg_name_desc' => "(ec.display_name IS NULL OR ec.display_name = '') ASC, ec.display_name DESC, c.id DESC",
                'import' => 'c.id ASC',
                'number_desc' => '(c.channel_number IS NULL) ASC, c.channel_number DESC, c.id DESC',
                default => '(c.channel_number IS NULL) ASC, c.channel_number ASC, c.id ASC',
            };
            $orderBy = "ORDER BY c.is_enabled DESC, {$subOrder}";
        }

        $channelNumExpr = $isCustomGroup ? 'cgc.sort_order AS channel_number' : 'c.channel_number';

        $sql = "SELECT c.id, c.tvg_id, c.tvg_name, c.tvg_logo, c.group_id, c.custom_name,
                       {$channelNumExpr}, c.epg_channel_id, c.epg_match_type, c.is_enabled, c.sort_order,
                       COALESCE(NULLIF(g.custom_name, ''), g.original_name) AS group_name,
                       g.category AS group_category,
                       ec.display_name AS epg_display_name, ec.icon_url AS epg_icon_url
                FROM channels c
                {$joinSql}
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

    public function reorderGroupBySort(int $groupId, string $sortBy): void
    {
        if ($groupId <= 0) {
            return;
        }
        $cgStmt = $this->pdo->prepare('SELECT is_custom FROM m3u_groups WHERE id = ? LIMIT 1');
        $cgStmt->execute([$groupId]);
        $isCustom = (int) $cgStmt->fetchColumn() === 1;

        if ($isCustom) {
            $orderSql = match ($sortBy) {
                'name_asc' => "COALESCE(NULLIF(c.custom_name, ''), c.tvg_name) ASC, c.id ASC",
                'name_desc' => "COALESCE(NULLIF(c.custom_name, ''), c.tvg_name) DESC, c.id DESC",
                'number_desc' => 'cgc.sort_order DESC, c.id DESC',
                default => 'cgc.sort_order ASC, c.id ASC',
            };
            $stmt = $this->pdo->prepare(
                "SELECT c.id FROM custom_group_channels cgc
                 INNER JOIN channels c ON c.id = cgc.channel_id
                 WHERE cgc.custom_group_id = :gid AND cgc.is_ignored = 0
                 ORDER BY c.is_enabled DESC, {$orderSql}"
            );
            $stmt->execute([':gid' => $groupId]);
            $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $this->pdo->beginTransaction();
            try {
                $upd = $this->pdo->prepare('UPDATE custom_group_channels SET sort_order = :num WHERE custom_group_id = :gid AND channel_id = :id');
                $num = 1;
                foreach ($ids as $id) {
                    $upd->execute([':num' => $num++, ':gid' => $groupId, ':id' => (int) $id]);
                }
                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
            return;
        }

        $orderSql = match ($sortBy) {
            'name_asc' => "COALESCE(NULLIF(custom_name, ''), tvg_name) ASC, id ASC",
            'name_desc' => "COALESCE(NULLIF(custom_name, ''), tvg_name) DESC, id DESC",
            'number_desc' => '(channel_number IS NULL) ASC, channel_number DESC, id DESC',
            default => '(channel_number IS NULL) ASC, channel_number ASC, id ASC',
        };

        $stmt = $this->pdo->prepare(
            "SELECT id FROM channels WHERE group_id = :gid AND is_enabled = 1 ORDER BY {$orderSql}"
        );
        $stmt->execute([':gid' => $groupId]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $this->pdo->beginTransaction();
        try {
            $upd = $this->pdo->prepare('UPDATE channels SET channel_number = :num WHERE id = :id');
            $num = 1;
            foreach ($ids as $id) {
                $upd->execute([':num' => $num++, ':id' => (int) $id]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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

    public function normalizeActiveGroupNumbers(int $groupId): bool
    {
        if ($groupId <= 0) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, channel_number
                 FROM channels
                 WHERE group_id = :gid AND is_enabled = 1
                 ORDER BY (channel_number IS NULL OR channel_number <= 0) ASC, channel_number ASC, id ASC'
            );
            $stmt->execute([':gid' => $groupId]);
            $rows = $stmt->fetchAll();

            $updStmt = $this->pdo->prepare('UPDATE channels SET channel_number = :num WHERE id = :id');
            $changed = false;
            $expected = 1;

            foreach ($rows as $row) {
                $currentNum = $row['channel_number'] !== null ? (int) $row['channel_number'] : 0;
                if ($currentNum !== $expected) {
                    $updStmt->execute([
                        ':num' => $expected,
                        ':id' => (int) $row['id'],
                    ]);
                    $changed = true;
                }
                $expected++;
            }

            $this->pdo->commit();
            return $changed;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param int[] $channelIds
     */
    public function bulkSetEnabledByIds(int $groupId, array $channelIds, int $isEnabled): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $channelIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        $val = $isEnabled === 1 ? 1 : 0;
        $this->pdo->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare(
                "UPDATE channels SET is_enabled = ? WHERE group_id = ? AND id IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$val, $groupId], $ids));
            $affected = $stmt->rowCount();
            $this->pdo->commit();
            return $affected;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function bulkReplaceNameInGroup(int $groupId, string $search, string $replace, bool $caseSensitive = false): int
    {
        if ($search === '') {
            return 0;
        }

        $cgStmt = $this->pdo->prepare('SELECT is_custom FROM m3u_groups WHERE id = ? LIMIT 1');
        $cgStmt->execute([$groupId]);
        $isCustom = (int) $cgStmt->fetchColumn() === 1;

        $this->pdo->beginTransaction();
        try {
            if ($isCustom) {
                $stmt = $this->pdo->prepare(
                    'SELECT c.id, c.tvg_name, c.custom_name
                     FROM channels c
                     INNER JOIN custom_group_channels cgc ON cgc.channel_id = c.id
                     WHERE cgc.custom_group_id = :gid AND cgc.is_ignored = 0'
                );
            } else {
                $stmt = $this->pdo->prepare('SELECT id, tvg_name, custom_name FROM channels WHERE group_id = :gid');
            }
            $stmt->execute([':gid' => $groupId]);
            $rows = $stmt->fetchAll();

            $updStmt = $this->pdo->prepare('UPDATE channels SET custom_name = :cname WHERE id = :id');
            $affected = 0;

            foreach ($rows as $row) {
                $tvgName = (string) ($row['tvg_name'] ?? '');
                $current = ($row['custom_name'] !== null && $row['custom_name'] !== '')
                    ? (string) $row['custom_name']
                    : $tvgName;

                $newName = $caseSensitive
                    ? str_replace($search, $replace, $current)
                    : str_ireplace($search, $replace, $current);
                $newName = trim((string) preg_replace('/\s{2,}/u', ' ', $newName));

                if ($newName === '' || $newName === $current) {
                    continue;
                }

                $storedCustom = ($newName === $tvgName) ? null : $newName;
                $updStmt->execute([
                    ':cname' => $storedCustom,
                    ':id' => (int) $row['id'],
                ]);
                $affected++;
            }

            $this->pdo->commit();
            return $affected;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, has_more: bool, next_offset: int, compatible_groups: array<int, array<string, mixed>>}
     */
    public function searchCompatibleChannels(
        int $customGroupId,
        string $query = '',
        ?int $sourceGroupId = null,
        int $limit = 20,
        int $offset = 0,
        string $category = '',
        string $status = 'all',
        string $countryTag = ''
    ): array {
        $grpStmt = $this->pdo->prepare('SELECT category FROM m3u_groups WHERE id = ? AND is_custom = 1 LIMIT 1');
        $grpStmt->execute([$customGroupId]);
        $targetCategory = $grpStmt->fetchColumn();
        if ($targetCategory === false) {
            return ['items' => [], 'has_more' => false, 'next_offset' => 0, 'allowed_categories' => []];
        }
        $targetCategory = (string) $targetCategory;

        $allowedCategories = match ($targetCategory) {
            'Film' => ['Film'],
            'TV Series' => ['TV Series'],
            default => ['Live TV', 'Sport', 'Animatie', 'Muziek', 'Adult'],
        };

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $where = [
            'g.is_custom = 0',
            "LTRIM(c.tvg_name) NOT LIKE '###%'",
            "LTRIM(COALESCE(c.custom_name, '')) NOT LIKE '###%'",
        ];
        $params = [':cgid' => $customGroupId];

        $cleanCategory = trim($category);
        if ($cleanCategory !== '' && in_array($cleanCategory, $allowedCategories, true)) {
            $where[] = "COALESCE(g.category, 'Live TV') = :selectedCategory";
            $params[':selectedCategory'] = $cleanCategory;
        } else {
            $catPlaceholders = [];
            foreach ($allowedCategories as $idx => $catVal) {
                $key = ":acat_{$idx}";
                $catPlaceholders[] = $key;
                $params[$key] = $catVal;
            }
            $where[] = "COALESCE(g.category, 'Live TV') IN (" . implode(', ', $catPlaceholders) . ')';
        }

        if ($sourceGroupId !== null && $sourceGroupId > 0) {
            $where[] = 'c.group_id = :sgid';
            $params[':sgid'] = $sourceGroupId;
        }

        if ($status === 'enabled') {
            $where[] = 'c.is_enabled = 1';
        } elseif ($status === 'disabled') {
            $where[] = 'c.is_enabled = 0';
        }

        $cleanTag = trim($countryTag, "[]()|: \t\n\r");
        if ($cleanTag !== '') {
            $pPipeEnd = '%' . $cleanTag . '|%';
            $pPipeStart = '%|' . $cleanTag . '%';
            $pBracket = '%[' . $cleanTag . ']%';
            $pParen = '%(' . $cleanTag . ')%';
            $pColon = '%' . $cleanTag . ':%';
            $where[] = '(c.tvg_name LIKE :t1 OR c.tvg_name LIKE :t2 OR c.tvg_name LIKE :t3 OR c.tvg_name LIKE :t4 OR c.tvg_name LIKE :t9
                         OR g.original_name LIKE :t5 OR g.original_name LIKE :t6 OR g.original_name LIKE :t7 OR g.original_name LIKE :t8 OR g.original_name LIKE :t10)';
            $params[':t1'] = $pPipeEnd;
            $params[':t2'] = $pPipeStart;
            $params[':t3'] = $pBracket;
            $params[':t4'] = $pParen;
            $params[':t5'] = $pPipeEnd;
            $params[':t6'] = $pPipeStart;
            $params[':t7'] = $pBracket;
            $params[':t8'] = $pParen;
            $params[':t9'] = $pColon;
            $params[':t10'] = $pColon;
        }

        $cleanQuery = trim($query);
        if ($cleanQuery !== '') {
            $where[] = "(LOWER(c.tvg_name) LIKE :q1 OR LOWER(COALESCE(c.custom_name, '')) LIKE :q2)";
            $like = '%' . mb_strtolower($cleanQuery) . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
        }

        $whereSql = implode(' AND ', $where);
        $fetchLimit = $limit + 1;

        $sql = "SELECT c.id, c.tvg_name, c.custom_name, c.group_id, c.epg_channel_id, c.is_enabled,
                       COALESCE(NULLIF(c.custom_name, ''), c.tvg_name) AS display_name,
                       COALESCE(NULLIF(g.custom_name, ''), g.original_name) AS group_display_name,
                       CASE WHEN cgc.id IS NOT NULL AND cgc.is_ignored = 0 THEN 1 ELSE 0 END AS already_in_custom
                FROM channels c
                INNER JOIN m3u_groups g ON g.id = c.group_id
                LEFT JOIN custom_group_channels cgc ON cgc.channel_id = c.id AND cgc.custom_group_id = :cgid
                WHERE {$whereSql}
                ORDER BY c.tvg_name ASC, c.id ASC
                LIMIT {$fetchLimit} OFFSET {$offset}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        foreach ($rows as &$row) {
            $inCustom = ((int) $row['already_in_custom']) === 1;
            $row['id'] = (int) $row['id'];
            $row['group_id'] = (int) $row['group_id'];
            $row['is_enabled'] = ((int) $row['is_enabled']) === 1;
            $row['already_in_custom'] = $inCustom;
            $row['in_custom_group'] = $inCustom;
        }
        unset($row);

        $compatibleGroups = [];
        if ($offset === 0) {
            $catPlaceholders2 = [];
            $cgParams = [];
            foreach ($allowedCategories as $idx => $catVal) {
                $key = ":cgcat_{$idx}";
                $catPlaceholders2[] = $key;
                $cgParams[$key] = $catVal;
            }
            $cgSql = "SELECT id, COALESCE(NULLIF(custom_name, ''), original_name) AS name
                      FROM m3u_groups
                      WHERE is_custom = 0 AND COALESCE(category, 'Live TV') IN (" . implode(', ', $catPlaceholders2) . ')
                      ORDER BY name ASC';
            $cgStmt = $this->pdo->prepare($cgSql);
            $cgStmt->execute($cgParams);
            $compatibleGroups = $cgStmt->fetchAll();
        }

        return [
            'items' => $rows,
            'has_more' => $hasMore,
            'next_offset' => $offset + count($rows),
            'allowed_categories' => $allowedCategories,
            'compatible_groups' => $compatibleGroups,
        ];
    }
}
