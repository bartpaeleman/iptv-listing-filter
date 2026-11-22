<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Repositories\SettingsRepository;
use PDO;
use RuntimeException;

final class M3uParserService
{
    private PDO $pdo;
    private SettingsRepository $settings;

    public function __construct(?PDO $pdo = null, ?SettingsRepository $settings = null)
    {
        $this->pdo = $pdo ?? Connection::getInstance();
        $this->settings = $settings ?? new SettingsRepository($this->pdo);
    }

    /**
     * @return array{groups_added: int, channels_added: int, channels_updated: int, auto_matched: int}
     */
    public function sync(?string $overrideUrl = null): array
    {
        $url = $overrideUrl ?? (string) $this->settings->get('m3u_source_url', '');
        if ($url === '') {
            throw new RuntimeException('Geen M3U bron-URL geconfigureerd.');
        }

        $cacheDir = dirname(__DIR__, 2) . '/storage/cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }
        $tmpFile = $cacheDir . '/m3u_source.tmp';

        $this->downloadToFile($url, $tmpFile);

        $handle = fopen($tmpFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Kan gedownloade M3U bestand niet openen.');
        }

        $groupCache = $this->loadGroupCache();
        $epgMap = $this->loadEpgLookupMap();

        $groupsAdded = 0;
        $channelsAdded = 0;
        $channelsUpdated = 0;
        $autoMatched = 0;
        $now = gmdate('Y-m-d H:i:s');

        $insertGroupStmt = $this->pdo->prepare(
            'INSERT INTO m3u_groups (original_name, is_enabled, sort_order) VALUES (:name, 1, :sort)'
        );

        $selectChannelStmt = $this->pdo->prepare(
            'SELECT id, epg_channel_id, epg_match_type FROM channels WHERE stream_hash = :hash LIMIT 1'
        );

        $insertChannelStmt = $this->pdo->prepare(
            'INSERT INTO channels (
                stream_hash, tvg_id, tvg_name, tvg_logo, group_id, stream_url,
                epg_channel_id, epg_match_type, is_enabled, sort_order, last_seen_at
             ) VALUES (
                :hash, :tvg_id, :tvg_name, :tvg_logo, :group_id, :stream_url,
                :epg_id, :match_type, 1, :sort, :seen
             )'
        );

        $updateChannelStmt = $this->pdo->prepare(
            'UPDATE channels
             SET tvg_id = :tvg_id,
                 tvg_name = :tvg_name,
                 tvg_logo = :tvg_logo,
                 group_id = :group_id,
                 stream_url = :stream_url,
                 epg_channel_id = :epg_id,
                 epg_match_type = :match_type,
                 last_seen_at = :seen
             WHERE id = :id'
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

                if (str_starts_with($line, '#')) {
                    continue;
                }

                if ($pendingMeta === null) {
                    continue;
                }

                $streamUrl = $line;
                $groupName = $pendingMeta['group_title'] !== '' ? $pendingMeta['group_title'] : 'Overig';

                if (!isset($groupCache[$groupName])) {
                    $insertGroupStmt->execute([
                        ':name' => $groupName,
                        ':sort' => count($groupCache) + 1,
                    ]);
                    $groupCache[$groupName] = (int) $this->pdo->lastInsertId();
                    $groupsAdded++;
                }

                $groupId = $groupCache[$groupName];
                $streamHash = hash('sha256', $streamUrl);

                $selectChannelStmt->execute([':hash' => $streamHash]);
                $existing = $selectChannelStmt->fetch();

                if ($existing) {
                    $epgChannelId = $existing['epg_channel_id'];
                    $matchType = (string) $existing['epg_match_type'];

                    if ($matchType === 'none' || $epgChannelId === null || $epgChannelId === '') {
                        $matched = $this->findEpgMatch($pendingMeta['tvg_id'], $pendingMeta['tvg_name'], $epgMap);
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
                        ':stream_url' => $streamUrl,
                        ':epg_id' => $epgChannelId,
                        ':match_type' => $matchType,
                        ':seen' => $now,
                        ':id' => (int) $existing['id'],
                    ]);
                    $channelsUpdated++;
                } else {
                    $matched = $this->findEpgMatch($pendingMeta['tvg_id'], $pendingMeta['tvg_name'], $epgMap);
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
                        ':stream_url' => $streamUrl,
                        ':epg_id' => $matched,
                        ':match_type' => $matchType,
                        ':sort' => $nextSort++,
                        ':seen' => $now,
                    ]);
                    $channelsAdded++;
                }

                $pendingMeta = null;
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            fclose($handle);
            @unlink($tmpFile);
            throw $e;
        }

        fclose($handle);
        @unlink($tmpFile);

        $this->settings->set('last_m3u_sync', $now, false);

        return [
            'groups_added' => $groupsAdded,
            'channels_added' => $channelsAdded,
            'channels_updated' => $channelsUpdated,
            'auto_matched' => $autoMatched,
        ];
    }

    /**
     * @return array{tvg_id: string, tvg_name: string, tvg_logo: string, group_title: string}
     */
    private function parseExtInf(string $line): array
    {
        $tvgId = '';
        $tvgName = '';
        $tvgLogo = '';
        $groupTitle = '';

        if (preg_match('/tvg-id="([^"]*)"/i', $line, $m)) {
            $tvgId = trim($m[1]);
        }
        if (preg_match('/tvg-name="([^"]*)"/i', $line, $m)) {
            $tvgName = trim($m[1]);
        }
        if (preg_match('/tvg-logo="([^"]*)"/i', $line, $m)) {
            $tvgLogo = trim($m[1]);
        }
        if (preg_match('/group-title="([^"]*)"/i', $line, $m)) {
            $groupTitle = trim($m[1]);
        }

        $commaPos = strrpos($line, ',');
        $displayName = $commaPos !== false ? trim(substr($line, $commaPos + 1)) : '';
        if ($tvgName === '' && $displayName !== '') {
            $tvgName = $displayName;
        }

        return [
            'tvg_id' => $tvgId,
            'tvg_name' => $tvgName,
            'tvg_logo' => $tvgLogo,
            'group_title' => $groupTitle,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function loadGroupCache(): array
    {
        $stmt = $this->pdo->query('SELECT id, original_name FROM m3u_groups');
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['original_name']] = (int) $row['id'];
        }
        return $map;
    }

    /**
     * @return array{by_id: array<string, string>, by_name: array<string, string>}
     */
    private function loadEpgLookupMap(): array
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
    private function findEpgMatch(string $tvgId, string $tvgName, array $epgMap): ?string
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

    public static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\b(hd|fhd|uhd|4k|sd|nl|be|uk|us|hevc|h265)\b/i', '', $name) ?? $name;
        return preg_replace('/[^a-z0-9]/', '', $name) ?? '';
    }

    private function downloadToFile(string $url, string $destPath): void
    {
        if (is_file($url) && is_readable($url)) {
            if (!copy($url, $destPath)) {
                throw new RuntimeException('Kan lokaal M3U bestand niet kopiëren.');
            }
            return;
        }

        $fp = fopen($destPath, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Kan tijdelijk M3U bestand niet aanmaken.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fp);
            throw new RuntimeException('cURL initialisatie mislukt.');
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_USERAGENT => 'IPTV-Manager/1.0',
            CURLOPT_FAILONERROR => true,
        ]);

        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok) {
            @unlink($destPath);
            throw new RuntimeException('M3U download mislukt: ' . $err);
        }
    }
}
