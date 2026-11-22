<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Core\Database;
use App\Core\EnvLoader;
use App\Repositories\SettingsRepository;
use App\Repositories\SyncLogRepository;
use App\Services\SchedulerService;

final class DashboardController extends BaseController
{
    public function index(): void
    {
        $this->requireAuth();
        $pdo = Database::getInstance();
        $settings = new SettingsRepository($pdo);
        $logs = new SyncLogRepository($pdo);

        $activeChannels = (int) $pdo->query(
            'SELECT COUNT(*) FROM channels c INNER JOIN m3u_groups g ON g.id = c.group_id WHERE g.is_enabled = 1 AND c.is_enabled = 1'
        )->fetchColumn();
        $totalChannels = (int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn();
        $activeGroups = (int) $pdo->query('SELECT COUNT(*) FROM m3u_groups WHERE is_enabled = 1')->fetchColumn();
        $totalGroups = (int) $pdo->query('SELECT COUNT(*) FROM m3u_groups')->fetchColumn();
        $linkedEpg = (int) $pdo->query(
            "SELECT COUNT(*) FROM channels c INNER JOIN m3u_groups g ON g.id = c.group_id
             WHERE g.is_enabled = 1 AND c.is_enabled = 1 AND c.epg_channel_id IS NOT NULL AND c.epg_channel_id <> ''"
        )->fetchColumn();
        $epgChannelsCount = (int) $pdo->query('SELECT COUNT(*) FROM epg_channels')->fetchColumn();

        $cache = new \App\Services\FeedCacheService($settings);

        $this->jsonSuccess([
            'active_channels' => $activeChannels,
            'total_channels' => $totalChannels,
            'active_groups' => $activeGroups,
            'total_groups' => $totalGroups,
            'linked_epg' => $linkedEpg,
            'epg_channels_count' => $epgChannelsCount,
            'last_m3u_sync' => $settings->get('last_m3u_sync', ''),
            'last_epg_sync' => $settings->get('last_epg_sync', ''),
            'sync_interval_hours' => (int) $settings->get('sync_interval_hours', '72'),
            'sync_preferred_hour' => (int) $settings->get('sync_preferred_hour', '3'),
            'output_token' => $settings->get('output_token', ''),
            'app_url' => rtrim((string) EnvLoader::get('APP_URL', ''), '/'),
            'logs' => $logs->recent(15),
            'cache_status' => [
                'm3u' => $cache->getCacheInfo('m3u'),
                'epg' => $cache->getCacheInfo('epg'),
            ],
        ]);
    }

    public function sync(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);
        session_write_close();

        $mode = in_array($input['mode'] ?? 'all', ['all', 'm3u', 'epg'], true) ? (string) $input['mode'] : 'all';
        $cacheMode = in_array($input['cache_mode'] ?? 'force_download', ['auto', 'cache_only', 'force_download'], true)
            ? (string) $input['cache_mode']
            : 'force_download';

        try {
            $res = (new SchedulerService())->run(true, $mode, $cacheMode);
            $this->jsonSuccess($res);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    public function syncStatus(): void
    {
        $this->requireAuth();
        session_write_close();

        $progress = (new \App\Services\SyncProgressService())->getState();
        $logs = (new SyncLogRepository())->recent(15);
        $this->jsonSuccess([
            'progress' => $progress,
            'logs' => $logs,
        ]);
    }

    public function clearCache(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $type = in_array($input['type'] ?? null, ['m3u', 'epg'], true) ? (string) $input['type'] : null;
        (new \App\Services\FeedCacheService())->clearCache($type);
        $this->jsonSuccess();
    }

    public function clearSyncLogs(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $deleted = (new SyncLogRepository())->clearAll();
        $this->jsonSuccess(['deleted' => $deleted, 'message' => 'Sync log is leeggemaakt']);
    }

    public function logout(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);
        $this->auth->logout();
        $this->jsonSuccess();
    }
}
