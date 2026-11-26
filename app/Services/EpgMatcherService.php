<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class EpgMatcherService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    /**
     * @return array{by_id: array<string, string>, by_name: array<string, string>}
     */
    public function loadLookupMap(): array
    {
        $stmt = $this->pdo->query('SELECT epg_id, display_name FROM epg_channels');
        $byId = [];
        $byName = [];

        foreach ($stmt->fetchAll() as $row) {
            $id = (string) $row['epg_id'];
            $name = (string) $row['display_name'];
            $byId[strtolower($id)] = $id;
            $norm = self::normalizeName($name);
            if ($norm !== '') {
                $byName[$norm] = $id;
            }
        }

        return ['by_id' => $byId, 'by_name' => $byName];
    }

    /**
     * @param array{by_id: array<string, string>, by_name: array<string, string>} $epgMap
     */
    public function findMatch(string $tvgId, string $tvgName, array $epgMap): ?string
    {
        $res = $this->findMatchWithQuality($tvgId, $tvgName, $epgMap);
        return $res['epg_id'];
    }

    /**
     * @param array{by_id: array<string, string>, by_name: array<string, string>} $epgMap
     * @return array{epg_id: ?string, match_type: string}
     */
    public function findMatchWithQuality(string $tvgId, string $tvgName, array $epgMap): array
    {
        if ($tvgId !== '') {
            $key = strtolower($tvgId);
            if (isset($epgMap['by_id'][$key])) {
                return ['epg_id' => $epgMap['by_id'][$key], 'match_type' => 'auto'];
            }
        }

        $strictName = self::normalizeStrictName($tvgName);
        if ($strictName !== '' && isset($epgMap['by_name'][$strictName])) {
            return ['epg_id' => $epgMap['by_name'][$strictName], 'match_type' => 'auto'];
        }

        $normName = self::normalizeName($tvgName);
        if ($normName !== '' && isset($epgMap['by_name'][$normName])) {
            return ['epg_id' => $epgMap['by_name'][$normName], 'match_type' => 'close_match'];
        }

        return ['epg_id' => null, 'match_type' => 'none'];
    }

    public function autoMatchUnmapped(): int
    {
        $epgMap = $this->loadLookupMap();
        $channels = $this->pdo->query(
            "SELECT c.id, c.tvg_id, c.tvg_name
             FROM channels c
             INNER JOIN m3u_groups g ON g.id = c.group_id
             WHERE c.is_enabled = 1 AND g.is_enabled = 1 AND COALESCE(g.category, 'Live TV') = 'Live TV'
               AND (c.epg_channel_id IS NULL OR c.epg_channel_id = '')"
        )->fetchAll();

        $upd = $this->pdo->prepare('UPDATE channels SET epg_channel_id = :epg, epg_match_type = :type WHERE id = :id');
        $matched = 0;

        foreach ($channels as $ch) {
            $res = $this->findMatchWithQuality((string) ($ch['tvg_id'] ?? ''), (string) ($ch['tvg_name'] ?? ''), $epgMap);
            if ($res['epg_id'] !== null) {
                $upd->execute([':epg' => $res['epg_id'], ':type' => $res['match_type'], ':id' => (int) $ch['id']]);
                $matched++;
            }
        }

        return $matched;
    }

    /**
     * @param array<int, int> $channelIds
     * @return array{matched: int, processed: int}
     */
    public function autoMatchFiltered(
        int $groupId = 0,
        string $epgStatus = 'all',
        bool $activeOnly = true,
        bool $liveOnly = true,
        array $channelIds = []
    ): array {
        $where = ['1=1'];
        $params = [];

        if ($groupId > 0) {
            $where[] = 'c.group_id = :gid';
            $params[':gid'] = $groupId;
        }
        if ($activeOnly) {
            $where[] = 'c.is_enabled = 1 AND g.is_enabled = 1';
        }
        if ($liveOnly) {
            $where[] = "c.is_enabled = 1 AND g.is_enabled = 1 AND COALESCE(g.category, 'Live TV') = 'Live TV'";
        }
        if ($epgStatus === 'coupled' || $epgStatus === 'mapped' || $epgStatus === 'yes') {
            $where[] = "c.epg_channel_id IS NOT NULL AND c.epg_channel_id <> ''";
        } elseif ($epgStatus === 'uncoupled' || $epgStatus === 'unmapped' || $epgStatus === 'no') {
            $where[] = "(c.epg_channel_id IS NULL OR c.epg_channel_id = '')";
        }
        if ($channelIds !== []) {
            $cleanIds = array_values(array_unique(array_filter(
                array_map('intval', $channelIds),
                static fn(int $id): bool => $id > 0
            )));
            if ($cleanIds === []) {
                return ['matched' => 0, 'processed' => 0];
            }
            $ph = [];
            foreach ($cleanIds as $idx => $cid) {
                $key = ":cid_{$idx}";
                $ph[] = $key;
                $params[$key] = $cid;
            }
            $where[] = 'c.id IN (' . implode(', ', $ph) . ')';
        }

        $sql = 'SELECT c.id, c.tvg_id, c.tvg_name, c.custom_name
                FROM channels c
                INNER JOIN m3u_groups g ON g.id = c.group_id
                WHERE ' . implode(' AND ', $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $channels = $stmt->fetchAll();

        if ($channels === []) {
            return ['matched' => 0, 'processed' => 0];
        }

        $epgMap = $this->loadLookupMap();
        $upd = $this->pdo->prepare('UPDATE channels SET epg_channel_id = :epg, epg_match_type = :type WHERE id = :id');
        $matched = 0;
        $closeMatched = 0;

        foreach ($channels as $ch) {
            $name = (string) (($ch['custom_name'] ?? '') !== '' ? $ch['custom_name'] : ($ch['tvg_name'] ?? ''));
            $res = $this->findMatchWithQuality((string) ($ch['tvg_id'] ?? ''), $name, $epgMap);
            if ($res['epg_id'] !== null) {
                $upd->execute([':epg' => $res['epg_id'], ':type' => $res['match_type'], ':id' => (int) $ch['id']]);
                $matched++;
                if ($res['match_type'] === 'close_match') {
                    $closeMatched++;
                }
            }
        }

        return [
            'matched' => $matched,
            'close_matched' => $closeMatched,
            'processed' => count($channels),
        ];
    }

    public static function normalizeStrictName(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($name))) ?? '';
    }

    public static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\b(hd|fhd|uhd|4k|sd|nl|be|uk|us|hevc|h265)\b/i', '', $name) ?? $name;
        return preg_replace('/[^a-z0-9]/', '', $name) ?? '';
    }
}
