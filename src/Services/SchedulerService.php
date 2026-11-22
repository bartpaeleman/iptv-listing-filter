<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Repositories\SettingsRepository;
use PDO;

final class SchedulerService
{
    private PDO $pdo;
    private SettingsRepository $settings;

    public function __construct(?PDO $pdo = null, ?SettingsRepository $settings = null)
    {
        $this->pdo = $pdo ?? Connection::getInstance();
        $this->settings = $settings ?? new SettingsRepository($this->pdo);
    }

    /**
     * @return array{ran: bool, reason?: string, m3u?: array<string, int>, epg?: array<string, int>}
     */
    public function run(bool $force = false, string $mode = 'all'): array
    {
        $intervalHours = max(1, (int) ($this->settings->get('sync_interval_hours', '72') ?? '72'));
        $preferredHour = (int) ($this->settings->get('sync_preferred_hour', '3') ?? '3');
        $lastM3u = (string) $this->settings->get('last_m3u_sync', '');
        $lastEpg = (string) $this->settings->get('last_epg_sync', '');

        if (!$force) {
            $currentHour = (int) date('G');
            if ($currentHour !== $preferredHour) {
                return ['ran' => false, 'reason' => "Huidig uur ({$currentHour}) != voorkeursuur ({$preferredHour})."];
            }

            $lastSync = $lastM3u !== '' ? strtotime($lastM3u . ' UTC') : 0;
            if ($lastSync > 0 && (time() - $lastSync) < ($intervalHours * 3600)) {
                return ['ran' => false, 'reason' => 'Interval nog niet verstreken.'];
            }
        }

        $result = ['ran' => true];

        try {
            if ($mode === 'all' || $mode === 'epg') {
                $epgParser = new EpgParserService($this->pdo, $this->settings);
                $epgStats = $epgParser->sync();
                $result['epg'] = $epgStats;
                $this->log('epg', 'success', 'EPG synchronisatie voltooid.', $epgStats);
            }

            if ($mode === 'all' || $mode === 'm3u') {
                $m3uParser = new M3uParserService($this->pdo, $this->settings);
                $m3uStats = $m3uParser->sync();
                $result['m3u'] = $m3uStats;
                $this->log('m3u', 'success', 'M3U synchronisatie voltooid.', $m3uStats);
            }
        } catch (\Throwable $e) {
            $this->log($mode, 'error', $e->getMessage(), []);
            throw $e;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function log(string $type, string $status, string $message, array $stats): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sync_logs (sync_type, status, message, stats_json) VALUES (:type, :status, :msg, :stats)'
        );
        $stmt->execute([
            ':type' => $type,
            ':status' => $status,
            ':msg' => mb_substr($message, 0, 500),
            ':stats' => json_encode($stats, JSON_UNESCAPED_UNICODE),
        ]);

        $logFile = dirname(__DIR__, 2) . '/storage/logs/sync.log';
        $line = sprintf("[%s] [%s] [%s] %s %s\n", date('Y-m-d H:i:s'), strtoupper($type), strtoupper($status), $message, json_encode($stats));
        @file_put_contents($logFile, $line, FILE_APPEND);
    }
}
