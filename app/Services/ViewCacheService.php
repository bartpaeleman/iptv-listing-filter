<?php

declare(strict_types=1);

namespace App\Services;

final class ViewCacheService
{
    private string $cacheDir;

    public function __construct()
    {
        $this->cacheDir = dirname(__DIR__, 2) . '/storage/cache/view';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDashboardStats(): ?array
    {
        return $this->readJson($this->cacheDir . '/dashboard_stats.json');
    }

    /**
     * @param array<string, mixed> $stats
     */
    public function setDashboardStats(array $stats): void
    {
        $this->writeJson($this->cacheDir . '/dashboard_stats.json', $stats);
    }

    public function invalidateDashboardStats(): void
    {
        @unlink($this->cacheDir . '/dashboard_stats.json');
    }

    /**
     * @param array<int, string> $contentTypes
     * @return array<int, array<string, mixed>>|null
     */
    public function getActiveGroups(string $sortMode, array $contentTypes): ?array
    {
        $key = $this->groupsKey($sortMode, $contentTypes);
        return $this->readJson($this->cacheDir . '/active_groups_' . $key . '.json');
    }

    /**
     * @param array<int, string> $contentTypes
     * @param array<int, array<string, mixed>> $groups
     */
    public function setActiveGroups(string $sortMode, array $contentTypes, array $groups): void
    {
        $key = $this->groupsKey($sortMode, $contentTypes);
        $this->writeJson($this->cacheDir . '/active_groups_' . $key . '.json', $groups);
    }

    public function invalidateGroupsCache(): void
    {
        $files = glob($this->cacheDir . '/active_groups_*.json') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}|null
     */
    public function getGroupChannels(int $groupId): ?array
    {
        if ($groupId <= 0) {
            return null;
        }
        return $this->readJson($this->cacheDir . '/group_channels_' . $groupId . '.json');
    }

    /**
     * @param array{items: array<int, array<string, mixed>>, total: int} $data
     */
    public function setGroupChannels(int $groupId, array $data): void
    {
        if ($groupId <= 0) {
            return;
        }
        $this->writeJson($this->cacheDir . '/group_channels_' . $groupId . '.json', $data);
    }

    public function invalidateGroupChannels(int $groupId): void
    {
        if ($groupId > 0) {
            @unlink($this->cacheDir . '/group_channels_' . $groupId . '.json');
        }
    }

    public function invalidateAll(): void
    {
        $files = glob($this->cacheDir . '/*.json') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /**
     * @param array<int, string> $contentTypes
     */
    private function groupsKey(string $sortMode, array $contentTypes): string
    {
        $types = $contentTypes;
        sort($types);
        return md5($sortMode . '|' . implode(',', $types));
    }

    /**
     * @return array<mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            @file_put_contents($path, $json, LOCK_EX);
        }
    }
}
