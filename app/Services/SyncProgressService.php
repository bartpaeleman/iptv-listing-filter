<?php

declare(strict_types=1);

namespace App\Services;

final class SyncProgressService
{
    private string $filePath;

    public function __construct()
    {
        $cacheDir = dirname(__DIR__, 2) . '/storage/cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }
        $this->filePath = $cacheDir . '/sync_progress.json';
    }

    /**
     * @param array<string, int|string> $stats
     */
    public function update(string $status, string $step, int $percent, string $message, array $stats = [], ?string $liveLine = null): void
    {
        $state = $this->getState();
        $lines = is_array($state['live_lines'] ?? null) ? $state['live_lines'] : [];
        if ($liveLine !== null && $liveLine !== '') {
            $lines[] = '[' . date('H:i:s') . '] ' . $liveLine;
            if (count($lines) > 25) {
                $lines = array_slice($lines, -25);
            }
        }

        $startedAt = isset($state['started_at']) && is_numeric($state['started_at'])
            ? (float) $state['started_at']
            : microtime(true);
        $clampedPercent = max(0, min(100, $percent));
        $etaSeconds = null;
        if ($status === 'running' && $clampedPercent > 0 && $clampedPercent < 100) {
            $elapsed = max(0.1, microtime(true) - $startedAt);
            $totalEstimated = $elapsed / ($clampedPercent / 100);
            $etaSeconds = max(0, (int) round($totalEstimated - $elapsed));
        }

        $payload = [
            'status' => $status,
            'step' => $step,
            'percent' => $clampedPercent,
            'message' => $message,
            'stats' => $stats,
            'live_lines' => $lines,
            'started_at' => $startedAt,
            'eta_seconds' => $etaSeconds,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        $tmp = $this->filePath . '.tmp';
        file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE));
        if (!@rename($tmp, $this->filePath)) {
            @copy($tmp, $this->filePath);
            @unlink($tmp);
        }
    }

    public function reset(): void
    {
        $payload = [
            'status' => 'running',
            'step' => 'download',
            'percent' => 0,
            'message' => 'Synchronisatie gestart...',
            'stats' => ['inserted' => 0, 'updated' => 0, 'total' => 0],
            'live_lines' => ['[' . date('H:i:s') . '] Synchronisatie gestart'],
            'started_at' => microtime(true),
            'eta_seconds' => null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        file_put_contents($this->filePath, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        clearstatcache(true, $this->filePath);
        if (!is_file($this->filePath)) {
            return [
                'status' => 'idle',
                'step' => 'done',
                'percent' => 0,
                'message' => 'Gereed',
                'stats' => [],
                'live_lines' => [],
                'updated_at' => null,
            ];
        }

        $decoded = json_decode((string) file_get_contents($this->filePath), true);
        return is_array($decoded) ? $decoded : ['status' => 'idle', 'percent' => 0, 'live_lines' => []];
    }
}
