<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/autoload.php';

use App\Config\EnvLoader;
use App\Security\AuthService;
use App\Services\EpgExporterService;

EnvLoader::load();

$auth = new AuthService();
$token = isset($_GET['token']) ? (string) $_GET['token'] : null;

if (!$auth->verifyLanOrToken($token)) {
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

$exporter = new EpgExporterService();
$exporter->streamXmltv($useGzip);
