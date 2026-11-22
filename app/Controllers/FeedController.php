<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Security\NetworkGuard;
use App\Services\EpgExporterService;
use App\Services\PlaylistExporterService;
use App\Services\SchedulerService;

final class FeedController extends BaseController
{
    private NetworkGuard $guard;

    public function __construct()
    {
        parent::__construct();
        $this->guard = new NetworkGuard();
    }

    public function playlist(): void
    {
        $token = isset($_GET['token']) ? (string) $_GET['token'] : null;
        if (!$this->guard->verifyLanOrToken($token)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "403 Forbidden: Ongeldige token of IP-adres buiten LAN.\n";
            exit;
        }

        header('Content-Type: audio/x-mpegurl; charset=utf-8');
        header('Content-Disposition: inline; filename="playlist.m3u"');
        header('Cache-Control: no-cache, must-revalidate');

        (new PlaylistExporterService())->streamPlaylist();
        exit;
    }

    public function epg(): void
    {
        $token = isset($_GET['token']) ? (string) $_GET['token'] : null;
        if (!$this->guard->verifyLanOrToken($token)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "403 Forbidden: Ongeldige token of IP-adres buiten LAN.\n";
            exit;
        }

        $acceptEncoding = (string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
        $useGzip = str_contains(strtolower($acceptEncoding), 'gzip') && function_exists('gzencode');

        header('Content-Type: application/xml; charset=utf-8');
        if ($useGzip) {
            header('Content-Encoding: gzip');
        }
        header('Cache-Control: no-cache, must-revalidate');

        (new EpgExporterService())->streamXmltv($useGzip);
        exit;
    }

    public function cron(): void
    {
        $token = isset($_GET['token']) ? (string) $_GET['token'] : null;
        if (!$this->guard->verifyLanOrToken($token)) {
            $this->jsonError('Forbidden', 403);
        }

        $force = isset($_GET['force']) && $_GET['force'] === '1';
        $mode = in_array($_GET['mode'] ?? 'all', ['all', 'm3u', 'epg'], true) ? (string) $_GET['mode'] : 'all';

        try {
            $res = (new SchedulerService())->run($force, $mode);
            $this->jsonSuccess($res);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }
}
