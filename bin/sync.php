#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dit script mag enkel via PHP-CLI uitgevoerd worden.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/src/autoload.php';

use App\Config\EnvLoader;
use App\Services\SchedulerService;

EnvLoader::load();

$force = in_array('--force', $argv, true);
$mode = 'all';
if (in_array('--m3u', $argv, true)) {
    $mode = 'm3u';
} elseif (in_array('--epg', $argv, true)) {
    $mode = 'epg';
}

try {
    $scheduler = new SchedulerService();
    $result = $scheduler->run($force, $mode);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Sync fout: ' . $e->getMessage() . "\n");
    exit(1);
}
