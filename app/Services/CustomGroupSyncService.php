<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class CustomGroupSyncService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    public function evaluateAllConvertedGroups(): int
    {
        $stmt = $this->pdo->query(
            'SELECT id, source_group_id FROM m3u_groups
             WHERE is_custom = 1 AND source_group_id IS NOT NULL'
        );
        $groups = $stmt->fetchAll();

        $diffCount = 0;
        foreach ($groups as $group) {
            $customGroupId = (int) $group['id'];
            $sourceGroupId = (int) $group['source_group_id'];
            if ($this->evaluateGroup($customGroupId, $sourceGroupId)) {
                $diffCount++;
            }
        }

        return $diffCount;
    }

    public function evaluateGroup(int $customGroupId, int $sourceGroupId): bool
    {
        $srcStmt = $this->pdo->prepare(
            'SELECT id, COALESCE(NULLIF(custom_name, \'\'), tvg_name) AS name, tvg_logo
             FROM channels
             WHERE group_id = ?
             ORDER BY id ASC'
        );
        $srcStmt->execute([$sourceGroupId]);
        $sourceChannels = [];
        foreach ($srcStmt->fetchAll() as $row) {
            $sourceChannels[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'tvg_logo' => (string) ($row['tvg_logo'] ?? ''),
            ];
        }

        $cgcStmt = $this->pdo->prepare(
            'SELECT cgc.channel_id, cgc.is_ignored,
                    COALESCE(NULLIF(c.custom_name, \'\'), c.tvg_name) AS name,
                    c.tvg_logo
             FROM custom_group_channels cgc
             INNER JOIN channels c ON c.id = cgc.channel_id
             WHERE cgc.custom_group_id = ?'
        );
        $cgcStmt->execute([$customGroupId]);
        $existingAll = [];
        $existingActive = [];
        foreach ($cgcStmt->fetchAll() as $row) {
            $cid = (int) $row['channel_id'];
            $existingAll[$cid] = true;
            if ((int) $row['is_ignored'] === 0) {
                $existingActive[$cid] = [
                    'id' => $cid,
                    'name' => (string) $row['name'],
                    'tvg_logo' => (string) ($row['tvg_logo'] ?? ''),
                ];
            }
        }

        $added = [];
        foreach ($sourceChannels as $cid => $info) {
            if (!isset($existingAll[$cid])) {
                $added[] = $info;
            }
        }

        $removed = [];
        foreach ($existingActive as $cid => $info) {
            if (!isset($sourceChannels[$cid])) {
                $removed[] = $info;
            }
        }

        $hasDiff = ($added !== [] || $removed !== []);
        $json = $hasDiff ? json_encode(['added' => $added, 'removed' => $removed], JSON_UNESCAPED_UNICODE) : null;

        $upd = $this->pdo->prepare('UPDATE m3u_groups SET sync_diff_json = ? WHERE id = ?');
        $upd->execute([$json, $customGroupId]);

        return $hasDiff;
    }

    /**
     * @param array<int, int> $addIds
     * @param array<int, int> $ignoreAddIds
     * @param array<int, int> $removeIds
     */
    public function resolveSyncDiff(int $customGroupId, array $addIds, array $ignoreAddIds, array $removeIds): void
    {
        $grpStmt = $this->pdo->prepare('SELECT source_group_id FROM m3u_groups WHERE id = ? AND is_custom = 1 LIMIT 1');
        $grpStmt->execute([$customGroupId]);
        $sourceGroupId = $grpStmt->fetchColumn();
        if ($sourceGroupId === false) {
            return;
        }

        $cleanAdd = array_values(array_unique(array_filter(array_map('intval', $addIds), static fn(int $id): bool => $id > 0)));
        $cleanIgnore = array_values(array_unique(array_filter(array_map('intval', $ignoreAddIds), static fn(int $id): bool => $id > 0)));
        $cleanRemove = array_values(array_unique(array_filter(array_map('intval', $removeIds), static fn(int $id): bool => $id > 0)));

        if ($cleanAdd !== []) {
            $maxOrderStmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM custom_group_channels WHERE custom_group_id = ?');
            $maxOrderStmt->execute([$customGroupId]);
            $nextOrder = (int) $maxOrderStmt->fetchColumn() + 1;

            $ins = $this->pdo->prepare(
                'INSERT INTO custom_group_channels (custom_group_id, channel_id, sort_order, is_ignored)
                 VALUES (?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE is_ignored = 0'
            );
            foreach ($cleanAdd as $cid) {
                $ins->execute([$customGroupId, $cid, $nextOrder]);
                $nextOrder++;
            }
        }

        if ($cleanIgnore !== []) {
            $insIgn = $this->pdo->prepare(
                'INSERT INTO custom_group_channels (custom_group_id, channel_id, sort_order, is_ignored)
                 VALUES (?, ?, 0, 1)
                 ON DUPLICATE KEY UPDATE is_ignored = 1'
            );
            foreach ($cleanIgnore as $cid) {
                $insIgn->execute([$customGroupId, $cid]);
            }
        }

        if ($cleanRemove !== []) {
            $placeholders = implode(', ', array_fill(0, count($cleanRemove), '?'));
            $del = $this->pdo->prepare(
                "DELETE FROM custom_group_channels WHERE custom_group_id = ? AND channel_id IN ({$placeholders})"
            );
            $del->execute(array_merge([$customGroupId], $cleanRemove));
        }

        if ($sourceGroupId !== null && (int) $sourceGroupId > 0) {
            $this->evaluateGroup($customGroupId, (int) $sourceGroupId);
        } else {
            $this->pdo->prepare('UPDATE m3u_groups SET sync_diff_json = NULL WHERE id = ?')->execute([$customGroupId]);
        }
    }

    public function backupCustomGroupsToDisk(): void
    {
        $groups = $this->pdo->query(
            'SELECT id, original_name, custom_name, category, content_type, is_enabled, sort_order, source_group_id
             FROM m3u_groups
             WHERE is_custom = 1
             ORDER BY id ASC'
        )->fetchAll();

        $channelsStmt = $this->pdo->prepare(
            'SELECT cgc.channel_id, cgc.channel_tvg_name, cgc.channel_tvg_id, cgc.source_group_name,
                    cgc.saved_epg_channel_id, cgc.saved_custom_name, cgc.sort_order, cgc.is_ignored,
                    c.tvg_name, c.tvg_id, c.epg_channel_id, c.custom_name, g.original_name AS live_source_group_name
             FROM custom_group_channels cgc
             LEFT JOIN channels c ON c.id = cgc.channel_id
             LEFT JOIN m3u_groups g ON g.id = c.group_id
             WHERE cgc.custom_group_id = ?
             ORDER BY cgc.sort_order ASC, cgc.id ASC'
        );

        $payload = [
            'created_at' => date('c'),
            'groups' => [],
        ];

        foreach ($groups as $grp) {
            $channelsStmt->execute([(int) $grp['id']]);
            $grp['channels'] = $channelsStmt->fetchAll();
            $payload['groups'][] = $grp;
        }

        $backupDir = dirname(__DIR__, 2) . '/storage/backups';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0775, true);
        }

        $target = $backupDir . '/custom_groups_latest.json';
        $tmp = $target . '.tmp';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false && @file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $target);
        }
    }

    public function captureSnapshotsBeforeSync(): void
    {
        $this->pdo->exec(
            "UPDATE custom_group_channels cgc
             INNER JOIN channels c ON c.id = cgc.channel_id
             LEFT JOIN m3u_groups g ON g.id = c.group_id
             SET cgc.channel_tvg_name = c.tvg_name,
                 cgc.channel_tvg_id = c.tvg_id,
                 cgc.source_group_name = COALESCE(g.original_name, cgc.source_group_name),
                 cgc.saved_epg_channel_id = CASE WHEN c.epg_channel_id IS NOT NULL AND c.epg_channel_id <> '' THEN c.epg_channel_id ELSE cgc.saved_epg_channel_id END,
                 cgc.saved_custom_name = CASE WHEN c.custom_name IS NOT NULL AND c.custom_name <> '' THEN c.custom_name ELSE cgc.saved_custom_name END"
        );
    }

    public function relinkCustomGroupChannelsAfterSync(): void
    {
        $orphans = $this->pdo->query(
            "SELECT id, channel_tvg_name, source_group_name, saved_epg_channel_id, saved_custom_name
             FROM custom_group_channels
             WHERE channel_id IS NULL AND channel_tvg_name <> ''"
        )->fetchAll();

        if ($orphans === []) {
            return;
        }

        $exactStmt = $this->pdo->prepare(
            'SELECT c.id, c.epg_channel_id, c.custom_name
             FROM channels c
             INNER JOIN m3u_groups g ON g.id = c.group_id
             WHERE c.tvg_name = ? AND g.original_name = ?
             LIMIT 1'
        );
        $fallbackStmt = $this->pdo->prepare(
            'SELECT c.id, c.epg_channel_id, c.custom_name
             FROM channels c
             WHERE c.tvg_name = ?
             LIMIT 1'
        );
        $relinkStmt = $this->pdo->prepare('UPDATE IGNORE custom_group_channels SET channel_id = ? WHERE id = ?');
        $restoreChannelStmt = $this->pdo->prepare(
            "UPDATE channels
             SET epg_channel_id = CASE WHEN (epg_channel_id IS NULL OR epg_channel_id = '') AND ? IS NOT NULL AND ? <> '' THEN ? ELSE epg_channel_id END,
                 custom_name = CASE WHEN (custom_name IS NULL OR custom_name = '') AND ? IS NOT NULL AND ? <> '' THEN ? ELSE custom_name END
             WHERE id = ?"
        );

        foreach ($orphans as $row) {
            $tvgName = (string) $row['channel_tvg_name'];
            $srcGroup = (string) $row['source_group_name'];
            $match = false;

            if ($srcGroup !== '') {
                $exactStmt->execute([$tvgName, $srcGroup]);
                $match = $exactStmt->fetch();
            }
            if (!$match) {
                $fallbackStmt->execute([$tvgName]);
                $match = $fallbackStmt->fetch();
            }
            if (!$match) {
                continue;
            }

            $cid = (int) $match['id'];
            $relinkStmt->execute([$cid, (int) $row['id']]);

            $savedEpg = $row['saved_epg_channel_id'];
            $savedCustom = $row['saved_custom_name'];
            if (($savedEpg !== null && $savedEpg !== '') || ($savedCustom !== null && $savedCustom !== '')) {
                $restoreChannelStmt->execute([
                    $savedEpg, $savedEpg, $savedEpg,
                    $savedCustom, $savedCustom, $savedCustom,
                    $cid,
                ]);
            }
        }
    }

    public function getLatestBackupInfo(): array
    {
        $path = dirname(__DIR__, 2) . '/storage/backups/custom_groups_latest.json';
        if (!is_file($path) || !is_readable($path)) {
            return [
                'exists' => false,
                'filename' => 'custom_groups_latest.json',
                'updated_at' => null,
                'size_bytes' => 0,
                'group_count' => 0,
                'channel_count' => 0,
            ];
        }

        $size = (int) (@filesize($path) ?: 0);
        $mtime = @filemtime($path);
        $updatedAt = $mtime ? date('Y-m-d H:i:s', $mtime) : null;
        $raw = (string) @file_get_contents($path);
        $decoded = json_decode($raw, true);

        $groups = is_array($decoded['groups'] ?? null) ? $decoded['groups'] : [];
        $channelCount = 0;
        foreach ($groups as $g) {
            if (is_array($g['channels'] ?? null)) {
                $channelCount += count($g['channels']);
            }
        }

        return [
            'exists' => true,
            'filename' => 'custom_groups_latest.json',
            'updated_at' => $updatedAt,
            'size_bytes' => $size,
            'group_count' => count($groups),
            'channel_count' => $channelCount,
        ];
    }

    public function restoreCustomGroupsFromJson(string $rawJson): array
    {
        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded) || !isset($decoded['groups']) || !is_array($decoded['groups'])) {
            throw new \InvalidArgumentException('Ongeldig backup JSON-formaat: veld "groups" ontbreekt.');
        }

        $groupsRestored = 0;
        $channelsLinked = 0;
        $channelsPending = 0;

        $findGroupStmt = $this->pdo->prepare('SELECT id FROM m3u_groups WHERE original_name = ? AND is_custom = 1 LIMIT 1');
        $checkSourceIdStmt = $this->pdo->prepare('SELECT id FROM m3u_groups WHERE id = ? LIMIT 1');
        $insertGroupStmt = $this->pdo->prepare(
            'INSERT INTO m3u_groups (original_name, custom_name, category, category_locked, content_type, is_enabled, sort_order, is_custom, source_group_id)
             VALUES (?, ?, ?, 1, ?, ?, ?, 1, ?)'
        );
        $updateGroupStmt = $this->pdo->prepare(
            'UPDATE m3u_groups
             SET custom_name = ?, category = ?, category_locked = 1, content_type = ?, is_enabled = ?, sort_order = ?, source_group_id = ?
             WHERE id = ?'
        );

        $exactChannelStmt = $this->pdo->prepare(
            'SELECT c.id FROM channels c INNER JOIN m3u_groups g ON g.id = c.group_id WHERE c.tvg_name = ? AND g.original_name = ? LIMIT 1'
        );
        $fallbackChannelStmt = $this->pdo->prepare('SELECT id FROM channels WHERE tvg_name = ? LIMIT 1');

        $upsertCgcStmt = $this->pdo->prepare(
            'INSERT INTO custom_group_channels
                (custom_group_id, channel_id, channel_tvg_name, channel_tvg_id, source_group_name, saved_epg_channel_id, saved_custom_name, sort_order, is_ignored)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                sort_order = VALUES(sort_order),
                is_ignored = VALUES(is_ignored),
                channel_tvg_name = VALUES(channel_tvg_name),
                channel_tvg_id = VALUES(channel_tvg_id),
                source_group_name = VALUES(source_group_name),
                saved_epg_channel_id = COALESCE(VALUES(saved_epg_channel_id), saved_epg_channel_id),
                saved_custom_name = COALESCE(VALUES(saved_custom_name), saved_custom_name)'
        );

        $restoreChannelMetaStmt = $this->pdo->prepare(
            "UPDATE channels
             SET epg_channel_id = CASE WHEN (epg_channel_id IS NULL OR epg_channel_id = '') AND ? IS NOT NULL AND ? <> '' THEN ? ELSE epg_channel_id END,
                 custom_name = CASE WHEN (custom_name IS NULL OR custom_name = '') AND ? IS NOT NULL AND ? <> '' THEN ? ELSE custom_name END
             WHERE id = ?"
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($decoded['groups'] as $grp) {
                if (!is_array($grp) || empty($grp['original_name'])) {
                    continue;
                }
                $origName = (string) $grp['original_name'];
                $customName = isset($grp['custom_name']) ? (string) $grp['custom_name'] : null;
                $category = (string) ($grp['category'] ?? 'Live TV');
                $contentType = (string) ($grp['content_type'] ?? 'live');
                $isEnabled = (int) ($grp['is_enabled'] ?? 1);
                $sortOrder = (int) ($grp['sort_order'] ?? 0);

                $sourceGroupId = null;
                if (!empty($grp['source_group_id'])) {
                    $checkSourceIdStmt->execute([(int) $grp['source_group_id']]);
                    if ($checkSourceIdStmt->fetchColumn()) {
                        $sourceGroupId = (int) $grp['source_group_id'];
                    }
                }

                $findGroupStmt->execute([$origName]);
                $existingId = $findGroupStmt->fetchColumn();

                if ($existingId) {
                    $customGroupId = (int) $existingId;
                    $updateGroupStmt->execute([$customName, $category, $contentType, $isEnabled, $sortOrder, $sourceGroupId, $customGroupId]);
                } else {
                    $insertGroupStmt->execute([$origName, $customName, $category, $contentType, $isEnabled, $sortOrder, $sourceGroupId]);
                    $customGroupId = (int) $this->pdo->lastInsertId();
                }
                $groupsRestored++;

                $channels = is_array($grp['channels'] ?? null) ? $grp['channels'] : [];
                foreach ($channels as $ch) {
                    if (!is_array($ch)) {
                        continue;
                    }
                    $tvgName = (string) ($ch['channel_tvg_name'] ?? $ch['tvg_name'] ?? '');
                    $tvgId = $ch['channel_tvg_id'] ?? $ch['tvg_id'] ?? null;
                    $srcGroupName = (string) ($ch['source_group_name'] ?? $ch['live_source_group_name'] ?? '');
                    $savedEpg = $ch['saved_epg_channel_id'] ?? $ch['epg_channel_id'] ?? null;
                    $savedCustom = $ch['saved_custom_name'] ?? $ch['custom_name'] ?? null;
                    $chSort = (int) ($ch['sort_order'] ?? 0);
                    $isIgnored = (int) ($ch['is_ignored'] ?? 0);

                    $channelId = null;
                    if ($tvgName !== '' && $srcGroupName !== '') {
                        $exactChannelStmt->execute([$tvgName, $srcGroupName]);
                        $found = $exactChannelStmt->fetchColumn();
                        if ($found) {
                            $channelId = (int) $found;
                        }
                    }
                    if ($channelId === null && $tvgName !== '') {
                        $fallbackChannelStmt->execute([$tvgName]);
                        $found = $fallbackChannelStmt->fetchColumn();
                        if ($found) {
                            $channelId = (int) $found;
                        }
                    }

                    $upsertCgcStmt->execute([
                        $customGroupId,
                        $channelId,
                        $tvgName,
                        $tvgId !== null ? (string) $tvgId : null,
                        $srcGroupName,
                        $savedEpg !== null ? (string) $savedEpg : null,
                        $savedCustom !== null ? (string) $savedCustom : null,
                        $chSort,
                        $isIgnored,
                    ]);

                    if ($channelId !== null) {
                        $channelsLinked++;
                        if (($savedEpg !== null && $savedEpg !== '') || ($savedCustom !== null && $savedCustom !== '')) {
                            $restoreChannelMetaStmt->execute([
                                $savedEpg, $savedEpg, $savedEpg,
                                $savedCustom, $savedCustom, $savedCustom,
                                $channelId,
                            ]);
                        }
                    } else {
                        $channelsPending++;
                    }
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        (new ViewCacheService())->invalidateAll();

        return [
            'groups_restored' => $groupsRestored,
            'channels_linked' => $channelsLinked,
            'channels_pending' => $channelsPending,
        ];
    }
}
