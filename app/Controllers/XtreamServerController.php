<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SettingsRepository;
use App\Services\EpgExporterService;
use App\Services\PlaylistExporterService;
use App\Services\XtreamServerService;

final class XtreamServerController
{
    private XtreamServerService $xtream;
    private SettingsRepository $settings;

    public function __construct(
        ?XtreamServerService $xtream = null,
        ?SettingsRepository $settings = null
    ) {
        $this->xtream = $xtream ?? new XtreamServerService();
        $this->settings = $settings ?? new SettingsRepository();
    }

    public function playerApi(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $user = (string) ($_REQUEST['username'] ?? '');
        $pass = (string) ($_REQUEST['password'] ?? '');

        if (!$this->xtream->authenticate($user, $pass)) {
            http_response_code(401);
            echo json_encode(['user_info' => ['auth' => 0]], JSON_UNESCAPED_UNICODE);
            return;
        }

        $action = (string) ($_REQUEST['action'] ?? '');

        $data = match ($action) {
            '' => $this->xtream->buildAuthPayload(
                $user,
                $pass,
                $this->resolveHost(),
                $this->resolvePort(),
                $this->resolveProtocol()
            ),
            'get_live_categories' => $this->xtream->getLiveCategories(),
            'get_live_streams' => $this->xtream->getLiveStreams(
                isset($_REQUEST['category_id']) && $_REQUEST['category_id'] !== '' ? (int) $_REQUEST['category_id'] : null
            ),
            'get_vod_categories' => $this->xtream->getVodCategories(),
            'get_vod_streams' => $this->xtream->getVodStreams(
                isset($_REQUEST['category_id']) && $_REQUEST['category_id'] !== '' ? (int) $_REQUEST['category_id'] : null
            ),
            'get_vod_info' => $this->xtream->getVodInfo((int) ($_REQUEST['vod_id'] ?? 0)),
            'get_series_categories' => $this->xtream->getSeriesCategories(),
            'get_series' => $this->xtream->getSeries(
                isset($_REQUEST['category_id']) && $_REQUEST['category_id'] !== '' ? (int) $_REQUEST['category_id'] : null
            ),
            'get_series_info' => $this->xtream->getSeriesInfo((int) ($_REQUEST['series_id'] ?? 0)),
            'get_short_epg' => $this->xtream->getShortEpg(
                (int) ($_REQUEST['stream_id'] ?? 0),
                isset($_REQUEST['limit']) ? (int) $_REQUEST['limit'] : 4
            ),
            'get_simple_data_table' => $this->xtream->getShortEpg(
                (int) ($_REQUEST['stream_id'] ?? 0),
                20
            ),
            default => [],
        };

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function getPlaylist(): void
    {
        $user = (string) ($_REQUEST['username'] ?? '');
        $pass = (string) ($_REQUEST['password'] ?? '');

        if (!$this->xtream->authenticate($user, $pass)) {
            http_response_code(401);
            echo 'Unauthorized';
            return;
        }

        $sortMode = (string) $this->settings->get('group_sort_mode', 'import');
        header('Content-Type: application/x-mpegurl; charset=utf-8');
        header('Content-Disposition: inline; filename="playlist.m3u"');

        (new PlaylistExporterService())->streamPlaylist($sortMode);
    }

    public function xmltv(): void
    {
        $user = (string) ($_REQUEST['username'] ?? '');
        $pass = (string) ($_REQUEST['password'] ?? '');

        if (!$this->xtream->authenticate($user, $pass)) {
            http_response_code(401);
            echo 'Unauthorized';
            return;
        }

        header('Content-Type: application/xml; charset=utf-8');
        (new EpgExporterService())->streamXmltv(false);
    }

    public function liveStream(): void
    {
        $user = (string) ($_GET['username'] ?? '');
        $pass = (string) ($_GET['password'] ?? '');
        $streamId = (int) ($_GET['stream_id'] ?? 0);

        if (!$this->xtream->authenticate($user, $pass)) {
            http_response_code(401);
            echo 'Unauthorized';
            return;
        }

        $url = $this->xtream->resolveStreamUrl($streamId);
        if ($url === null || $url === '') {
            http_response_code(404);
            echo 'Stream not found';
            return;
        }

        header('Location: ' . $url, true, 302);
        exit;
    }

    private function resolveProtocol(): string
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return $isHttps ? 'https' : 'http';
    }

    private function resolveHost(): string
    {
        $httpHost = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return explode(':', $httpHost)[0];
    }

    private function resolvePort(): int
    {
        $httpHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if (str_contains($httpHost, ':')) {
            return (int) explode(':', $httpHost)[1];
        }
        return $this->resolveProtocol() === 'https' ? 443 : 80;
    }
}
