<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\EnvLoader;
use App\Repositories\SettingsRepository;
use PDO;

final class PlaylistExporterService
{
    private PDO $pdo;
    private SettingsRepository $settings;

    public function __construct(?PDO $pdo = null, ?SettingsRepository $settings = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
        $this->settings = $settings ?? new SettingsRepository($this->pdo);
    }

    public function streamPlaylist(string $sortMode = 'import'): void
    {
        $appUrl = $this->resolveBaseUrl();
        $token = (string) $this->settings->get('output_token', '');
        $epgUrl = $appUrl . '/index.php?route=epg' . ($token !== '' ? '&token=' . urlencode($token) : '');

        echo '#EXTM3U url-tvg="' . $this->escapeAttr($epgUrl) . '" x-tvg-url="' . $this->escapeAttr($epgUrl) . "\"\n";

        $groupOrder = match ($sortMode) {
            'asc' => 'g_name ASC, g_id ASC',
            'desc' => 'g_name DESC, g_id DESC',
            default => 'g_import_order ASC, g_id ASC',
        };

        $sql = "SELECT tvg_id, tvg_name, tvg_logo, stream_url, custom_name, channel_number, epg_channel_id, group_name
                FROM (
                    SELECT c.tvg_id, c.tvg_name, c.tvg_logo, c.stream_url, c.custom_name,
                           c.channel_number, c.epg_channel_id,
                           COALESCE(NULLIF(g.custom_name, ''), g.original_name) AS group_name,
                           g.original_name AS g_name, g.import_order AS g_import_order, g.id AS g_id,
                           COALESCE(c.channel_number, 999999) AS ch_num, c.sort_order AS ch_sort, c.id AS ch_id
                    FROM channels c
                    INNER JOIN m3u_groups g ON g.id = c.group_id
                    WHERE g.is_custom = 0 AND g.is_enabled = 1 AND c.is_enabled = 1

                    UNION ALL

                    SELECT c.tvg_id, c.tvg_name, c.tvg_logo, c.stream_url, c.custom_name,
                           c.channel_number, c.epg_channel_id,
                           COALESCE(NULLIF(g.custom_name, ''), g.original_name) AS group_name,
                           COALESCE(NULLIF(g.custom_name, ''), g.original_name) AS g_name, g.import_order AS g_import_order, g.id AS g_id,
                           0 AS ch_num, cgc.sort_order AS ch_sort, c.id AS ch_id
                    FROM custom_group_channels cgc
                    INNER JOIN m3u_groups g ON g.id = cgc.custom_group_id
                    INNER JOIN channels c ON c.id = cgc.channel_id
                    WHERE g.is_custom = 1 AND g.is_enabled = 1 AND c.is_enabled = 1 AND cgc.is_ignored = 0
                ) AS export_rows
                ORDER BY {$groupOrder}, ch_num ASC, ch_sort ASC, ch_id ASC";

        $stmt = $this->pdo->query($sql);
        while ($row = $stmt->fetch()) {
            $tvgId = (string) ($row['epg_channel_id'] ?: $row['tvg_id'] ?: '');
            $name = ChannelNameCleanerService::stripQualityTags((string) ($row['custom_name'] ?: $row['tvg_name'] ?: 'Channel'));
            $logo = (string) ($row['tvg_logo'] ?? '');
            $group = (string) ($row['group_name'] ?? 'Overig');
            $chno = $row['channel_number'] !== null ? (string) (int) $row['channel_number'] : '';

            $attrs = [
                'tvg-id="' . $this->escapeAttr($tvgId) . '"',
                'tvg-name="' . $this->escapeAttr($name) . '"',
            ];
            if ($logo !== '') {
                $attrs[] = 'tvg-logo="' . $this->escapeAttr($logo) . '"';
            }
            if ($chno !== '') {
                $attrs[] = 'tvg-chno="' . $chno . '"';
            }
            $attrs[] = 'group-title="' . $this->escapeAttr($group) . '"';

            echo '#EXTINF:-1 ' . implode(' ', $attrs) . ',' . $name . "\n";
            echo $row['stream_url'] . "\n";
        }
    }

    private function resolveBaseUrl(): string
    {
        if (!empty($_SERVER['HTTP_HOST'])) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            $scheme = $isHttps ? 'https' : 'http';
            $scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
            if ($scriptDir === '.') {
                $scriptDir = '';
            }
            return $scheme . '://' . $_SERVER['HTTP_HOST'] . $scriptDir;
        }

        return rtrim((string) EnvLoader::get('APP_URL', ''), '/');
    }

    private function escapeAttr(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['\"', '', ''], $value);
    }
}
