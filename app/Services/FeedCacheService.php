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
     * @return array<int, array<string, mixed>>
     */
    public function listCacheFiles(?string $type = null): array
    {
        $files = glob($this->cacheDir . '/*.{m3u,xml}', GLOB_BRACE) ?: [];
        $result = [];

        foreach ($files as $path) {
            $filename = basename($path);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $fileType = $ext === 'xml' ? 'epg' : 'm3u';
            if ($type !== null && $fileType !== $type) {
                continue;
            }

            $metaPath = $path . '.meta.json';
            $meta = is_file($metaPath) ? json_decode((string) file_get_contents($metaPath), true) : null;
            if (!is_array($meta)) {
                $mtime = filemtime($path) ?: time();
                $size = filesize($path) ?: 0;
                $meta = [
                    'filename' => $filename,
                    'type' => $fileType,
                    'label' => $filename,
                    'source_label' => 'provider',
                    'created_at' => gmdate('Y-m-d H:i:s', $mtime),
                    'timestamp' => $mtime,
                    'size_bytes' => $size,
                    'size_formatted' => $this->formatBytes($size),
                    'item_count' => null,
                ];
            }

            $meta['filename'] = $filename;
            $result[] = $meta;
        }

        usort($result, static fn (array $a, array $b): int => ((int) ($b['timestamp'] ?? 0)) <=> ((int) ($a['timestamp'] ?? 0)));
        return $result;
    }

    /**
     * @return array{path: string, filename: string|null, exists: bool, downloaded_at: string|null, age_minutes: int|null, is_fresh: bool, size_formatted: string, total_files: int}
     */
    public function getCacheInfo(string $type): array
    {
        $files = $this->listCacheFiles($type);
        $totalFiles = count($this->listCacheFiles(null));
        $empty = [
            'path' => '',
            'filename' => null,
            'exists' => false,
            'downloaded_at' => null,
            'age_minutes' => null,
            'is_fresh' => false,
            'size_formatted' => '0 B',
            'total_files' => $totalFiles,
        ];

        if ($files === []) {
            return $empty;
        }

        $newest = $files[0];
        try {
            $path = $this->resolveFilePath((string) ($newest['filename'] ?? ''));
        } catch (\Throwable) {
            return $empty;
        }
        $mtime = (int) ($newest['timestamp'] ?? time());
        $ttlMinutes = max(1, (int) ($this->settings->get('cache_ttl_minutes', '360') ?? '360'));
        $ageMinutes = max(0, (int) floor((time() - $mtime) / 60));

        return [
            'path' => $path,
            'filename' => (string) $newest['filename'],
            'exists' => true,
            'downloaded_at' => (string) ($newest['created_at'] ?? gmdate('Y-m-d H:i:s', $mtime)),
            'age_minutes' => $ageMinutes,
            'is_fresh' => $ageMinutes < $ttlMinutes,
            'size_formatted' => (string) ($newest['size_formatted'] ?? '0 B'),
            'total_files' => $totalFiles,
        ];
    }

    public function storeFromTempFile(string $type, string $tempPath, string $sourceLabel = 'provider'): string
    {
        if (!is_file($tempPath) || filesize($tempPath) === 0) {
            throw new RuntimeException("Gedownload tijdelijk bestand voor {$type} ontbreekt of is leeg.");
        }

        $safeSource = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($sourceLabel)) ?: 'provider';
        $safeSource = trim(substr($safeSource, 0, 24), '_') ?: 'provider';
        $ext = $type === 'epg' ? 'xml' : 'm3u';
        $filename = sprintf('%s_%s_%s.%s', $type, date('Y-m-d_His'), $safeSource, $ext);
        $destPath = $this->cacheDir . '/' . $filename;

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
            'filename' => $filename,
            'type' => $type,
            'label' => $filename,
            'source_label' => $sourceLabel,
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'timestamp' => $now,
            'size_bytes' => $size,
            'size_formatted' => $this->formatBytes($size),
            'item_count' => null,
        ];
        @file_put_contents($destPath . '.meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE));

        return $destPath;
    }

    public function resolveFilePath(string $filename): string
    {
        if ($filename === '' || basename($filename) !== $filename) {
            throw new RuntimeException('Ongeldige cache-bestandsnaam.');
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['m3u', 'xml'], true)) {
            throw new RuntimeException('Ongeldige cache-extensie.');
        }
        $path = $this->cacheDir . '/' . $filename;
        if (!is_file($path)) {
            throw new RuntimeException("Cachebestand '{$filename}' niet gevonden.");
        }
        return $path;
    }

    public function updateItemCount(string $filePath, int $count): void
    {
        $metaPath = $filePath . '.meta.json';
        if (!is_file($metaPath)) {
            return;
        }
        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (is_array($meta)) {
            $meta['item_count'] = $count;
            @file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_UNICODE));
        }
    }

    public function renameLabel(string $filename, string $newLabel): bool
    {
        $path = $this->resolveFilePath($filename);
        $metaPath = $path . '.meta.json';
        $meta = is_file($metaPath) ? json_decode((string) file_get_contents($metaPath), true) : [];
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['filename'] = $filename;
        $meta['label'] = trim($newLabel) !== '' ? trim($newLabel) : $filename;
        return file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_UNICODE)) !== false;
    }

    public function deleteFile(string $filename): bool
    {
        $path = $this->resolveFilePath($filename);
        @unlink($path . '.meta.json');
        return @unlink($path);
    }

    /**
     * @param array<int, string> $filenames
     */
    public function deleteBulk(array $filenames): int
    {
        $deleted = 0;
        foreach ($filenames as $fn) {
            try {
                if ($this->deleteFile((string) $fn)) {
                    $deleted++;
                }
            } catch (\Throwable) {
                // Skip invalid filenames
            }
        }
        return $deleted;
    }

    public function clearCache(?string $type = null): void
    {
        foreach ($this->listCacheFiles($type) as $file) {
            $this->deleteFile((string) $file['filename']);
        }
    }

    public function formatBytes(int $bytes): string
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
