<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpClient;
use App\Repositories\SettingsRepository;
use PDO;
use RuntimeException;
use XMLReader;

final class EpgParserService
{
    private PDO $pdo;
    private SettingsRepository $settings;
    private HttpClient $http;

    public function __construct(?PDO $pdo = null, ?SettingsRepository $settings = null, ?HttpClient $http = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
        $this->settings = $settings ?? new SettingsRepository($this->pdo);
        $this->http = $http ?? new HttpClient();
    }

    /**
     * @return array{epg_channels: int, programmes_imported: int, programmes_pruned: int, sources_ok: int, sources_failed: int}
     */
    public function sync(?string $overrideUrl = null, string $cacheMode = 'auto', ?string $cacheFilename = null): array
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        \App\Core\SchemaMigrator::ensureSchemaUpToDate($this->pdo);

        $cache = new FeedCacheService($this->settings);
        $cacheInfo = $cache->getCacheInfo('epg');

        if ($cacheMode === 'cache_only') {
            if ($cacheFilename !== null && $cacheFilename !== '') {
                $file = $cache->resolveFilePath($cacheFilename);
            } elseif ($cacheInfo['exists']) {
                $file = $cacheInfo['path'];
            } else {
                throw new RuntimeException("Geen lokale EPG-cache aanwezig — kies 'EPG downloaden & importeren'.");
            }
            $stats = $this->parseXmlFile($file);
            $cache->updateItemCount($file, $stats['epg_channels']);
            $this->settings->set('last_epg_sync', gmdate('Y-m-d H:i:s'), false);
            return [
                'epg_channels' => $stats['epg_channels'],
                'programmes_imported' => $stats['programmes_imported'],
                'programmes_pruned' => $stats['programmes_pruned'],
                'sources_ok' => 1,
                'sources_failed' => 0,
            ];
        }

        if ($cacheMode === 'auto' && $cacheInfo['is_fresh'] && $overrideUrl === null) {
            $stats = $this->parseXmlFile($cacheInfo['path']);
            $this->settings->set('last_epg_sync', gmdate('Y-m-d H:i:s'), false);
            return [
                'epg_channels' => $stats['epg_channels'],
                'programmes_imported' => $stats['programmes_imported'],
                'programmes_pruned' => $stats['programmes_pruned'],
                'sources_ok' => 1,
                'sources_failed' => 0,
            ];
        }

        $sourceRepo = new \App\Repositories\EpgSourceRepository($this->pdo);
        $sources = [];

        if ($overrideUrl !== null && $overrideUrl !== '') {
            $sources[] = ['id' => 0, 'name' => 'Handmatig', 'url' => $overrideUrl];
        } else {
            foreach ($sourceRepo->allDecrypted(true) as $src) {
                if (!empty($src['url'])) {
                    $sources[] = $src;
                }
            }
            if ($sources === []) {
                $legacyUrl = (string) $this->settings->get('epg_source_url', '');
                if ($legacyUrl === '' && $this->settings->get('source_mode', 'xtream') === 'xtream') {
                    $host = (string) $this->settings->get('xtream_host', '');
                    $user = (string) $this->settings->get('xtream_username', '');
                    $pass = (string) $this->settings->get('xtream_password', '');
                    if ($host !== '' && $user !== '' && $pass !== '') {
                        $legacyUrl = XtreamUrlBuilder::buildXmltvUrl($host, $user, $pass);
                        $sourceRepo->addOrUpdate('Xtream Provider EPG', $legacyUrl, 10);
                    }
                }
                if ($legacyUrl !== '') {
                    $sources[] = ['id' => 0, 'name' => 'Standaard EPG', 'url' => $legacyUrl];
                }
            }
        }

        if ($sources === []) {
            throw new RuntimeException('Geen actieve EPG-URL geconfigureerd.');
        }

        $totalChannels = 0;
        $totalProgs = 0;
        $totalPruned = 0;
        $sourcesOk = 0;
        $sourcesFailed = 0;

        foreach ($sources as $src) {
            $srcId = (int) ($src['id'] ?? 0);
            try {
                $tmpFile = dirname(__DIR__, 2) . '/storage/cache/epg_source.xml.tmp';
                $this->http->downloadToFile((string) $src['url'], $tmpFile, 300, true);
                $cachedFile = $cache->storeFromTempFile('epg', $tmpFile, (string) ($src['name'] ?? 'epg'));
                $stats = $this->parseXmlFile($cachedFile);
                $cache->updateItemCount($cachedFile, $stats['epg_channels']);
                $totalChannels += $stats['epg_channels'];
                $totalProgs += $stats['programmes_imported'];
                $totalPruned += $stats['programmes_pruned'];
                $sourcesOk++;
                if ($srcId > 0) {
                    $sourceRepo->updateStatus($srcId, "OK ({$stats['epg_channels']} zenders, {$stats['programmes_imported']} prog.)");
                }
            } catch (\Throwable $e) {
                $sourcesFailed++;
                if ($srcId > 0) {
                    $sourceRepo->updateStatus($srcId, 'Fout: ' . $e->getMessage());
                }
            }
        }

        if ($sourcesOk > 0) {
            $this->settings->set('last_epg_sync', gmdate('Y-m-d H:i:s'), false);
        }

        return [
            'epg_channels' => $totalChannels,
            'programmes_imported' => $totalProgs,
            'programmes_pruned' => $totalPruned,
            'sources_ok' => $sourcesOk,
            'sources_failed' => $sourcesFailed,
        ];
    }

    /**
     * @return array{epg_channels: int, programmes_imported: int, programmes_pruned: int}
     */
    private function parseXmlFile(string $tmpFile): array
    {
        $reader = new XMLReader();
        if (!$reader->open($tmpFile, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Kan EPG XML niet openen met XMLReader.');
        }

        $activeEpgIds = $this->loadActiveLinkedEpgIds();
        $cutoffUtc = gmdate('Y-m-d H:i:s', strtotime('-1 day'));

        $pruneStmt = $this->pdo->prepare('DELETE FROM epg_programmes WHERE stop_utc < :cutoff');
        $pruneStmt->execute([':cutoff' => $cutoffUtc]);
        $prunedCount = $pruneStmt->rowCount();

        $upsertChannelStmt = $this->pdo->prepare(
            'INSERT INTO epg_channels (epg_id, display_name, icon_url) VALUES (:id, :name, :icon)
             ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), icon_url = VALUES(icon_url)'
        );
        $insertProgStmt = $this->pdo->prepare(
            'INSERT IGNORE INTO epg_programmes (epg_id, start_utc, stop_utc, title, description, category)
             VALUES (:epg_id, :start, :stop, :title, :desc, :cat)'
        );

        $channelCount = 0;
        $progCount = 0;
        $batchSize = 0;

        $this->pdo->beginTransaction();
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($reader->name === 'channel') {
                    $el = @simplexml_load_string($reader->readOuterXml());
                    if ($el === false) {
                        continue;
                    }
                    $epgId = trim((string) ($el['id'] ?? ''));
                    if ($epgId === '') {
                        continue;
                    }
                    $displayName = trim((string) ($el->{'display-name'} ?? ''));
                    $iconUrl = trim((string) ($el->icon['src'] ?? ''));

                    $upsertChannelStmt->execute([
                        ':id' => $epgId,
                        ':name' => $displayName !== '' ? $displayName : $epgId,
                        ':icon' => $iconUrl !== '' ? $iconUrl : null,
                    ]);
                    $channelCount++;
                    $batchSize++;
                } elseif ($reader->name === 'programme') {
                    $epgId = trim((string) ($reader->getAttribute('channel') ?? ''));
                    if ($epgId === '' || !isset($activeEpgIds[$epgId])) {
                        continue;
                    }

                    $el = @simplexml_load_string($reader->readOuterXml());
                    if ($el === false) {
                        continue;
                    }
                    $startUtc = $this->parseXmltvTime((string) ($el['start'] ?? ''));
                    $stopUtc = $this->parseXmltvTime((string) ($el['stop'] ?? ''));
                    if ($startUtc === null || $stopUtc === null || $stopUtc < $cutoffUtc) {
                        continue;
                    }

                    $title = trim((string) ($el->title ?? ''));
                    $desc = trim((string) ($el->desc ?? ''));
                    $cat = trim((string) ($el->category ?? ''));

                    $insertProgStmt->execute([
                        ':epg_id' => $epgId,
                        ':start' => $startUtc,
                        ':stop' => $stopUtc,
                        ':title' => mb_substr($title, 0, 500),
                        ':desc' => $desc !== '' ? $desc : null,
                        ':cat' => $cat !== '' ? mb_substr($cat, 0, 255) : null,
                    ]);
                    $progCount++;
                    $batchSize++;
                }

                if ($batchSize >= 1000) {
                    $this->pdo->commit();
                    $this->pdo->beginTransaction();
                    $batchSize = 0;
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $reader->close();
            throw $e;
        }

        $reader->close();
        $this->settings->set('last_epg_sync', gmdate('Y-m-d H:i:s'), false);

        return [
            'epg_channels' => $channelCount,
            'programmes_imported' => $progCount,
            'programmes_pruned' => $prunedCount,
        ];
    }

    /**
     * @return array<string, true>
     */
    private function loadActiveLinkedEpgIds(): array
    {
        $stmt = $this->pdo->query(
            "SELECT DISTINCT epg_channel_id FROM channels WHERE is_enabled = 1 AND epg_channel_id IS NOT NULL AND epg_channel_id <> ''"
        );
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $ids[(string) $row['epg_channel_id']] = true;
        }
        return $ids;
    }

    private function parseXmltvTime(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{14})\s*([+-]\d{4})?/', $raw, $m)) {
            $dt = \DateTimeImmutable::createFromFormat('YmdHis O', $m[1] . ' ' . ($m[2] ?? '+0000'));
            if ($dt !== false) {
                return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        $ts = strtotime($raw);
        return $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : null;
    }

    private function sanitizeString(?string $value, int $maxLength = 255): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = trim((string) mb_convert_encoding($value, 'UTF-8', 'UTF-8'));
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
        $clean = trim((string) mb_convert_encoding($value, 'UTF-8', 'UTF-8'));
        return $clean !== '' ? $clean : null;
    }
}
