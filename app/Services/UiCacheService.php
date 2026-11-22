<?php

declare(strict_types=1);

namespace App\Services;

final class UiCacheService
{
    private string $cacheDir;

    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = $cacheDir ?? (dirname(__DIR__, 2) . '/storage/cache');
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function getGroups(): ?array
    {
        $file = $this->cacheDir . '/ui_groups_cache.json';
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     */
    public function saveGroups(array $groups): void
    {
        $file = $this->cacheDir . '/ui_groups_cache.json';
        @file_put_contents($file, json_encode($groups, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function getGroupChannels(int $groupId): ?array
    {
        if ($groupId <= 0) {
            return null;
        }
        $file = $this->cacheDir . '/ui_channels_group_' . $groupId . '.json';
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<int, array<string, mixed>> $channels
     */
    public function saveGroupChannels(int $groupId, array $channels): void
    {
        if ($groupId <= 0) {
            return;
        }
        $file = $this->cacheDir . '/ui_channels_group_' . $groupId . '.json';
        @file_put_contents($file, json_encode($channels, JSON_UNESCAPED_UNICODE));
    }

    public function invalidateGroup(int $groupId): void
    {
        @unlink($this->cacheDir . '/ui_groups_cache.json');
        if ($groupId > 0) {
            @unlink($this->cacheDir . '/ui_channels_group_' . $groupId . '.json');
        }
    }

    public function invalidateAll(): void
    {
        @unlink($this->cacheDir . '/ui_groups_cache.json');
        $files = glob($this->cacheDir . '/ui_channels_group_*.json');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
}
