<?php

declare(strict_types=1);

namespace App\Services;

final class CrontabService
{
    private const QNAP_CRONTAB_PATH = '/etc/config/crontab';
    private const SYNC_MARKER = 'bin/sync.php';

    public function getStatus(): array
    {
        $scriptPath = dirname(__DIR__, 2) . '/bin/sync.php';
        $defaultPhp = $this->detectPhpCli();
        $backend = $this->detectBackend();
        $writable = $this->isWritable($backend);

        $lines = $this->readCrontabLines($backend);
        $jobs = [
            'm3u' => ['raw_line' => '', 'current_schedule' => '', 'current_command' => ''],
            'epg' => ['raw_line' => '', 'current_schedule' => '', 'current_command' => ''],
        ];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (!str_contains($trimmed, self::SYNC_MARKER)) {
                continue;
            }
            $type = null;
            if (str_contains($trimmed, '--m3u')) {
                $type = 'm3u';
            } elseif (str_contains($trimmed, '--epg')) {
                $type = 'epg';
            }
            if ($type !== null && $jobs[$type]['raw_line'] === '') {
                $jobs[$type]['raw_line'] = $trimmed;
                if (preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(.+)$/', $trimmed, $m)) {
                    $jobs[$type]['current_schedule'] = $m[1];
                    $jobs[$type]['current_command'] = $m[2];
                }
            }
        }

        $permissionHint = $writable
            ? ''
            : sprintf(
                'Geen schrijfrechten op %s. Voer eenmalig via SSH als admin/root uit: chmod 664 %s && chgrp httpdusr %s (of gebruik sudo crontab -e).',
                $backend === 'qnap' ? self::QNAP_CRONTAB_PATH : 'crontab',
                self::QNAP_CRONTAB_PATH,
                self::QNAP_CRONTAB_PATH
            );

        return [
            'supported' => $backend !== 'none',
            'writable' => $writable,
            'backend' => $backend,
            'jobs' => $jobs,
            'current_schedule' => $jobs['m3u']['current_schedule'],
            'current_command' => $jobs['m3u']['current_command'],
            'raw_line' => $jobs['m3u']['raw_line'],
            'default_php_path' => $defaultPhp,
            'script_path' => $scriptPath,
            'permission_hint' => $permissionHint,
        ];
    }

    public function saveJob(string $schedule, string $phpPath, string $type = 'm3u'): array
    {
        if (!in_array($type, ['m3u', 'epg'], true)) {
            throw new \InvalidArgumentException('Ongeldig cronjob-type. Gebruik m3u of epg.');
        }

        $schedule = trim(preg_replace('/\s+/', ' ', $schedule) ?? '');
        if (!$this->isValidCronSchedule($schedule)) {
            throw new \InvalidArgumentException('Ongeldige cron-expressie. Gebruik 5 velden (bijv. 0 */6 * * *).');
        }

        $phpPath = trim($phpPath);
        if ($phpPath === '' || !preg_match('/^[a-zA-Z0-9_\/\.\-]+$/', $phpPath)) {
            throw new \InvalidArgumentException('Ongeldig PHP-CLI pad.');
        }

        $status = $this->getStatus();
        if (!$status['writable']) {
            throw new \RuntimeException($status['permission_hint'] ?: 'Geen schrijfrechten voor crontab.');
        }

        $scriptPath = $status['script_path'];
        $typeFlag = '--' . $type;
        $newLine = sprintf('%s %s %s %s --force >/dev/null 2>&1', $schedule, $phpPath, $scriptPath, $typeFlag);

        $backend = $status['backend'];
        $lines = $this->readCrontabLines($backend);
        $updated = [];
        $replaced = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            $isTarget = str_contains($line, self::SYNC_MARKER)
                && str_contains($line, $typeFlag)
                && !str_starts_with($trimmed, '#');

            if ($isTarget) {
                if (!$replaced) {
                    $updated[] = $newLine;
                    $replaced = true;
                }
                continue;
            }
            $updated[] = $line;
        }

        if (!$replaced) {
            $updated[] = $newLine;
        }

        $this->writeCrontabLines($backend, $updated);
        return $this->getStatus();
    }

    public function deleteJob(string $type = 'm3u'): array
    {
        if (!in_array($type, ['m3u', 'epg'], true)) {
            throw new \InvalidArgumentException('Ongeldig cronjob-type. Gebruik m3u of epg.');
        }

        $status = $this->getStatus();
        if (!$status['writable']) {
            throw new \RuntimeException($status['permission_hint'] ?: 'Geen schrijfrechten voor crontab.');
        }

        $typeFlag = '--' . $type;
        $backend = $status['backend'];
        $lines = $this->readCrontabLines($backend);
        $filtered = array_values(array_filter($lines, static function (string $line) use ($typeFlag): bool {
            return !(str_contains($line, self::SYNC_MARKER) && str_contains($line, $typeFlag) && !str_starts_with(trim($line), '#'));
        }));

        $this->writeCrontabLines($backend, $filtered);
        return $this->getStatus();
    }

    private function detectPhpCli(): string
    {
        $candidates = [
            '/mnt/ext/opt/apache/bin/php',
            '/opt/bin/php',
            PHP_BINARY,
            '/usr/bin/php',
        ];
        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && @is_executable($path) && !str_contains($path, 'fpm')) {
                return $path;
            }
        }
        return '/usr/bin/php';
    }

    private function detectBackend(): string
    {
        if (@file_exists(self::QNAP_CRONTAB_PATH)) {
            return 'qnap';
        }
        if (function_exists('exec')) {
            @exec('which crontab 2>/dev/null', $out, $code);
            if ($code === 0 && !empty($out)) {
                return 'cli';
            }
        }
        return 'none';
    }

    private function isWritable(string $backend): bool
    {
        if ($backend === 'qnap') {
            return @is_writable(self::QNAP_CRONTAB_PATH);
        }
        if ($backend === 'cli' && function_exists('exec')) {
            return true;
        }
        return false;
    }

    /**
     * @return array<int, string>
     */
    private function readCrontabLines(string $backend): array
    {
        if ($backend === 'qnap') {
            $content = @file_get_contents(self::QNAP_CRONTAB_PATH);
            if ($content === false) {
                return [];
            }
            return explode("\n", str_replace("\r\n", "\n", trim($content)));
        }

        if ($backend === 'cli' && function_exists('exec')) {
            $out = [];
            @exec('crontab -l 2>/dev/null', $out, $code);
            return $code === 0 ? $out : [];
        }

        return [];
    }

    /**
     * @param array<int, string> $lines
     */
    private function writeCrontabLines(string $backend, array $lines): void
    {
        $payload = implode("\n", array_filter($lines, static fn(string $l): bool => $l !== '')) . "\n";

        if ($backend === 'qnap') {
            if (@file_put_contents(self::QNAP_CRONTAB_PATH, $payload, LOCK_EX) === false) {
                throw new \RuntimeException('Kon niet schrijven naar ' . self::QNAP_CRONTAB_PATH);
            }
            if (function_exists('exec')) {
                @exec('crontab ' . escapeshellarg(self::QNAP_CRONTAB_PATH) . ' 2>&1');
                if (@is_executable('/etc/init.d/crond.sh')) {
                    @exec('/etc/init.d/crond.sh restart 2>&1');
                }
            }
            return;
        }

        if ($backend === 'cli' && function_exists('proc_open')) {
            $proc = @proc_open('crontab -', [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            if (!is_resource($proc)) {
                throw new \RuntimeException('Kon crontab proces niet starten.');
            }
            fwrite($pipes[0], $payload);
            fclose($pipes[0]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            if ($code !== 0) {
                throw new \RuntimeException('Crontab opslaan mislukt: ' . trim((string) $err));
            }
        }
    }

    private function isValidCronSchedule(string $schedule): bool
    {
        $parts = explode(' ', $schedule);
        if (count($parts) !== 5) {
            return false;
        }
        foreach ($parts as $part) {
            if (!preg_match('/^[0-9\*\/,\-]+$/', $part)) {
                return false;
            }
        }
        return true;
    }
}
