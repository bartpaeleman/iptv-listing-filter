<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpClient;
use App\Repositories\SettingsRepository;
use App\Security\CryptoService;
use PDO;
use RuntimeException;

final class XtreamApiService
{
    private const VALID_TYPES = ['live', 'movie', 'series'];
    private const BATCH_SIZE = 500;

    private PDO $pdo;
    private SettingsRepository $settings;
    private CryptoService $crypto;
    private SyncProgressService $progress;
    private HttpClient $http;

    public function __construct(
        ?PDO $pdo = null,
        ?SettingsRepository $settings = null,
        ?CryptoService $crypto = null,
        ?SyncProgressService $progress = null,
        ?HttpClient $http = null
    ) {
        $this->pdo = $pdo ?? Database::getInstance();
        $this->crypto = $crypto ?? new CryptoService();
        $this->settings = $settings ?? new SettingsRepository($this->pdo, $this->crypto);
        $this->progress = $progress ?? new SyncProgressService();
        $this->http = $http ?? new HttpClient();
    }

    /**
     * @param array<int, string> $allowedTypes
     * @return array{imported: int, groups: int, kept_epg_ids: array<int, string>}
     */
    public function sync(array $allowedTypes = ['live', 'movie', 'series']): array
    {
        $types = array_values(array_filter(
            $allowedTypes,
            static fn (string $t): bool => in_array($t, self::VALID_TYPES, true)
        ));
        if ($types === []) {
            $types = self::VALID_TYPES;
        }

        $customGroupSync = new CustomGroupSyncService($this->pdo);
        $customGroupSync->captureSnapshotsBeforeSync();

        $host = rtrim(trim((string) $this->settings->get('xtream_host', '')), '/');
        $username = trim((string) $this->settings->get('xtream_username', ''));
        $password = (string) $this->settings->get('xtream_password', '');

        if ($host === '' || $username === '' || $password === '') {
            throw new RuntimeException('Xtream API-gegevens (host, gebruikersnaam, wachtwoord) zijn onvolledig.');
        }

        $baseUrl = $host . '/player_api.php?username=' . rawurlencode($username) . '&password=' . rawurlencode($password);
        $importStartTime = date('Y-m-d H:i:s');

        $totalImported = 0;
        $totalGroups = 0;
        $keptEpgIdsMap = [];

        $typeCount = count($types);
        foreach ($types as $typeIdx => $type) {
            $pctBase = (int) round(($typeIdx / $typeCount) * 80) + 10;
            $this->progress->update('running', 'xtream_' . $type, $pctBase, "Xtream {$type} categorieën ophalen...");

            $catAction = match ($type) {
                'live' => 'get_live_categories',
                'movie' => 'get_vod_categories',
                'series' => 'get_series_categories',
            };

            $categories = $this->fetchJson($baseUrl . '&action=' . $catAction);
            $categoryIdToGroupId = $this->upsertCategories($categories, $type);
            $totalGroups += count($categoryIdToGroupId);

            $this->progress->update('running', 'xtream_' . $type, $pctBase + 10, "Xtream {$type} streams ophalen...");

            $streamAction = match ($type) {
                'live' => 'get_live_streams',
                'movie' => 'get_vod_streams',
                'series' => 'get_series',
            };

            $streams = $this->fetchJson($baseUrl . '&action=' . $streamAction);
            $fallbackGroupId = $this->ensureFallbackGroup($type);

            $batch = [];
            foreach ($streams as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $streamId = (string) ($item['stream_id'] ?? $item['series_id'] ?? '');
                if ($streamId === '') {
                    continue;
                }

                $name = trim((string) ($item['name'] ?? ''));
                $logo = trim((string) ($item['stream_icon'] ?? $item['cover'] ?? ''));
                $epgId = trim((string) ($item['epg_channel_id'] ?? ''));
                $catId = (string) ($item['category_id'] ?? '');
                $groupId = $categoryIdToGroupId[$catId] ?? $fallbackGroupId;

                $streamUrl = match ($type) {
                    'live' => "{$host}/live/{$username}/{$password}/{$streamId}.ts",
                    'movie' => "{$host}/movie/{$username}/{$password}/{$streamId}." . ($item['container_extension'] ?? 'mp4'),
                    'series' => "{$host}/series/{$username}/{$password}/{$streamId}",
                };

                $streamHash = hash('sha256', $type . ':' . $streamId);

                if ($epgId !== '') {
                    $keptEpgIdsMap[strtolower($epgId)] = true;
                }

                $batch[] = [
                    'stream_hash' => $streamHash,
                    'tvg_id' => $epgId !== '' ? $epgId : null,
                    'tvg_name' => $name,
                    'tvg_logo' => $logo !== '' ? $logo : null,
                    'group_id' => $groupId,
                    'content_type' => $type,
                    'stream_url' => $streamUrl,
                    'last_seen_at' => $importStartTime,
                ];

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->upsertChannelBatch($batch);
                    $totalImported += count($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $this->upsertChannelBatch($batch);
                $totalImported += count($batch);
            }
        }

        $this->cleanupStaleRecords($types, $importStartTime);
        $customGroupSync->relinkCustomGroupChannelsAfterSync();
        $customGroupSync->evaluateAllConvertedGroups();
        $this->settings->set('last_m3u_sync', gmdate('Y-m-d H:i:s'), false);

        return [
            'imported' => $totalImported,
            'groups' => $totalGroups,
            'kept_epg_ids' => array_keys($keptEpgIdsMap),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function fetchJson(string $url): array
    {
        $body = $this->http->get($url, 60);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * @param array<int, mixed> $categories
     * @return array<string, int>
     */
    private function upsertCategories(array $categories, string $type): array
    {
        $map = [];
        $stmt = $this->pdo->prepare(
            'INSERT INTO m3u_groups (original_name, content_type, import_order)
             VALUES (:orig, :ct, :ord)
             ON DUPLICATE KEY UPDATE
                content_type = VALUES(content_type),
                import_order = VALUES(import_order)'
        );
        $selectStmt = $this->pdo->prepare(
            'SELECT id FROM m3u_groups WHERE original_name = :orig LIMIT 1'
        );

        $this->pdo->beginTransaction();
        try {
            $idx = 0;
            foreach ($categories as $cat) {
                if (!is_array($cat)) {
                    continue;
                }
                $catId = (string) ($cat['category_id'] ?? '');
                $catName = trim((string) ($cat['category_name'] ?? ''));
                if ($catId === '' || $catName === '') {
                    continue;
                }

                $stmt->execute([
                    ':orig' => $catName,
                    ':ct' => $type,
                    ':ord' => $idx++,
                ]);

                $selectStmt->execute([':orig' => $catName]);
                $groupId = (int) $selectStmt->fetchColumn();
                if ($groupId > 0) {
                    $map[$catId] = $groupId;
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $map;
    }

    private function ensureFallbackGroup(string $type): int
    {
        $name = 'Overig (' . $type . ')';
        $stmt = $this->pdo->prepare(
            'INSERT INTO m3u_groups (original_name, content_type, import_order)
             VALUES (:orig, :ct, 999999)
             ON DUPLICATE KEY UPDATE content_type = VALUES(content_type)'
        );
        $stmt->execute([':orig' => $name, ':ct' => $type]);

        $sel = $this->pdo->prepare('SELECT id FROM m3u_groups WHERE original_name = :orig LIMIT 1');
        $sel->execute([':orig' => $name]);
        return (int) $sel->fetchColumn();
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    private function upsertChannelBatch(array $batch): void
    {
        if ($batch === []) {
            return;
        }

        $placeholders = [];
        $params = [];
        foreach ($batch as $i => $row) {
            $placeholders[] = "(:sh_{$i}, :tid_{$i}, :tname_{$i}, :tlogo_{$i}, :gid_{$i}, :ct_{$i}, :url_{$i}, :seen_{$i})";
            $params[":sh_{$i}"] = $row['stream_hash'];
            $params[":tid_{$i}"] = mb_substr((string) $row['tvg_id'], 0, 255, 'UTF-8');
            $params[":tname_{$i}"] = mb_substr((string) $row['tvg_name'], 0, 255, 'UTF-8');
            $params[":tlogo_{$i}"] = $row['tvg_logo'];
            $params[":gid_{$i}"] = $row['group_id'];
            $params[":ct_{$i}"] = $row['content_type'];
            $params[":url_{$i}"] = $row['stream_url'];
            $params[":seen_{$i}"] = $row['last_seen_at'];
        }

        $sql = 'INSERT INTO channels (stream_hash, tvg_id, tvg_name, tvg_logo, group_id, content_type, stream_url, last_seen_at)
                VALUES ' . implode(', ', $placeholders) . '
                ON DUPLICATE KEY UPDATE
                    tvg_id = VALUES(tvg_id),
                    tvg_name = VALUES(tvg_name),
                    tvg_logo = VALUES(tvg_logo),
                    group_id = VALUES(group_id),
                    content_type = VALUES(content_type),
                    stream_url = VALUES(stream_url),
                    last_seen_at = VALUES(last_seen_at)';

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<int, string> $types
     */
    private function cleanupStaleRecords(array $types, string $importStartTime): void
    {
        if ($types === []) {
            return;
        }

        $ctPlaceholders = [];
        $params = [':cutoff' => $importStartTime];
        foreach ($types as $idx => $type) {
            $key = ":ct_{$idx}";
            $ctPlaceholders[] = $key;
            $params[$key] = $type;
        }
        $inClause = implode(', ', $ctPlaceholders);

        $delChannels = $this->pdo->prepare(
            "DELETE FROM channels
             WHERE content_type IN ({$inClause})
               AND (last_seen_at IS NULL OR last_seen_at < :cutoff)"
        );
        $delChannels->execute($params);

        $grpPlaceholders = [];
        $grpParams = [];
        foreach ($types as $idx => $type) {
            $key = ":gct_{$idx}";
            $grpPlaceholders[] = $key;
            $grpParams[$key] = $type;
        }
        $grpInClause = implode(', ', $grpPlaceholders);

        $delGroups = $this->pdo->prepare(
            "DELETE g FROM m3u_groups g
             LEFT JOIN channels c ON c.group_id = g.id
             WHERE g.content_type IN ({$grpInClause})
               AND c.id IS NULL"
        );
        $delGroups->execute($grpParams);
    }
}
