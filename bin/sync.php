#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dit script mag enkel via PHP-CLI uitgevoerd worden.\n");
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/app/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use App\Core\EnvLoader;
use App\Services\SchedulerService;

EnvLoader::load(dirname(__DIR__) . '/.env');

$force = in_array('--force', $argv, true);
$cacheMode = in_array('--use-cache', $argv, true) ? 'cache_only' : 'force_download';
$mode = 'all';
if (in_array('--m3u', $argv, true)) {
    $mode = 'm3u';
} elseif (in_array('--epg', $argv, true)) {
    $mode = 'epg';
}

try {
    $result = (new SchedulerService())->run($force, $mode, $cacheMode);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Sync fout: ' . $e->getMessage() . "\n");
    exit(1);
}
