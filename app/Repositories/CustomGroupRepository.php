<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;
use InvalidArgumentException;
use RuntimeException;

final class CustomGroupRepository extends BaseRepository
{
    protected string $table = 'm3u_groups';

    public const ALLOWED_CATEGORIES = [
        'Live TV',
        'Sport',
        'Animatie',
        'TV Series',
        'Film',
        'Muziek',
        'Adult',
    ];

    public static function contentTypeForCategory(string $category): string
    {
        return match ($category) {
            'Film' => 'movie',
            'TV Series' => 'series',
            default => 'live',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function createCustomGroup(string $name, string $category): array
    {
        $cleanName = trim($name);
        if ($cleanName === '') {
            throw new InvalidArgumentException('Groepsnaam mag niet leeg zijn.');
        }

        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            throw new InvalidArgumentException('Ongeldige categorie.');
        }

        $contentType = self::contentTypeForCategory($category);
        $originalName = 'custom_' . str_replace('.', '', uniqid('', true));

        $maxOrder = (int) $this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM m3u_groups')->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO m3u_groups
                (original_name, custom_name, is_enabled, sort_order, import_order, content_type, category, category_locked, is_custom, source_group_id, sync_diff_json)
             VALUES (?, ?, 1, ?, 0, ?, ?, 1, 1, NULL, NULL)'
        );
        $stmt->execute([$originalName, $cleanName, $maxOrder + 1, $contentType, $category]);

        $id = (int) $this->pdo->lastInsertId();
        return $this->findById($id) ?? ['id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    public function convertProviderGroup(int $sourceGroupId): array
    {
        $source = $this->findById($sourceGroupId);
        if ($source === null) {
            throw new RuntimeException('Brongroep niet gevonden.');
        }
        if ((int) ($source['is_custom'] ?? 0) === 1) {
            throw new InvalidArgumentException('Kan geen custom groep opnieuw converteren.');
        }

        $displayName = trim((string) ($source['custom_name'] ?? ''));
        if ($displayName === '') {
            $displayName = (string) ($source['original_name'] ?? 'Custom Groep');
        }
        $category = (string) ($source['category'] ?? 'Live TV');
        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            $category = 'Live TV';
        }
        $contentType = (string) ($source['content_type'] ?? self::contentTypeForCategory($category));
        $originalName = 'custom_' . str_replace('.', '', uniqid('', true));

        $this->pdo->beginTransaction();
        try {
            $maxOrder = (int) $this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM m3u_groups')->fetchColumn();

            $insertGroup = $this->pdo->prepare(
                'INSERT INTO m3u_groups
                    (original_name, custom_name, is_enabled, sort_order, import_order, content_type, category, category_locked, is_custom, source_group_id, sync_diff_json)
                 VALUES (?, ?, 1, ?, 0, ?, ?, 1, 1, ?, NULL)'
            );
            $insertGroup->execute([$originalName, $displayName, $maxOrder + 1, $contentType, $category, $sourceGroupId]);
            $customGroupId = (int) $this->pdo->lastInsertId();

            $chStmt = $this->pdo->prepare(
                'SELECT id FROM channels
                 WHERE group_id = ?
                 ORDER BY is_enabled DESC, sort_order ASC, (channel_number IS NULL) ASC, channel_number ASC, id ASC'
            );
            $chStmt->execute([$sourceGroupId]);
            $channelIds = $chStmt->fetchAll(\PDO::FETCH_COLUMN);

            if ($channelIds !== []) {
                $insertCh = $this->pdo->prepare(
                    'INSERT IGNORE INTO custom_group_channels (custom_group_id, channel_id, sort_order, is_ignored)
                     VALUES (?, ?, ?, 0)'
                );
                $order = 1;
                foreach ($channelIds as $cid) {
                    $insertCh->execute([$customGroupId, (int) $cid, $order]);
                    $order++;
                }
            }

            $disableSource = $this->pdo->prepare('UPDATE m3u_groups SET is_enabled = 0 WHERE id = ?');
            $disableSource->execute([$sourceGroupId]);

            $this->pdo->commit();

            return $this->findById($customGroupId) ?? ['id' => $customGroupId];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function deleteCustomGroup(int $customGroupId, bool $restoreSource = true): void
    {
        $group = $this->findById($customGroupId);
        if ($group === null || (int) ($group['is_custom'] ?? 0) !== 1) {
            throw new InvalidArgumentException('Custom groep niet gevonden.');
        }

        $sourceGroupId = $group['source_group_id'] !== null ? (int) $group['source_group_id'] : null;

        $this->pdo->beginTransaction();
        try {
            $delStmt = $this->pdo->prepare('DELETE FROM m3u_groups WHERE id = ? AND is_custom = 1');
            $delStmt->execute([$customGroupId]);

            if ($restoreSource && $sourceGroupId !== null && $sourceGroupId > 0) {
                $restoreStmt = $this->pdo->prepare('UPDATE m3u_groups SET is_enabled = 1 WHERE id = ?');
                $restoreStmt->execute([$sourceGroupId]);
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
     * @param array<int, int> $channelIds
     */
    public function addChannels(int $customGroupId, array $channelIds): int
    {
        $group = $this->findById($customGroupId);
        if ($group === null || (int) ($group['is_custom'] ?? 0) !== 1) {
            throw new InvalidArgumentException('Doelgroep is geen geldige custom groep.');
        }

        $targetContentType = (string) ($group['content_type'] ?? 'live');
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $channelIds), static fn(int $id): bool => $id > 0)));
        if ($cleanIds === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($cleanIds), '?'));
        $checkStmt = $this->pdo->prepare(
            "SELECT c.id, c.content_type AS channel_ct, g.content_type AS group_ct
             FROM channels c
             INNER JOIN m3u_groups g ON g.id = c.group_id
             WHERE c.id IN ({$placeholders})"
        );
        $checkStmt->execute($cleanIds);
        $rows = $checkStmt->fetchAll();

        $validIds = [];
        foreach ($rows as $row) {
            $chCt = (string) ($row['channel_ct'] ?? 'live');
            $grpCt = (string) ($row['group_ct'] ?? 'live');
            if ($chCt !== $targetContentType || $grpCt !== $targetContentType) {
                throw new InvalidArgumentException(
                    "Kanaal #{$row['id']} heeft een incompatibel content-type ({$chCt}) voor doelgroep ({$targetContentType})."
                );
            }
            $validIds[] = (int) $row['id'];
        }

        if ($validIds === []) {
            return 0;
        }

        $maxOrderStmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM custom_group_channels WHERE custom_group_id = ?');
        $maxOrderStmt->execute([$customGroupId]);
        $nextOrder = (int) $maxOrderStmt->fetchColumn() + 1;

        $insertStmt = $this->pdo->prepare(
            'INSERT IGNORE INTO custom_group_channels (custom_group_id, channel_id, sort_order, is_ignored)
             VALUES (?, ?, ?, 0)'
        );

        $added = 0;
        foreach ($validIds as $cid) {
            $insertStmt->execute([$customGroupId, $cid, $nextOrder]);
            if ($insertStmt->rowCount() > 0) {
                $added++;
                $nextOrder++;
            }
        }

        if ($added > 0) {
            $this->normalizeSortOrder($customGroupId);
        }

        return $added;
    }

    /**
     * @param array<int, int> $channelIds
     */
    public function removeChannels(int $customGroupId, array $channelIds): void
    {
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $channelIds), static fn(int $id): bool => $id > 0)));
        if ($cleanIds === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($cleanIds), '?'));
        $stmt = $this->pdo->prepare(
            "DELETE FROM custom_group_channels
             WHERE custom_group_id = ? AND channel_id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$customGroupId], $cleanIds));
        $this->normalizeSortOrder($customGroupId);
    }

    /**
     * @param array<int, int> $orderedChannelIds
     */
    public function reorderChannels(int $customGroupId, array $orderedChannelIds): void
    {
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $orderedChannelIds), static fn(int $id): bool => $id > 0)));
        if ($cleanIds === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE custom_group_channels SET sort_order = ? WHERE custom_group_id = ? AND channel_id = ?'
        );
        $order = 1;
        foreach ($cleanIds as $cid) {
            $stmt->execute([$order, $customGroupId, $cid]);
            $order++;
        }
    }

    /**
     * @return array<int, int>
     */
    public function getChannelIdsInCustomGroup(int $customGroupId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT channel_id FROM custom_group_channels
             WHERE custom_group_id = ? AND is_ignored = 0
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$customGroupId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function normalizeSortOrder(int $customGroupId): void
    {
        if ($customGroupId <= 0) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id FROM custom_group_channels
             WHERE custom_group_id = ? AND is_ignored = 0
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([$customGroupId]);
        $rowIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if ($rowIds === []) {
            return;
        }

        $upd = $this->pdo->prepare('UPDATE custom_group_channels SET sort_order = ? WHERE id = ?');
        $order = 1;
        foreach ($rowIds as $rowId) {
            $upd->execute([$order++, (int) $rowId]);
        }
    }

    public function reorderChannelInCustomGroup(int $customGroupId, int $channelId, int $newPosition): void
    {
        if ($customGroupId <= 0 || $channelId <= 0) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT channel_id FROM custom_group_channels
                 WHERE custom_group_id = ? AND is_ignored = 0
                 ORDER BY sort_order ASC, id ASC'
            );
            $stmt->execute([$customGroupId]);
            $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

            $currentIdx = array_search($channelId, $ids, true);
            if ($currentIdx === false) {
                $this->pdo->rollBack();
                return;
            }

            array_splice($ids, $currentIdx, 1);
            $targetIdx = max(0, min(count($ids), $newPosition - 1));
            array_splice($ids, $targetIdx, 0, [$channelId]);

            $upd = $this->pdo->prepare(
                'UPDATE custom_group_channels SET sort_order = ? WHERE custom_group_id = ? AND channel_id = ?'
            );
            $order = 1;
            foreach ($ids as $cid) {
                $upd->execute([$order++, $customGroupId, $cid]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function reorderCustomGroupBySort(int $customGroupId, string $sortBy): void
    {
        if ($customGroupId <= 0) {
            return;
        }

        $orderSql = match ($sortBy) {
            'name', 'name_asc' => "COALESCE(NULLIF(c.custom_name, ''), c.tvg_name) ASC, c.id ASC",
            'name_desc' => "COALESCE(NULLIF(c.custom_name, ''), c.tvg_name) DESC, c.id DESC",
            'number_desc' => 'cgc.sort_order DESC, cgc.id DESC',
            default => 'cgc.sort_order ASC, cgc.id ASC',
        };

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT cgc.id FROM custom_group_channels cgc
                 INNER JOIN channels c ON c.id = cgc.channel_id
                 WHERE cgc.custom_group_id = ? AND cgc.is_ignored = 0
                 ORDER BY c.is_enabled DESC, {$orderSql}"
            );
            $stmt->execute([$customGroupId]);
            $rowIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $upd = $this->pdo->prepare('UPDATE custom_group_channels SET sort_order = ? WHERE id = ?');
            $order = 1;
            foreach ($rowIds as $rowId) {
                $upd->execute([$order++, (int) $rowId]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
