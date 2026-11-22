<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Services\FeedCacheService;
use App\Services\SchedulerService;

final class CacheController extends BaseController
{
    public function listFiles(): void
    {
        $this->requireAuth();
        $cache = new FeedCacheService();
        $files = $cache->listCacheFiles();

        $totalBytes = 0;
        $m3uCount = 0;
        $epgCount = 0;
        foreach ($files as $f) {
            $totalBytes += (int) ($f['size_bytes'] ?? 0);
            if (($f['type'] ?? '') === 'epg') {
                $epgCount++;
            } else {
                $m3uCount++;
            }
        }

        $this->jsonSuccess([
            'files' => $files,
            'm3u_count' => $m3uCount,
            'epg_count' => $epgCount,
            'total_size_formatted' => $cache->formatBytes($totalBytes),
        ]);
    }

    public function importFile(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);
        session_write_close();

        $filename = trim((string) ($input['filename'] ?? ''));
        $cache = new FeedCacheService();
        $cache->resolveFilePath($filename);

        $mode = str_ends_with(strtolower($filename), '.xml') ? 'epg' : 'm3u';
        try {
            $res = (new SchedulerService())->run(true, $mode, 'cache_only', $filename);
            $this->jsonSuccess($res);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    public function renameLabel(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $filename = trim((string) ($input['filename'] ?? ''));
        $label = trim((string) ($input['label'] ?? ''));
        (new FeedCacheService())->renameLabel($filename, $label);
        $this->jsonSuccess();
    }

    public function deleteFiles(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $filenames = is_array($input['filenames'] ?? null) ? $input['filenames'] : [];
        $deleted = (new FeedCacheService())->deleteBulk($filenames);
        $this->jsonSuccess(['deleted' => $deleted]);
    }
}
