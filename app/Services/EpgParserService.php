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
     * @return array{epg_channels: int, programmes_imported: int, programmes_pruned: int}
     */
    public function sync(?string $overrideUrl = null): array
    {
        $url = $overrideUrl ?? (string) $this->settings->get('epg_source_url', '');
        if ($url === '') {
            throw new RuntimeException('Geen EPG bron-URL geconfigureerd.');
        }

        $isGz = str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.gz');
        $tmpFile = dirname(__DIR__, 2) . '/storage/cache/' . ($isGz ? 'epg_source.xml.gz' : 'epg_source.xml');
        $this->http->downloadToFile($url, $tmpFile, 300);

        $readerUri = $isGz ? 'compress.zlib://' . $tmpFile : $tmpFile;
        $reader = new XMLReader();
        if (!$reader->open($readerUri, null, LIBXML_NONET | LIBXML_COMPACT)) {
            @unlink($tmpFile);
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
            'INSERT INTO epg_programmes (epg_id, start_utc, stop_utc, title, description, category)
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
            @unlink($tmpFile);
            throw $e;
        }

        $reader->close();
        @unlink($tmpFile);
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
}
