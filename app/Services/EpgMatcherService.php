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
     * @return array{by_id: array<string, string>, by_name: array<string, string>, entries: array<int, array{epg_id: string, display_name: string, norm: string, country: string}>}
     */
    public function loadLookupMap(): array
    {
        $stmt = $this->pdo->query('SELECT epg_id, display_name FROM epg_channels');
        $byId = [];
        $byName = [];
        $entries = [];

        foreach ($stmt->fetchAll() as $row) {
            $id = (string) $row['epg_id'];
            $name = (string) $row['display_name'];
            $byId[strtolower($id)] = $id;
            $norm = self::normalizeName($name);
            if ($norm !== '') {
                $byName[$norm] = $id;
            }
            $entries[] = [
                'epg_id' => $id,
                'display_name' => $name,
                'norm' => $norm,
                'country' => self::extractCountryCode($name, $id),
            ];
        }

        return ['by_id' => $byId, 'by_name' => $byName, 'entries' => $entries];
    }

    /**
     * @param array{by_id: array<string, string>, by_name: array<string, string>, entries?: array<int, array{epg_id: string, display_name: string, norm: string, country: string}>} $epgMap
     */
    public function findMatch(string $tvgId, string $tvgName, array $epgMap): ?string
    {
        if ($tvgId !== '') {
            $key = strtolower($tvgId);
            if (isset($epgMap['by_id'][$key])) {
                return $epgMap['by_id'][$key];
            }
        }

        $normName = self::normalizeName($tvgName);
        if ($normName !== '' && isset($epgMap['by_name'][$normName])) {
            return $epgMap['by_name'][$normName];
        }

        // Layer 3a: Zender-alias map
        $aliases = [
            'canvas' => ['vrtcanvas'],
            'vrtcanvas' => ['canvas'],
            'een' => ['vrt1'],
            'vrt1' => ['een'],
            'vtm4' => ['vtm4'],
            'play4' => ['vier'],
            'vier' => ['play4'],
            'play5' => ['vijf'],
            'vijf' => ['play5'],
            'play6' => ['zes'],
            'zes' => ['play6'],
            'play7' => ['zeven'],
            'zeven' => ['play7'],
        ];
        if ($normName !== '' && isset($aliases[$normName])) {
            foreach ($aliases[$normName] as $alt) {
                if (isset($epgMap['by_name'][$alt])) {
                    return $epgMap['by_name'][$alt];
                }
            }
        }

        // Layer 3b: Land-gebonden token-match
        $chCountry = self::extractCountryCode($tvgName, $tvgId);
        $coreWords = self::extractCoreWords($tvgName);
        if ($chCountry !== '' && $coreWords !== '' && !empty($epgMap['entries'])) {
            $pattern = '/\b' . preg_quote($coreWords, '/') . '\b/i';
            foreach ($epgMap['entries'] as $entry) {
                if ($entry['country'] === $chCountry && preg_match($pattern, $entry['display_name']) === 1) {
                    return $entry['epg_id'];
                }
            }
        }

        return null;
    }

    public function autoMatchAllActiveUnlinked(): int
    {
        $epgMap = $this->loadLookupMap();
        if ($epgMap['by_id'] === [] && $epgMap['by_name'] === []) {
            return 0;
        }

        $channels = $this->pdo->query(
            "SELECT DISTINCT c.id, c.tvg_id, c.tvg_name
             FROM channels c
             LEFT JOIN m3u_groups g ON g.id = c.group_id
             WHERE c.is_enabled = 1
               AND (c.epg_channel_id IS NULL OR c.epg_channel_id = '')
               AND COALESCE(g.category, 'Live TV') NOT IN ('Film', 'TV Series')
               AND (
                   (g.is_custom = 0 AND g.is_enabled = 1)
                   OR EXISTS (
                       SELECT 1 FROM custom_group_channels cgc
                       INNER JOIN m3u_groups cg ON cg.id = cgc.custom_group_id
                       WHERE cgc.channel_id = c.id AND cgc.is_ignored = 0 AND cg.is_custom = 1 AND cg.is_enabled = 1
                   )
               )"
        )->fetchAll();

        $upd = $this->pdo->prepare("UPDATE channels SET epg_channel_id = :epg, epg_match_type = 'auto_name' WHERE id = :id AND (epg_channel_id IS NULL OR epg_channel_id = '')");
        $matched = 0;

        foreach ($channels as $ch) {
            $epgId = $this->findMatch((string) ($ch['tvg_id'] ?? ''), (string) ($ch['tvg_name'] ?? ''), $epgMap);
            if ($epgId !== null) {
                $upd->execute([':epg' => $epgId, ':id' => (int) $ch['id']]);
                $matched += $upd->rowCount();
            }
        }

        return $matched;
    }

    public function autoMatchUnmapped(): int
    {
        $epgMap = $this->loadLookupMap();
        $channels = $this->pdo->query(
            "SELECT id, tvg_id, tvg_name FROM channels WHERE is_enabled = 1 AND (epg_channel_id IS NULL OR epg_channel_id = '')"
        )->fetchAll();

        $upd = $this->pdo->prepare("UPDATE channels SET epg_channel_id = :epg, epg_match_type = 'auto' WHERE id = :id");
        $matched = 0;

        foreach ($channels as $ch) {
            $epgId = $this->findMatch((string) ($ch['tvg_id'] ?? ''), (string) ($ch['tvg_name'] ?? ''), $epgMap);
            if ($epgId !== null) {
                $upd->execute([':epg' => $epgId, ':id' => (int) $ch['id']]);
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
            $where[] = "c.is_enabled = 1 AND g.is_enabled = 1 AND COALESCE(g.category, 'Live TV') NOT IN ('Film', 'TV Series')";
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
        $upd = $this->pdo->prepare("UPDATE channels SET epg_channel_id = :epg, epg_match_type = 'auto' WHERE id = :id");
        $matched = 0;

        foreach ($channels as $ch) {
            $name = (string) (($ch['custom_name'] ?? '') !== '' ? $ch['custom_name'] : ($ch['tvg_name'] ?? ''));
            $epgId = $this->findMatch((string) ($ch['tvg_id'] ?? ''), $name, $epgMap);
            if ($epgId !== null) {
                $upd->execute([':epg' => $epgId, ':id' => (int) $ch['id']]);
                $matched++;
            }
        }

        return [
            'matched' => $matched,
            'processed' => count($channels),
        ];
    }

    public static function normalizeName(string $name): string
    {
        $name = str_replace(['ᴿᴬᵂ', 'ᴴᴰ', 'ᶠᴴᴰ', '⁴ᴷ', 'ᵁᴴᴰ', 'ˢᵈ', 'ᴴᴱⱽᶜ', 'é', 'ë'], ['', '', '', '', '', '', '', 'e', 'e'], $name);
        $name = preg_replace('/^(?:PLAY\+|PLAY|BE|NL|UK|US)\s*\|\s*/i', '', trim($name)) ?? $name;
        $name = strtolower(trim($name));
        $name = preg_replace('/\b(hd|fhd|uhd|4k|sd|nl|be|uk|us|hevc|h265|raw)\b/i', '', $name) ?? $name;
        return preg_replace('/[^a-z0-9]/', '', $name) ?? '';
    }

    private static function extractCountryCode(string $name, string $id = ''): string
    {
        if (preg_match('/\b(BE|NL|UK|US)\b/i', $name, $m) === 1) {
            return strtoupper($m[1]);
        }
        if (preg_match('/\.(be|nl|uk|us)$/i', trim($id), $m) === 1) {
            return strtoupper($m[1]);
        }
        return '';
    }

    private static function extractCoreWords(string $name): string
    {
        $clean = str_replace(['ᴿᴬᵂ', 'ᴴᴰ', 'ᶠᴴᴰ', '⁴ᴷ', 'ᵁᴴᴰ', 'ˢᵈ', 'ᴴᴱⱽᶜ'], '', $name);
        $clean = preg_replace('/^(?:PLAY\+|PLAY|BE|NL|UK|US)\s*\|\s*/i', '', trim($clean)) ?? $clean;
        $clean = preg_replace('/\b(hd|fhd|uhd|4k|sd|nl|be|uk|us|hevc|h265|raw)\b/i', '', $clean) ?? $clean;
        $clean = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $clean) ?? $clean;
        return trim(preg_replace('/\s+/', ' ', $clean) ?? '');
    }
}
