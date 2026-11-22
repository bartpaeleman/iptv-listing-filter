<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use RuntimeException;

final class FeedCacheService
{
    private string $cacheDir;
    private SettingsRepository $settings;

    public function __construct(?SettingsRepository $settings = null)
    {
        $this->cacheDir = dirname(__DIR__, 2) . '/storage/cache';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }
        $this->settings = $settings ?? new SettingsRepository();
    }

    /**
     * @return array{
     *   path: string,
     *   exists: bool,
     *   downloaded_at: string|null,
     *   age_minutes: int|null,
     *   is_fresh: bool,
     *   size_formatted: string
     * }
     */
    public function getCacheInfo(string $type): array
    {
        $path = $this->getDataPath($type);
        $metaPath = $this->getMetaPath($type);
        clearstatcache(true, $path);

        if (!is_file($path)) {
            return [
                'path' => $path,
                'exists' => false,
                'downloaded_at' => null,
                'age_minutes' => null,
                'is_fresh' => false,
                'size_formatted' => '0 B',
            ];
        }

        $mtime = filemtime($path) ?: time();
        $size = filesize($path) ?: 0;
        $downloadedAt = gmdate('Y-m-d H:i:s', $mtime);

        if (is_file($metaPath)) {
            $meta = json_decode((string) file_get_contents($metaPath), true);
            if (is_array($meta)) {
                $mtime = (int) ($meta['timestamp'] ?? $mtime);
                $size = (int) ($meta['size'] ?? $size);
                $downloadedAt = (string) ($meta['downloaded_at'] ?? $downloadedAt);
            }
        }

        $ttlMinutes = max(1, (int) ($this->settings->get('cache_ttl_minutes', '360') ?? '360'));
        $ageMinutes = max(0, (int) floor((time() - $mtime) / 60));

        return [
            'path' => $path,
            'exists' => true,
            'downloaded_at' => $downloadedAt,
            'age_minutes' => $ageMinutes,
            'is_fresh' => $ageMinutes < $ttlMinutes,
            'size_formatted' => $this->formatBytes($size),
        ];
    }

    public function storeFromTempFile(string $type, string $tempPath): string
    {
        if (!is_file($tempPath) || filesize($tempPath) === 0) {
            throw new RuntimeException("Gedownload tijdelijk bestand voor {$type} ontbreekt of is leeg.");
        }

        $destPath = $this->getDataPath($type);
        if (!@rename($tempPath, $destPath)) {
            if (!@copy($tempPath, $destPath)) {
                throw new RuntimeException("Kan cachebestand niet opslaan voor {$type}.");
            }
            @unlink($tempPath);
        }

        clearstatcache(true, $destPath);
        $size = is_file($destPath) ? (int) filesize($destPath) : 0;
        if ($size === 0) {
            throw new RuntimeException("Cachebestand voor {$type} is 0 bytes of niet weggeschreven.");
        }

        $now = time();
        $meta = [
            'timestamp' => $now,
            'downloaded_at' => gmdate('Y-m-d H:i:s', $now),
            'size' => $size,
        ];
        @file_put_contents($this->getMetaPath($type), json_encode($meta));

        return $destPath;
    }

    public function clearCache(?string $type = null): void
    {
        $types = $type !== null ? [$type] : ['m3u', 'epg'];
        foreach ($types as $t) {
            @unlink($this->getDataPath($t));
            @unlink($this->getMetaPath($t));
        }
    }

    private function getDataPath(string $type): string
    {
        return $this->cacheDir . ($type === 'epg' ? '/latest_epg.xml' : '/latest_playlist.m3u');
    }

    private function getMetaPath(string $type): string
    {
        return $this->getDataPath($type) . '.meta.json';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        }
        return $bytes . ' B';
    }
}
