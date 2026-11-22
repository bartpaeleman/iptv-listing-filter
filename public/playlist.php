<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/autoload.php';

use App\Config\EnvLoader;
use App\Security\AuthService;
use App\Services\PlaylistExporterService;

EnvLoader::load();

$auth = new AuthService();
$token = isset($_GET['token']) ? (string) $_GET['token'] : null;

if (!$auth->verifyLanOrToken($token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden: Ongeldige token of IP-adres buiten LAN.\n";
    exit;
}

header('Content-Type: audio/x-mpegurl; charset=utf-8');
header('Content-Disposition: inline; filename="playlist.m3u"');
header('Cache-Control: no-cache, must-revalidate');

$exporter = new PlaylistExporterService();
$exporter->streamPlaylist();
