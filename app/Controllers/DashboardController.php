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
            'source_mode' => $settings->get('source_mode', 'separate_urls'),
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
        ignore_user_abort(true);
        set_time_limit(0);

        $mode = in_array($input['mode'] ?? 'all', ['all', 'm3u', 'epg'], true) ? (string) $input['mode'] : 'all';
        $cacheMode = in_array($input['cache_mode'] ?? 'force_download', ['auto', 'cache_only', 'force_download'], true)
            ? (string) $input['cache_mode']
            : 'force_download';

        (new \App\Services\SyncProgressService())->reset();

        $response = json_encode([
            'ok' => true,
            'success' => true,
            'data' => [
                'started' => true,
                'background' => true,
                'mode' => $mode,
                'cache_mode' => $cacheMode,
            ],
        ], JSON_UNESCAPED_UNICODE);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Connection: close');
        header('Content-Length: ' . strlen((string) $response));
        echo $response;
        flush();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        try {
            (new SchedulerService())->run(true, $mode, $cacheMode);
        } catch (\Throwable $e) {
            @file_put_contents(
                dirname(__DIR__, 2) . '/storage/logs/app.log',
                sprintf("[%s] [SYNC] %s\n", date('Y-m-d H:i:s'), $e->getMessage()),
                FILE_APPEND
            );
        }
        exit;
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

    public function runUpdateSql(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $updateFile = dirname(__DIR__, 2) . '/database/update.sql';
        if (!is_file($updateFile) || !is_readable($updateFile)) {
            $this->jsonError('database/update.sql niet gevonden of niet leesbaar.', 404);
            return;
        }

        $sql = file_get_contents($updateFile);
        if ($sql === false || trim($sql) === '') {
            $this->jsonError('database/update.sql is leeg.', 422);
            return;
        }

        try {
            $pdo = Database::getInstance();
            $pdo->exec($sql);
            $hash = hash_file('sha256', $updateFile);
            if ($hash !== false) {
                (new SettingsRepository($pdo))->set('last_update_sql_hash', $hash, false);
            }
            $this->jsonSuccess(['message' => 'database/update.sql succesvol uitgevoerd.']);
        } catch (\Throwable $e) {
            $this->jsonError('Fout bij uitvoeren van update.sql: ' . $e->getMessage(), 500);
        }
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
