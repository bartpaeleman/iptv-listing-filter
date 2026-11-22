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

    public function streamPlaylist(): void
    {
        $appUrl = rtrim((string) EnvLoader::get('APP_URL', ''), '/');
        $token = (string) $this->settings->get('output_token', '');
        $epgUrl = $appUrl . '/index.php?route=epg' . ($token !== '' ? '&token=' . urlencode($token) : '');

        echo '#EXTM3U url-tvg="' . $this->escapeAttr($epgUrl) . '" x-tvg-url="' . $this->escapeAttr($epgUrl) . "\"\n";

        $sql = 'SELECT c.tvg_id, c.tvg_name, c.tvg_logo, c.stream_url, c.custom_name,
                       c.channel_number, c.epg_channel_id,
                       COALESCE(NULLIF(g.custom_name, \'\'), g.original_name) AS group_name
                FROM channels c
                INNER JOIN m3u_groups g ON g.id = c.group_id
                WHERE g.is_enabled = 1 AND c.is_enabled = 1
                ORDER BY g.sort_order ASC, g.id ASC,
                         COALESCE(c.channel_number, 999999) ASC,
                         c.sort_order ASC, c.id ASC';

        $stmt = $this->pdo->query($sql);
        while ($row = $stmt->fetch()) {
            $tvgId = (string) ($row['epg_channel_id'] ?: $row['tvg_id'] ?: '');
            $name = (string) ($row['custom_name'] ?: $row['tvg_name'] ?: 'Channel');
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

    private function escapeAttr(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['\"', '', ''], $value);
    }
}
