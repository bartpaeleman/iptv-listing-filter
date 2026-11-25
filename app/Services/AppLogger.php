<?php

declare(strict_types=1);

namespace App\Services;

final class AppLogger
{
    /**
     * @param array<string, mixed> $meta
     */
    public static function log(string $context, string $level, string $message, array $meta = []): void
    {
        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $logFile = $logDir . '/app.log';
        $ctx = strtoupper(trim($context));
        $lvl = strtoupper(trim($level));
        $line = sprintf('[%s] [%s] [%s] %s', date('Y-m-d H:i:s'), $ctx, $lvl, trim($message));

        if ($meta !== []) {
            $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }

        @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
