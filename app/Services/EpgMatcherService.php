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

        return null;
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

    public static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\b(hd|fhd|uhd|4k|sd|nl|be|uk|us|hevc|h265)\b/i', '', $name) ?? $name;
        return preg_replace('/[^a-z0-9]/', '', $name) ?? '';
    }
}
