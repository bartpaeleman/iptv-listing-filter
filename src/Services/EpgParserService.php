<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Repositories\SettingsRepository;
use PDO;
use RuntimeException;
use XMLReader;

final class EpgParserService
{
    private PDO $pdo;
    private SettingsRepository $settings;

    public function __construct(?PDO $pdo = null, ?SettingsRepository $settings = null)
    {
        $this->pdo = $pdo ?? Connection::getInstance();
        $this->settings = $settings ?? new SettingsRepository($this->pdo);
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

        $cacheDir = dirname(__DIR__, 2) . '/storage/cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        $isGz = str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.gz');
        $tmpFile = $cacheDir . ($isGz ? '/epg_source.xml.gz' : '/epg_source.xml');
        $this->downloadToFile($url, $tmpFile);

        $readerUri = $isGz ? 'compress.zlib://' . $tmpFile : $tmpFile;
        $reader = new XMLReader();
        if (!$reader->open($readerUri, null, LIBXML_NONET | LIBXML_COMPACT)) {
            @unlink($tmpFile);
            throw new RuntimeException('Kan EPG XML niet openen met XMLReader.');
        }

        $activeEpgIds = $this->loadActiveLinkedEpgIds();
        $cutoffUtc = gmdate('Y-m-d H:i:s', strtotime('-1 day'));

        // Prune old programmes
        $pruneStmt = $this->pdo->prepare('DELETE FROM epg_programmes WHERE stop_utc < :cutoff');
        $pruneStmt->execute([':cutoff' => $cutoffUtc]);
        $prunedCount = $pruneStmt->rowCount();

        $upsertChannelStmt = $this->pdo->prepare(
            'INSERT INTO epg_channels (epg_id, display_name, icon_url)
             VALUES (:id, :name, :icon)
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
                    $nodeXml = $reader->readOuterXml();
                    if ($nodeXml === '') {
                        continue;
                    }
                    $el = @simplexml_load_string($nodeXml);
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

                    $nodeXml = $reader->readOuterXml();
                    if ($nodeXml === '') {
                        continue;
                    }
                    $el = @simplexml_load_string($nodeXml);
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
            "SELECT DISTINCT epg_channel_id
             FROM channels
             WHERE is_enabled = 1 AND epg_channel_id IS NOT NULL AND epg_channel_id <> ''"
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

        // Format: YYYYMMDDHHMMSS +0000 or YYYYMMDDHHMMSS
        if (preg_match('/^(\d{14})\s*([+-]\d{4})?/', $raw, $m)) {
            $dtStr = $m[1] . ' ' . ($m[2] ?? '+0000');
            $dt = \DateTimeImmutable::createFromFormat('YmdHis O', $dtStr);
            if ($dt !== false) {
                return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }

        $ts = strtotime($raw);
        return $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : null;
    }

    private function downloadToFile(string $url, string $destPath): void
    {
        if (is_file($url) && is_readable($url)) {
            if (!copy($url, $destPath)) {
                throw new RuntimeException('Kan lokaal EPG bestand niet kopiëren.');
            }
            return;
        }

        $fp = fopen($destPath, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Kan tijdelijk EPG bestand niet aanmaken.');
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
            CURLOPT_TIMEOUT => 300,
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
            throw new RuntimeException('EPG download mislukt: ' . $err);
        }
    }
}
