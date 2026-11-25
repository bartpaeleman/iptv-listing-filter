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
                'INSERT INTO custom_group_channels
                 (custom_group_id, channel_id, sort_order, is_ignored, channel_tvg_name, channel_tvg_id, source_group_name, saved_epg_channel_id, saved_custom_name)
                 SELECT ?, c.id, ?, 0, c.tvg_name, c.tvg_id, g.original_name, c.epg_channel_id, c.custom_name
                 FROM channels c
                 LEFT JOIN m3u_groups g ON g.id = c.group_id
                 WHERE c.id = ?
                 ON DUPLICATE KEY UPDATE is_ignored = 0'
            );
            foreach ($cleanAdd as $cid) {
                $ins->execute([$customGroupId, $nextOrder, $cid]);
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

    public function captureSnapshotsBeforeSync(): void
    {
        $this->pdo->exec(
            'UPDATE custom_group_channels cgc
             INNER JOIN channels c ON c.id = cgc.channel_id
             LEFT JOIN m3u_groups g ON g.id = c.group_id
             SET cgc.channel_tvg_name = c.tvg_name,
                 cgc.channel_tvg_id = c.tvg_id,
                 cgc.source_group_name = g.original_name,
                 cgc.saved_epg_channel_id = CASE WHEN c.epg_channel_id IS NOT NULL AND c.epg_channel_id <> "" THEN c.epg_channel_id ELSE cgc.saved_epg_channel_id END,
                 cgc.saved_custom_name = CASE WHEN c.custom_name IS NOT NULL AND c.custom_name <> "" THEN c.custom_name ELSE cgc.saved_custom_name END
             WHERE cgc.channel_id IS NOT NULL'
        );
    }

    /**
     * @return array{relinked: int, epg_restored: int, name_restored: int}
     */
    public function relinkCustomGroupChannelsAfterSync(): array
    {
        $orphans = $this->pdo->query(
            'SELECT id, custom_group_id, channel_tvg_name, source_group_name, saved_epg_channel_id, saved_custom_name
             FROM custom_group_channels
             WHERE channel_id IS NULL AND channel_tvg_name IS NOT NULL AND channel_tvg_name <> ""'
        )->fetchAll();

        if ($orphans === []) {
            return ['relinked' => 0, 'epg_restored' => 0, 'name_restored' => 0];
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
             INNER JOIN m3u_groups g ON g.id = c.group_id
             WHERE c.tvg_name = ?
             ORDER BY (g.category = \'Live TV\') DESC, c.id ASC
             LIMIT 1'
        );
        $relinkStmt = $this->pdo->prepare('UPDATE custom_group_channels SET channel_id = ? WHERE id = ?');
        $restoreEpgStmt = $this->pdo->prepare('UPDATE channels SET epg_channel_id = ? WHERE id = ? AND (epg_channel_id IS NULL OR epg_channel_id = "")');
        $restoreNameStmt = $this->pdo->prepare('UPDATE channels SET custom_name = ? WHERE id = ? AND (custom_name IS NULL OR custom_name = "")');

        $relinked = 0;
        $epgRestored = 0;
        $nameRestored = 0;

        foreach ($orphans as $row) {
            $tvgName = (string) $row['channel_tvg_name'];
            $srcGroup = (string) ($row['source_group_name'] ?? '');
            $match = false;

            if ($srcGroup !== '') {
                $exactStmt->execute([$tvgName, $srcGroup]);
                $match = $exactStmt->fetch();
            }
            if ($match === false) {
                $fallbackStmt->execute([$tvgName]);
                $match = $fallbackStmt->fetch();
            }
            if ($match === false) {
                continue;
            }

            $newChannelId = (int) $match['id'];
            try {
                $relinkStmt->execute([$newChannelId, (int) $row['id']]);
                $relinked++;
            } catch (\PDOException) {
                continue;
            }

            $savedEpg = (string) ($row['saved_epg_channel_id'] ?? '');
            if ($savedEpg !== '' && empty($match['epg_channel_id'])) {
                $restoreEpgStmt->execute([$savedEpg, $newChannelId]);
                if ($restoreEpgStmt->rowCount() > 0) {
                    $epgRestored++;
                }
            }

            $savedName = (string) ($row['saved_custom_name'] ?? '');
            if ($savedName !== '' && empty($match['custom_name'])) {
                $restoreNameStmt->execute([$savedName, $newChannelId]);
                if ($restoreNameStmt->rowCount() > 0) {
                    $nameRestored++;
                }
            }
        }

        return [
            'relinked' => $relinked,
            'epg_restored' => $epgRestored,
            'name_restored' => $nameRestored,
        ];
    }
}
