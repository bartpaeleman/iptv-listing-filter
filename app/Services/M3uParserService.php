<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpClient;
use App\Repositories\SettingsRepository;
use PDO;
use RuntimeException;

final class M3uParserService
{
    private PDO $pdo;
    private SettingsRepository $settings;
    private HttpClient $http;
    private EpgMatcherService $matcher;

    public function __construct(
        ?PDO $pdo = null,
        ?SettingsRepository $settings = null,
        ?HttpClient $http = null,
        ?EpgMatcherService $matcher = null
    ) {
        $this->pdo = $pdo ?? Database::getInstance();
        $this->settings = $settings ?? new SettingsRepository($this->pdo);
        $this->http = $http ?? new HttpClient();
        $this->matcher = $matcher ?? new EpgMatcherService($this->pdo);
    }

    /**
     * @return array{groups_added: int, channels_added: int, channels_updated: int, auto_matched: int}
     */
    public function sync(?string $overrideUrl = null, string $cacheMode = 'auto', ?string $cacheFilename = null): array
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        \App\Core\SchemaMigrator::ensureSchemaUpToDate($this->pdo);

        $cache = new FeedCacheService($this->settings);
        $cacheInfo = $cache->getCacheInfo('m3u');

        if ($cacheMode === 'cache_only') {
            if ($cacheFilename !== null && $cacheFilename !== '') {
                $sourceFile = $cache->resolveFilePath($cacheFilename);
            } elseif ($cacheInfo['exists']) {
                $sourceFile = $cacheInfo['path'];
            } else {
                throw new RuntimeException('Geen lokaal M3U cachebestand gevonden. Voer eerst een nieuwe download uit.');
            }
        } elseif ($cacheMode === 'auto' && $cacheInfo['is_fresh'] && $overrideUrl === null) {
            $sourceFile = $cacheInfo['path'];
        } else {
            $url = $overrideUrl ?? (string) $this->settings->get('m3u_source_url', '');
            if ($url === '') {
                throw new RuntimeException('Geen M3U bron-URL geconfigureerd.');
            }
            $tmpFile = dirname(__DIR__, 2) . '/storage/cache/m3u_source.tmp';
            $this->http->downloadToFile($url, $tmpFile, 180);
            $hostLabel = (string) (parse_url($url, PHP_URL_HOST) ?: 'provider');
            $sourceFile = $cache->storeFromTempFile('m3u', $tmpFile, $hostLabel);
        }

        $progress = new SyncProgressService();
        $totalBytes = max(1, (int) (@filesize($sourceFile) ?: 1));
        $progress->update('running', 'parse_m3u', 15, 'M3U bestand parsen...', ['inserted' => 0, 'updated' => 0, 'total' => 0], 'Start M3U import');

        $handle = fopen($sourceFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Kan M3U bestand niet openen.');
        }

        $groupCache = $this->loadGroupCache();
        $epgMap = $this->matcher->loadLookupMap();

        $groupsAdded = 0;
        $channelsAdded = 0;
        $channelsUpdated = 0;
        $channelsUnchanged = 0;
        $channelsDeleted = 0;
        $autoMatched = 0;
        $now = gmdate('Y-m-d H:i:s');
        $touchIds = [];

        $insertGroupStmt = $this->pdo->prepare('INSERT INTO m3u_groups (original_name, content_type, is_enabled, sort_order) VALUES (:name, :ct, 1, :sort)');
        $selectChannelStmt = $this->pdo->prepare(
            'SELECT id, tvg_id, tvg_name, tvg_logo, group_id, stream_url, epg_channel_id, epg_match_type FROM channels WHERE stream_hash = :hash LIMIT 1'
        );
        $insertChannelStmt = $this->pdo->prepare(
            'INSERT INTO channels (stream_hash, tvg_id, tvg_name, tvg_logo, group_id, content_type, stream_url, epg_channel_id, epg_match_type, is_enabled, sort_order, last_seen_at)
             VALUES (:hash, :tvg_id, :tvg_name, :tvg_logo, :group_id, :ct, :stream_url, :epg_id, :match_type, 1, :sort, :seen)
             ON DUPLICATE KEY UPDATE
                tvg_id = VALUES(tvg_id),
                tvg_name = VALUES(tvg_name),
                tvg_logo = VALUES(tvg_logo),
                group_id = VALUES(group_id),
                content_type = VALUES(content_type),
                stream_url = VALUES(stream_url),
                last_seen_at = VALUES(last_seen_at)'
        );
        $updateChannelStmt = $this->pdo->prepare(
            'UPDATE channels SET tvg_id = :tvg_id, tvg_name = :tvg_name, tvg_logo = :tvg_logo, group_id = :group_id, content_type = :ct, stream_url = :stream_url,
             epg_channel_id = :epg_id, epg_match_type = :match_type, last_seen_at = :seen WHERE id = :id'
        );

        $this->pdo->beginTransaction();
        try {
            $pendingMeta = null;
            $nextSort = 1;

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#EXTM3U')) {
                    continue;
                }
                if (str_starts_with($line, '#EXTINF:')) {
                    $pendingMeta = $this->parseExtInf($line);
                    continue;
                }
                if (str_starts_with($line, '#') || $pendingMeta === null) {
                    continue;
                }

                $streamUrl = $line;
                $groupName = $pendingMeta['group_title'] !== '' ? $pendingMeta['group_title'] : 'Overig';
                $contentType = $this->detectContentType($streamUrl, $groupName);

                if (!isset($groupCache[$groupName])) {
                    $insertGroupStmt->execute([':name' => $groupName, ':ct' => $contentType, ':sort' => count($groupCache) + 1]);
                    $groupCache[$groupName] = (int) $this->pdo->lastInsertId();
                    $groupsAdded++;
                }

                $groupId = $groupCache[$groupName];
                $streamHash = hash('sha256', $streamUrl);
                $seenHashes[$streamHash] = true;
                $existing = $existingMap[$streamHash] ?? null;

                if ($existing !== null) {
                    $epgChannelId = $existing['epg_channel_id'];
                    $matchType = (string) $existing['epg_match_type'];

                    if ($matchType === 'none' || $epgChannelId === null || $epgChannelId === '') {
                        $matched = $this->matcher->findMatch($pendingMeta['tvg_id'], $pendingMeta['tvg_name'], $epgMap);
                        if ($matched !== null) {
                            $epgChannelId = $matched;
                            $matchType = 'auto';
                            $autoMatched++;
                        }
                    }

                    $updateChannelStmt->execute([
                        ':tvg_id' => $pendingMeta['tvg_id'],
                        ':tvg_name' => $pendingMeta['tvg_name'],
                        ':tvg_logo' => $pendingMeta['tvg_logo'],
                        ':group_id' => $groupId,
                        ':ct' => $contentType,
                        ':stream_url' => $streamUrl,
                        ':epg_id' => $epgChannelId,
                        ':match_type' => $matchType,
                        ':seen' => $now,
                        ':id' => (int) $existing['id'],
                    ]);
                    $channelsUpdated++;
                } else {
                    $matched = $this->matcher->findMatch($pendingMeta['tvg_id'], $pendingMeta['tvg_name'], $epgMap);
                    $matchType = $matched !== null ? 'auto' : 'none';
                    if ($matched !== null) {
                        $autoMatched++;
                    }

                    $insertChannelStmt->execute([
                        ':hash' => $streamHash,
                        ':tvg_id' => $pendingMeta['tvg_id'],
                        ':tvg_name' => $pendingMeta['tvg_name'],
                        ':tvg_logo' => $pendingMeta['tvg_logo'],
                        ':group_id' => $groupId,
                        ':ct' => $contentType,
                        ':stream_url' => $streamUrl,
                        ':epg_id' => $matched,
                        ':match_type' => $matchType,
                        ':sort' => $nextSort++,
                        ':seen' => $now,
                    ]);
                    $channelsAdded++;
                }

                $pendingMeta = null;
                $processed = $channelsAdded + $channelsUpdated;
                if ($processed > 0 && $processed % 500 === 0) {
                    $this->pdo->commit();
                    $this->pdo->beginTransaction();
                    $pos = ftell($handle) ?: 0;
                    $pct = min(85, 15 + (int) floor(($pos / $totalBytes) * 70));
                    $progress->update(
                        'running',
                        'parse_m3u',
                        $pct,
                        "M3U parsen: {$processed} kanalen verwerkt ({$channelsAdded} nieuw, {$channelsUpdated} bijgewerkt)...",
                        ['inserted' => $channelsAdded, 'updated' => $channelsUpdated, 'total' => $processed],
                        "M3U: {$processed} kanalen verwerkt"
                    );
                }
            }

            if ($touchIds !== []) {
                $placeholders = implode(',', array_fill(0, count($touchIds), '?'));
                $touchStmt = $this->pdo->prepare("UPDATE channels SET last_seen_at = ? WHERE id IN ({$placeholders})");
                $touchStmt->execute(array_merge([$now], $touchIds));
                $touchIds = [];
            }

            $delStmt = $this->pdo->prepare('DELETE FROM channels WHERE last_seen_at IS NULL OR last_seen_at < :seen');
            $delStmt->execute([':seen' => $now]);
            $channelsDeleted = $delStmt->rowCount();

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            fclose($handle);
            throw $e;
        }

        fclose($handle);
        $cache->updateItemCount($sourceFile, $channelsAdded + $channelsUpdated + $channelsUnchanged);
        $this->settings->set('last_m3u_sync', $now, false);
        \App\Core\SchemaMigrator::ensureSchemaUpToDate($this->pdo);
        (new GroupCategoryService())->classifyUnlockedGroups($this->pdo);
        (new ViewCacheService())->invalidateAll();

        return [
            'groups_added' => $groupsAdded,
            'channels_added' => $channelsAdded,
            'channels_updated' => $channelsUpdated,
            'channels_deleted' => $channelsDeleted,
            'channels_unchanged' => $channelsUnchanged,
            'auto_matched' => $autoMatched,
        ];
    }

    /**
     * @return array{tvg_id: string, tvg_name: string, tvg_logo: string, group_title: string}
     */
    private function parseExtInf(string $line): array
    {
        $tvgId = preg_match('/tvg-id="([^"]*)"/i', $line, $m) ? trim($m[1]) : '';
        $tvgName = preg_match('/tvg-name="([^"]*)"/i', $line, $m) ? trim($m[1]) : '';
        $tvgLogo = preg_match('/tvg-logo="([^"]*)"/i', $line, $m) ? trim($m[1]) : '';
        $groupTitle = preg_match('/group-title="([^"]*)"/i', $line, $m) ? trim($m[1]) : '';

        $commaPos = strrpos($line, ',');
        $displayName = $commaPos !== false ? trim(substr($line, $commaPos + 1)) : '';
        if ($tvgName === '' && $displayName !== '') {
            $tvgName = $displayName;
        }

        return [
            'tvg_id' => $this->sanitizeString($tvgId, 255) ?? '',
            'tvg_name' => $this->sanitizeString($tvgName, 255) ?? '',
            'tvg_logo' => $this->sanitizeText($tvgLogo) ?? '',
            'group_title' => $this->sanitizeString($groupTitle, 255) ?? '',
        ];
    }

    private function sanitizeString(?string $value, int $maxLength = 250): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = trim((string) @iconv('UTF-8', 'UTF-8//IGNORE', $value));
        if ($clean === '') {
            return null;
        }
        return mb_substr($clean, 0, $maxLength, 'UTF-8');
    }

    private function sanitizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = trim((string) @iconv('UTF-8', 'UTF-8//IGNORE', $value));
        if ($clean === '' || strlen($clean) > 16000000) {
            return null;
        }
        return $clean;
    }

    /**
     * @return array<string, int>
     */
    private function loadGroupCache(): array
    {
        $map = [];
        foreach ($this->pdo->query('SELECT id, original_name FROM m3u_groups')->fetchAll() as $row) {
            $map[(string) $row['original_name']] = (int) $row['id'];
        }
        return $map;
    }

    private function detectContentType(string $url, string $groupTitle): string
    {
        $lowerUrl = strtolower($url);
        if (str_contains($lowerUrl, '/movie/')) {
            return 'movie';
        }
        if (str_contains($lowerUrl, '/series/')) {
            return 'series';
        }
        return 'live';
    }
}
