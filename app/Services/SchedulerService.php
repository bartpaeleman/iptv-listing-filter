<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\SettingsRepository;
use App\Repositories\SyncLogRepository;
use PDO;

final class SchedulerService
{
    private PDO $pdo;
    private SettingsRepository $settings;
    private SyncLogRepository $logs;

    public function __construct(?PDO $pdo = null, ?SettingsRepository $settings = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
        $this->settings = $settings ?? new SettingsRepository($this->pdo);
        $this->logs = new SyncLogRepository($this->pdo);
    }

    /**
     * @return array{ran: bool, reason?: string, m3u?: array<string, int>, epg?: array<string, int>}
     */
    public function run(bool $force = false, string $mode = 'all', string $cacheMode = 'force_download'): array
    {
        $intervalHours = max(1, (int) ($this->settings->get('sync_interval_hours', '72') ?? '72'));
        $preferredHour = (int) ($this->settings->get('sync_preferred_hour', '3') ?? '3');
        $lastM3u = (string) $this->settings->get('last_m3u_sync', '');

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

        $progress = new SyncProgressService();
        $progress->reset();
        $result = ['ran' => true];

        try {
            if ($mode === 'all' || $mode === 'epg') {
                $progress->update('running', 'parse_epg', 10, 'EPG bronnen synchroniseren...', [], 'Start EPG synchronisatie');
                try {
                    $epgStats = (new EpgParserService($this->pdo, $this->settings))->sync(null, $cacheMode);
                    $result['epg'] = $epgStats;
                    $this->logs->addLog('epg', 'success', 'EPG synchronisatie voltooid.', $epgStats);
                    $this->writeFileLog('epg', 'success', 'EPG synchronisatie voltooid.', $epgStats);
                } catch (\Throwable $epgErr) {
                    if ($mode === 'all' && $cacheMode === 'cache_only') {
                        $this->logs->addLog('epg', 'skipped', $epgErr->getMessage(), []);
                    } else {
                        throw $epgErr;
                    }
                }
            }

            if ($mode === 'all' || $mode === 'm3u') {
                $m3uStats = (new M3uParserService($this->pdo, $this->settings))->sync(null, $cacheMode);
                $result['m3u'] = $m3uStats;
                $this->logs->addLog('m3u', 'success', 'M3U synchronisatie voltooid.', $m3uStats);
                $this->writeFileLog('m3u', 'success', 'M3U synchronisatie voltooid.', $m3uStats);
            }

            $progress->update('completed', 'done', 100, 'Synchronisatie voltooid!', [], 'Synchronisatie voltooid');
        } catch (\Throwable $e) {
            $progress->update('error', 'done', 100, 'Fout: ' . $e->getMessage(), [], 'Fout: ' . $e->getMessage());
            $this->logs->addLog($mode, 'error', $e->getMessage(), []);
            $this->writeFileLog($mode, 'error', $e->getMessage(), []);
            throw $e;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function writeFileLog(string $type, string $status, string $message, array $stats): void
    {
        $logFile = dirname(__DIR__, 2) . '/storage/logs/sync.log';
        $line = sprintf("[%s] [%s] [%s] %s %s\n", date('Y-m-d H:i:s'), strtoupper($type), strtoupper($status), $message, json_encode($stats));
        @file_put_contents($logFile, $line, FILE_APPEND);
    }
}
