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

    public function saveJob(string $schedule, string $phpPath, string $type = 'm3u', array $elevation = []): array
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
        $scriptPath = $status['script_path'];
        $typeFlag = '--' . $type;
        $newLine = sprintf('%s %s %s %s --force >/dev/null 2>&1', $schedule, $phpPath, $scriptPath, $typeFlag);

        $backend = $status['backend'];
        $lines = $this->readCrontabLines($backend);
        $updated = array_values(array_filter($lines, static function (string $line) use ($typeFlag): bool {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                return $trimmed !== '';
            }
            return !(str_contains($trimmed, self::SYNC_MARKER) && str_contains($trimmed, $typeFlag));
        }));
        $updated[] = $newLine;

        $this->writeCrontabLines($backend, $updated, $elevation);
        return $this->getStatus();
    }

    public function deleteJob(string $type = 'm3u', array $elevation = []): array
    {
        if (!in_array($type, ['m3u', 'epg'], true)) {
            throw new \InvalidArgumentException('Ongeldig cronjob-type. Gebruik m3u of epg.');
        }

        $status = $this->getStatus();
        $typeFlag = '--' . $type;
        $backend = $status['backend'];
        $lines = $this->readCrontabLines($backend);
        $filtered = array_values(array_filter($lines, static function (string $line) use ($typeFlag): bool {
            $trimmed = trim($line);
            if ($trimmed === '') {
                return false;
            }
            if (str_starts_with($trimmed, '#')) {
                return true;
            }
            return !(str_contains($trimmed, self::SYNC_MARKER) && str_contains($trimmed, $typeFlag));
        }));

        $this->writeCrontabLines($backend, $filtered, $elevation);
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
     * @param array{auth_mode?: string, ssh_user?: string, ssh_password?: string} $elevation
     */
    private function writeCrontabLines(string $backend, array $lines, array $elevation = []): void
    {
        $payload = implode("\n", array_filter($lines, static fn(string $l): bool => $l !== '')) . "\n";
        $authMode = trim((string) ($elevation['auth_mode'] ?? ''));
        $sshUser = trim((string) ($elevation['ssh_user'] ?? 'admin'));
        if ($sshUser === '') {
            $sshUser = 'admin';
        }
        $sshPassword = (string) ($elevation['ssh_password'] ?? '');
        $elevation['ssh_password'] = '';

        try {
            if ($backend === 'qnap') {
                if (@is_writable(self::QNAP_CRONTAB_PATH) && $authMode === '') {
                    if (@file_put_contents(self::QNAP_CRONTAB_PATH, $payload, LOCK_EX) === false) {
                        throw new \RuntimeException('Kon niet schrijven naar ' . self::QNAP_CRONTAB_PATH);
                    }
                    $this->reloadQnapCrontab();
                    return;
                }

                if ($authMode === '') {
                    if ($this->trySudoNoPassQnap($payload)) {
                        return;
                    }
                    throw new \RuntimeException(
                        'Geen schrijfrechten op ' . self::QNAP_CRONTAB_PATH . '. Kies Sudo of QNAP SSH Login om op te slaan.',
                        403
                    );
                }

                if ($authMode === 'sudo') {
                    if ($sshPassword === '') {
                        if ($this->trySudoNoPassQnap($payload)) {
                            return;
                        }
                        throw new \RuntimeException('Vul het sudo-wachtwoord in.', 403);
                    }
                    $this->writeQnapWithSudo($payload, $sshPassword);
                    return;
                }

                if ($authMode === 'ssh') {
                    if ($sshPassword === '') {
                        throw new \RuntimeException('Vul het SSH-wachtwoord in.', 403);
                    }
                    if (!preg_match('/^[a-zA-Z0-9_.\-]+$/', $sshUser)) {
                        throw new \InvalidArgumentException('Ongeldige SSH-gebruikersnaam.');
                    }
                    $this->writeQnapWithSsh($payload, $sshUser, $sshPassword);
                    return;
                }

                throw new \InvalidArgumentException('Ongeldige auth_mode. Gebruik sudo of ssh.');
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
                return;
            }

            throw new \RuntimeException('Crontab wordt op deze server niet ondersteund.');
        } finally {
            $sshPassword = '';
        }
    }

    private function reloadQnapCrontab(): void
    {
        if (!function_exists('exec')) {
            return;
        }
        $cmd = 'crontab ' . escapeshellarg(self::QNAP_CRONTAB_PATH);
        if (@is_executable('/etc/init.d/crond.sh')) {
            $cmd .= ' && /etc/init.d/crond.sh restart';
        }
        @exec($cmd . ' 2>&1');
    }

    private function trySudoNoPassQnap(string $payload): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $tmpFile = @tempnam(sys_get_temp_dir(), 'iptv_cron_');
        if ($tmpFile === false) {
            return false;
        }
        try {
            if (@file_put_contents($tmpFile, $payload) === false) {
                return false;
            }
            $cmd = sprintf(
                'sudo -n sh -c %s',
                escapeshellarg(
                    'cp ' . escapeshellarg($tmpFile) . ' ' . self::QNAP_CRONTAB_PATH
                    . ' && crontab ' . self::QNAP_CRONTAB_PATH
                    . ' && /etc/init.d/crond.sh restart'
                )
            );
            $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) {
                return false;
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            return proc_close($proc) === 0;
        } finally {
            @unlink($tmpFile);
        }
    }

    private function writeQnapWithSudo(string $payload, string $password): void
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('proc_open is uitgeschakeld op deze server.');
        }
        $tmpFile = @tempnam(sys_get_temp_dir(), 'iptv_cron_');
        if ($tmpFile === false || @file_put_contents($tmpFile, $payload) === false) {
            throw new \RuntimeException('Kon tijdelijk crontab-bestand niet aanmaken.');
        }
        try {
            $shellCmd = 'cp ' . escapeshellarg($tmpFile) . ' ' . self::QNAP_CRONTAB_PATH
                . ' && crontab ' . self::QNAP_CRONTAB_PATH
                . ' && /etc/init.d/crond.sh restart';
            $proc = @proc_open(
                'sudo -S -p "" sh -c ' . escapeshellarg($shellCmd),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($proc)) {
                throw new \RuntimeException('Kon sudo-proces niet starten.');
            }
            fwrite($pipes[0], $password . "\n");
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            if ($code !== 0) {
                throw new \RuntimeException('Sudo crontab-update mislukt: ' . trim((string) ($err ?: $out)));
            }
        } finally {
            @unlink($tmpFile);
        }
    }

    private function writeQnapWithSsh(string $payload, string $user, string $password): void
    {
        $b64 = base64_encode($payload);
        $remoteCmd = 'echo ' . escapeshellarg($b64) . ' | base64 -d > ' . self::QNAP_CRONTAB_PATH
            . ' && crontab ' . self::QNAP_CRONTAB_PATH
            . ' && /etc/init.d/crond.sh restart';

        if (function_exists('ssh2_connect')) {
            $conn = @ssh2_connect('127.0.0.1', 22);
            if ($conn === false) {
                throw new \RuntimeException('Kon geen SSH-verbinding maken met 127.0.0.1:22.');
            }
            if (!@ssh2_auth_password($conn, $user, $password)) {
                throw new \RuntimeException('SSH-authenticatie mislukt voor gebruiker ' . $user . '.', 403);
            }
            $stream = @ssh2_exec($conn, $remoteCmd);
            if ($stream === false) {
                throw new \RuntimeException('Kon SSH-commando niet uitvoeren.');
            }
            stream_set_blocking($stream, true);
            $errStream = @ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
            $out = stream_get_contents($stream);
            $err = $errStream ? stream_get_contents($errStream) : '';
            fclose($stream);
            if ($errStream) {
                fclose($errStream);
            }
            if (trim((string) $err) !== '' && str_contains(strtolower((string) $err), 'permission denied')) {
                throw new \RuntimeException('SSH-commando geweigerd: ' . trim((string) $err), 403);
            }
            return;
        }

        if (function_exists('proc_open')) {
            @exec('which sshpass 2>/dev/null', $whichOut, $whichCode);
            if ($whichCode === 0 && !empty($whichOut)) {
                $cmd = sprintf(
                    'sshpass -e ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 %s@127.0.0.1 %s',
                    escapeshellarg($user),
                    escapeshellarg($remoteCmd)
                );
                $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['SSHPASS' => $password]);
                if (!is_resource($proc)) {
                    throw new \RuntimeException('Kon sshpass-proces niet starten.');
                }
                fclose($pipes[0]);
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
                if ($code !== 0) {
                    throw new \RuntimeException('SSH crontab-update mislukt: ' . trim((string) ($err ?: $out)));
                }
                return;
            }
        }

        throw new \RuntimeException('Geen SSH-client beschikbaar (installeer PHP ssh2-extensie of sshpass, of gebruik Sudo).');
    }

    /**
     * @return array{source: string, content: string, m3u_rule: ?string, epg_rule: ?string}
     */
    public function readRawCrontab(): array
    {
        $source = 'crontab -l';
        $content = '';

        if (@is_file(self::QNAP_CRONTAB_PATH) && @is_readable(self::QNAP_CRONTAB_PATH)) {
            $source = self::QNAP_CRONTAB_PATH;
            $raw = @file_get_contents(self::QNAP_CRONTAB_PATH);
            $content = $raw !== false ? $raw : '';
        } elseif (function_exists('shell_exec')) {
            $raw = @shell_exec('crontab -l 2>&1');
            $content = is_string($raw) ? $raw : '';
        }

        $m3uRule = null;
        $epgRule = null;
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, self::SYNC_MARKER)) {
                continue;
            }
            if ($m3uRule === null && str_contains($trimmed, '--m3u')) {
                $m3uRule = $trimmed;
            } elseif ($epgRule === null && str_contains($trimmed, '--epg')) {
                $epgRule = $trimmed;
            }
        }

        return [
            'source' => $source,
            'content' => $content,
            'm3u_rule' => $m3uRule,
            'epg_rule' => $epgRule,
        ];
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
