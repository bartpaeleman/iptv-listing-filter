<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\SettingsRepository;
use App\Security\CryptoService;
use PDO;

final class XtreamServerService
{
    private PDO $pdo;
    private SettingsRepository $settings;
    private CryptoService $crypto;

    public function __construct(
        ?PDO $pdo = null,
        ?SettingsRepository $settings = null,
        ?CryptoService $crypto = null
    ) {
        $this->pdo = $pdo ?? Database::getInstance();
        $this->settings = $settings ?? new SettingsRepository($this->pdo);
        $this->crypto = $crypto ?? new CryptoService();
    }

    public function authenticate(string $user, string $pass): bool
    {
        if ($user === '' || $pass === '') {
            return false;
        }

        if ($this->settings->get('xtream_out_enabled', '0') !== '1') {
            return false;
        }

        $expectedUser = (string) $this->settings->get('xtream_out_username', '');
        $expectedPass = (string) $this->settings->get('xtream_out_password', '');

        if ($expectedUser === '' || $expectedPass === '') {
            return false;
        }

        return hash_equals($expectedUser, $user) && hash_equals($expectedPass, $pass);
    }

    public function buildAuthPayload(string $user, string $pass, string $host, int $port, string $protocol): array
    {
        $now = time();
        return [
            'user_info' => [
                'username' => $user,
                'password' => $pass,
                'message' => '',
                'auth' => 1,
                'status' => 'Active',
                'exp_date' => null,
                'is_trial' => '0',
                'active_cons' => '0',
                'created_at' => (string) $now,
                'max_connections' => '10',
                'allowed_output_formats' => ['m3u8', 'ts'],
            ],
            'server_info' => [
                'url' => $host,
                'port' => (string) $port,
                'https_port' => (string) $port,
                'server_protocol' => $protocol,
                'rtmp_port' => '0',
                'timezone' => date_default_timezone_get() ?: 'UTC',
                'timestamp_now' => $now,
                'time_now' => date('Y-m-d H:i:s', $now),
                'process' => true,
            ],
        ];
    }

    public function getLiveCategories(): array
    {
        $sortMode = (string) $this->settings->get('group_sort_mode', 'import');
        $groupOrder = match ($sortMode) {
            'asc' => 'original_name ASC, id ASC',
            'desc' => 'original_name DESC, id DESC',
            default => 'import_order ASC, id ASC',
        };

        $stmt = $this->pdo->query(
            "SELECT id, COALESCE(NULLIF(custom_name, ''), original_name) AS display_name
             FROM m3u_groups
             WHERE is_enabled = 1 AND COALESCE(category, 'Live TV') NOT IN ('Film', 'TV Series')
             ORDER BY {$groupOrder}"
        );

        $categories = [];
        while ($row = $stmt->fetch()) {
            $categories[] = [
                'category_id' => (string) $row['id'],
                'category_name' => (string) $row['display_name'],
                'parent_id' => 0,
            ];
        }

        return $categories;
    }

    public function getLiveStreams(?int $categoryId = null): array
    {
        $sortMode = (string) $this->settings->get('group_sort_mode', 'import');
        $groupOrder = match ($sortMode) {
            'asc' => 'g.original_name ASC, g.id ASC',
            'desc' => 'g.original_name DESC, g.id DESC',
            default => 'g.import_order ASC, g.id ASC',
        };

        $where = "g.is_enabled = 1 AND c.is_enabled = 1 AND COALESCE(g.category, 'Live TV') NOT IN ('Film', 'TV Series')";
        $params = [];
        if ($categoryId !== null && $categoryId > 0) {
            $where .= ' AND c.group_id = ?';
            $params[] = $categoryId;
        }

        $sql = "SELECT c.id, c.group_id, c.tvg_id, c.tvg_name, c.tvg_logo, c.custom_name,
                       c.channel_number, c.epg_channel_id
                FROM channels c
                INNER JOIN m3u_groups g ON g.id = c.group_id
                WHERE {$where}
                ORDER BY {$groupOrder},
                         COALESCE(c.channel_number, 999999) ASC,
                         c.sort_order ASC, c.id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $streams = [];
        $num = 1;
        while ($row = $stmt->fetch()) {
            $epgId = (string) ($row['epg_channel_id'] ?: $row['tvg_id'] ?: '');
            $name = ChannelNameCleanerService::stripQualityTags((string) ($row['custom_name'] ?: $row['tvg_name'] ?: 'Channel'));
            $streamId = (int) $row['id'];

            $streams[] = [
                'num' => $row['channel_number'] !== null ? (int) $row['channel_number'] : $num,
                'name' => $name,
                'stream_type' => 'live',
                'stream_id' => $streamId,
                'stream_icon' => (string) ($row['tvg_logo'] ?? ''),
                'epg_channel_id' => $epgId,
                'added' => '0',
                'category_id' => (string) $row['group_id'],
                'custom_sid' => '',
                'tv_archive' => 0,
                'direct_source' => '',
                'tv_archive_duration' => 0,
            ];
            $num++;
        }

        return $streams;
    }

    public function getShortEpg(int $streamId, int $limit = 4): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.epg_channel_id, c.tvg_id
             FROM channels c
             INNER JOIN m3u_groups g ON g.id = c.group_id
             WHERE c.id = ? AND g.is_enabled = 1 AND c.is_enabled = 1
             LIMIT 1"
        );
        $stmt->execute([$streamId]);
        $channel = $stmt->fetch();

        if (!$channel) {
            return ['epg_listings' => []];
        }

        $epgId = (string) ($channel['epg_channel_id'] ?: $channel['tvg_id'] ?: '');
        if ($epgId === '') {
            return ['epg_listings' => []];
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $limitVal = max(1, min(100, $limit));

        $progStmt = $this->pdo->prepare(
            "SELECT id, epg_id, start_utc, stop_utc, title, description
             FROM epg_programmes
             WHERE epg_id = ? AND stop_utc >= ?
             ORDER BY start_utc ASC
             LIMIT {$limitVal}"
        );
        $progStmt->execute([$epgId, $nowUtc]);

        $listings = [];
        while ($prog = $progStmt->fetch()) {
            $startTs = strtotime((string) $prog['start_utc'] . ' UTC') ?: 0;
            $stopTs = strtotime((string) $prog['stop_utc'] . ' UTC') ?: 0;
            $listings[] = [
                'id' => (string) $prog['id'],
                'epg_id' => (string) $prog['epg_id'],
                'title' => base64_encode((string) $prog['title']),
                'lang' => 'nl',
                'start' => date('Y-m-d H:i:s', $startTs),
                'end' => date('Y-m-d H:i:s', $stopTs),
                'description' => base64_encode((string) ($prog['description'] ?? '')),
                'channel_id' => $epgId,
                'start_timestamp' => (string) $startTs,
                'stop_timestamp' => (string) $stopTs,
            ];
        }

        return ['epg_listings' => $listings];
    }

    public function resolveStreamUrl(int $streamId): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.stream_url
             FROM channels c
             INNER JOIN m3u_groups g ON g.id = c.group_id
             WHERE c.id = ? AND g.is_enabled = 1 AND c.is_enabled = 1
             LIMIT 1"
        );
        $stmt->execute([$streamId]);
        $row = $stmt->fetch();

        return $row ? (string) $row['stream_url'] : null;
    }

    public function getVodCategories(): array
    {
        return $this->getCategoriesByGroupCategory('Film');
    }

    public function getVodStreams(?int $categoryId = null): array
    {
        $sortMode = (string) $this->settings->get('group_sort_mode', 'import');
        $groupOrder = match ($sortMode) {
            'asc' => 'g.original_name ASC, g.id ASC',
            'desc' => 'g.original_name DESC, g.id DESC',
            default => 'g.import_order ASC, g.id ASC',
        };

        $where = "g.is_enabled = 1 AND c.is_enabled = 1 AND LOWER(TRIM(g.category)) = 'film'";
        $params = [];
        if ($categoryId !== null && $categoryId > 0) {
            $where .= ' AND c.group_id = ?';
            $params[] = $categoryId;
        }

        $sql = "SELECT c.id, c.group_id, c.tvg_name, c.tvg_logo, c.custom_name, c.channel_number, c.stream_url, c.created_at
                FROM channels c
                INNER JOIN m3u_groups g ON g.id = c.group_id
                WHERE {$where}
                ORDER BY {$groupOrder},
                         COALESCE(c.channel_number, 999999) ASC,
                         c.sort_order ASC, c.id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $streams = [];
        $num = 1;
        while ($row = $stmt->fetch()) {
            $name = ChannelNameCleanerService::stripQualityTags((string) ($row['custom_name'] ?: $row['tvg_name'] ?: 'Movie'));
            $added = (string) (strtotime((string) ($row['created_at'] ?? 'now')) ?: time());
            $ext = $this->detectExtension((string) ($row['stream_url'] ?? ''), 'mp4');

            $streams[] = [
                'num' => $row['channel_number'] !== null ? (int) $row['channel_number'] : $num,
                'name' => $name,
                'stream_type' => 'movie',
                'stream_id' => (int) $row['id'],
                'stream_icon' => (string) ($row['tvg_logo'] ?? ''),
                'rating' => '',
                'rating_5based' => 0,
                'added' => $added,
                'category_id' => (string) $row['group_id'],
                'container_extension' => $ext,
                'custom_sid' => '',
                'direct_source' => '',
            ];
            $num++;
        }

        return $streams;
    }

    public function getVodInfo(int $vodId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.group_id, c.tvg_name, c.tvg_logo, c.custom_name, c.stream_url
             FROM channels c
             INNER JOIN m3u_groups g ON g.id = c.group_id
             WHERE c.id = ? AND g.is_enabled = 1 AND c.is_enabled = 1 AND LOWER(TRIM(g.category)) = 'film'
             LIMIT 1"
        );
        $stmt->execute([$vodId]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['info' => [], 'movie_data' => []];
        }

        $name = ChannelNameCleanerService::stripQualityTags((string) ($row['custom_name'] ?: $row['tvg_name'] ?: 'Movie'));
        $added = (string) time();
        $ext = $this->detectExtension((string) ($row['stream_url'] ?? ''), 'mp4');
        $logo = (string) ($row['tvg_logo'] ?? '');

        return [
            'info' => [
                'movie_image' => $logo,
                'cover_big' => $logo,
                'name' => $name,
                'plot' => '',
                'cast' => '',
                'director' => '',
                'genre' => '',
                'releasedate' => '',
                'rating' => '0',
                'rating_5based' => 0,
                'backdrop_path' => [],
                'youtube_trailer' => '',
                'duration' => '',
                'duration_secs' => 0,
            ],
            'movie_data' => [
                'stream_id' => (int) $row['id'],
                'name' => $name,
                'added' => $added,
                'category_id' => (string) $row['group_id'],
                'container_extension' => $ext,
                'custom_sid' => '',
                'direct_source' => '',
            ],
        ];
    }

    public function getSeriesCategories(): array
    {
        return $this->getCategoriesByGroupCategory('TV Series');
    }

    public function getSeries(?int $categoryId = null): array
    {
        $grouped = $this->buildSeriesGroups($categoryId);
        $result = [];
        $num = 1;
        foreach ($grouped as $s) {
            $result[] = [
                'num' => $num++,
                'name' => $s['title'],
                'series_id' => $s['series_id'],
                'cover' => $s['cover'],
                'plot' => '',
                'cast' => '',
                'director' => '',
                'genre' => '',
                'releaseDate' => '',
                'last_modified' => $s['last_modified'],
                'rating' => '0',
                'rating_5based' => 0,
                'backdrop_path' => [],
                'youtube_trailer' => '',
                'episode_run_time' => '0',
                'category_id' => (string) $s['category_id'],
            ];
        }
        return $result;
    }

    public function getSeriesInfo(int $seriesId): array
    {
        $grouped = $this->buildSeriesGroups(null);
        $target = null;
        foreach ($grouped as $s) {
            if ($s['series_id'] === $seriesId) {
                $target = $s;
                break;
            }
        }

        if ($target === null) {
            return ['seasons' => [], 'info' => [], 'episodes' => []];
        }

        $episodesBySeason = [];
        $seasons = [];
        foreach ($target['seasons'] as $seasonNum => $eps) {
            $seasons[] = [
                'season_number' => (int) $seasonNum,
                'name' => 'Season ' . $seasonNum,
                'episode_count' => count($eps),
                'cover' => $target['cover'],
            ];
            $episodesBySeason[(string) $seasonNum] = $eps;
        }

        return [
            'seasons' => $seasons,
            'info' => [
                'name' => $target['title'],
                'cover' => $target['cover'],
                'plot' => '',
                'category_id' => (string) $target['category_id'],
            ],
            'episodes' => $episodesBySeason,
        ];
    }

    private function getCategoriesByGroupCategory(string $category): array
    {
        $sortMode = (string) $this->settings->get('group_sort_mode', 'import');
        $groupOrder = match ($sortMode) {
            'asc' => 'original_name ASC, id ASC',
            'desc' => 'original_name DESC, id DESC',
            default => 'import_order ASC, id ASC',
        };

        $stmt = $this->pdo->prepare(
            "SELECT id, COALESCE(NULLIF(custom_name, ''), original_name) AS display_name
             FROM m3u_groups
             WHERE is_enabled = 1 AND LOWER(TRIM(category)) = LOWER(?)
             ORDER BY {$groupOrder}"
        );
        $stmt->execute([$category]);

        $categories = [];
        while ($row = $stmt->fetch()) {
            $categories[] = [
                'category_id' => (string) $row['id'],
                'category_name' => (string) $row['display_name'],
                'parent_id' => 0,
            ];
        }
        return $categories;
    }

    private function buildSeriesGroups(?int $categoryId): array
    {
        $sortMode = (string) $this->settings->get('group_sort_mode', 'import');
        $groupOrder = match ($sortMode) {
            'asc' => 'g.original_name ASC, g.id ASC',
            'desc' => 'g.original_name DESC, g.id DESC',
            default => 'g.import_order ASC, g.id ASC',
        };

        $where = "g.is_enabled = 1 AND c.is_enabled = 1 AND g.category = 'TV Series'";
        $params = [];
        if ($categoryId !== null && $categoryId > 0) {
            $where .= ' AND c.group_id = ?';
            $params[] = $categoryId;
        }

        $sql = "SELECT c.id, c.group_id, c.tvg_name, c.tvg_logo, c.custom_name, c.stream_url
                FROM channels c
                INNER JOIN m3u_groups g ON g.id = c.group_id
                WHERE {$where}
                ORDER BY {$groupOrder},
                         COALESCE(c.channel_number, 999999) ASC,
                         c.sort_order ASC, c.id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $seriesMap = [];
        $nowStr = (string) time();
        while ($row = $stmt->fetch()) {
            $rawName = ChannelNameCleanerService::stripQualityTags((string) ($row['custom_name'] ?: $row['tvg_name'] ?: 'Series'));
            [$seriesTitle, $season, $epNum] = $this->parseSeriesEpisode($rawName);
            $groupKey = $row['group_id'] . '::' . mb_strtolower($seriesTitle);

            $added = $nowStr;
            $ext = $this->detectExtension((string) ($row['stream_url'] ?? ''), 'mp4');

            if (!isset($seriesMap[$groupKey])) {
                $seriesMap[$groupKey] = [
                    'series_id' => (int) $row['id'],
                    'title' => $seriesTitle,
                    'cover' => (string) ($row['tvg_logo'] ?? ''),
                    'category_id' => (int) $row['group_id'],
                    'last_modified' => $added,
                    'seasons' => [],
                ];
            }

            $seriesMap[$groupKey]['seasons'][$season][] = [
                'id' => (string) $row['id'],
                'episode_num' => $epNum,
                'title' => $rawName,
                'container_extension' => $ext,
                'info' => [
                    'movie_image' => (string) ($row['tvg_logo'] ?? ''),
                ],
                'custom_sid' => '',
                'added' => $added,
                'season' => $season,
                'direct_source' => '',
            ];
        }

        return array_values($seriesMap);
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function parseSeriesEpisode(string $name): array
    {
        if (preg_match('/^(.*?)\s*[Ss](\d{1,2})\s*[Ee](\d{1,3})\b/u', $name, $m)) {
            $title = trim($m[1], " \t\n\r\0\x0B-|:");
            return [$title !== '' ? $title : $name, max(1, (int) $m[2]), max(1, (int) $m[3])];
        }
        if (preg_match('/^(.*?)\s*(\d{1,2})[xX](\d{1,3})\b/u', $name, $m)) {
            $title = trim($m[1], " \t\n\r\0\x0B-|:");
            return [$title !== '' ? $title : $name, max(1, (int) $m[2]), max(1, (int) $m[3])];
        }
        if (preg_match('/^(.*?)\s*Seizoen\s*(\d{1,2})\s*Aflevering\s*(\d{1,3})\b/iu', $name, $m)) {
            $title = trim($m[1], " \t\n\r\0\x0B-|:");
            return [$title !== '' ? $title : $name, max(1, (int) $m[2]), max(1, (int) $m[3])];
        }
        if (preg_match('/^(.*?)\s*[Ee](\d{1,3})\b/u', $name, $m)) {
            $title = trim($m[1], " \t\n\r\0\x0B-|:");
            return [$title !== '' ? $title : $name, 1, max(1, (int) $m[2])];
        }
        return [$name, 1, 1];
    }

    private function detectExtension(string $url, string $default = 'mp4'): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, ['mp4', 'mkv', 'avi', 'ts', 'm3u8'], true) ? $ext : $default;
    }
}
