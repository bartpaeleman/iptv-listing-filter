<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\CountryTagRepository;
use PDO;

final class CountryTagService
{
    private PDO $pdo;
    private CountryTagRepository $repo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
        $this->repo = new CountryTagRepository($this->pdo);
    }

    public static function isoToFlag(string $iso2): string
    {
        $code = strtoupper(trim($iso2));
        if (strlen($code) !== 2) {
            return '';
        }
        $first = mb_chr(0x1F1E6 + ord($code[0]) - 65, 'UTF-8');
        $second = mb_chr(0x1F1E6 + ord($code[1]) - 65, 'UTF-8');
        return $first . $second;
    }

    /**
     * @param array<int, array<string, mixed>> $tags
     * @return array{country_tag_id: int|null, formatted_name: string}
     */
    public function detectAndFormat(string $rawName, array $tags): array
    {
        foreach ($tags as $tag) {
            $pattern = (string) $tag['tag_pattern'];
            if ($pattern !== '' && stripos($rawName, $pattern) !== false) {
                $flag = (string) $tag['flag_emoji'];
                $formatted = str_starts_with($rawName, $flag) ? $rawName : trim($flag . ' ' . $rawName);
                return [
                    'country_tag_id' => (int) $tag['id'],
                    'formatted_name' => mb_substr($formatted, 0, 250, 'UTF-8'),
                ];
            }
        }
        return ['country_tag_id' => null, 'formatted_name' => $rawName];
    }

    /**
     * @return array<int, string>
     */
    public function scanUnmappedTags(): array
    {
        $existing = array_map(
            static fn (array $t): string => strtolower((string) $t['tag_pattern']),
            $this->repo->allOrdered()
        );
        $existingSet = array_flip($existing);

        $found = [];
        $rows = $this->pdo->query('SELECT tvg_name FROM channels LIMIT 5000')->fetchAll();
        foreach ($rows as $row) {
            if (preg_match_all('/(\[[A-Za-z]{2,4}\]|\|[A-Za-z]{2,4}\|)/', (string) $row['tvg_name'], $matches)) {
                foreach ($matches[1] as $tag) {
                    $key = strtolower($tag);
                    if (!isset($existingSet[$key])) {
                        $found[$tag] = true;
                    }
                }
            }
        }
        return array_keys($found);
    }

    /**
     * @return array{updated: int, grouped: int}
     */
    public function applyTagsToExistingChannels(bool $groupByCountry = false): array
    {
        $tags = $this->repo->allOrdered();
        $channels = $this->pdo->query('SELECT id, tvg_name, custom_name FROM channels')->fetchAll();
        $upd = $this->pdo->prepare('UPDATE channels SET country_tag_id = :tid, custom_name = :cname WHERE id = :id');

        $updated = 0;
        $grouped = 0;
        $groupCache = [];

        if ($groupByCountry) {
            foreach ($this->pdo->query('SELECT id, original_name FROM m3u_groups')->fetchAll() as $g) {
                $groupCache[(string) $g['original_name']] = (int) $g['id'];
            }
        }

        $insertGroup = $this->pdo->prepare('INSERT INTO m3u_groups (original_name, is_enabled, sort_order) VALUES (:n, 1, 1)');
        $moveGroup = $this->pdo->prepare('UPDATE channels SET group_id = :gid WHERE id = :id');

        foreach ($channels as $ch) {
            $baseName = (string) ($ch['custom_name'] ?: $ch['tvg_name']);
            $res = $this->detectAndFormat($baseName, $tags);
            if ($res['country_tag_id'] !== null) {
                $upd->execute([
                    ':tid' => $res['country_tag_id'],
                    ':cname' => $res['formatted_name'],
                    ':id' => (int) $ch['id'],
                ]);
                $updated++;

                if ($groupByCountry) {
                    foreach ($tags as $t) {
                        if ((int) $t['id'] === $res['country_tag_id']) {
                            $gName = trim($t['flag_emoji'] . ' ' . $t['country_name']);
                            if (!isset($groupCache[$gName])) {
                                $insertGroup->execute([':n' => $gName]);
                                $groupCache[$gName] = (int) $this->pdo->lastInsertId();
                            }
                            $moveGroup->execute([':gid' => $groupCache[$gName], ':id' => (int) $ch['id']]);
                            $grouped++;
                            break;
                        }
                    }
                }
            }
        }

        return ['updated' => $updated, 'grouped' => $grouped];
    }
}
