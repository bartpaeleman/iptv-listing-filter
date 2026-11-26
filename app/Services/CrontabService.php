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

        $permissionHint = '';

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
        $hadExisting = false;
        $updated = array_values(array_filter($lines, static function (string $line) use ($typeFlag, &$hadExisting): bool {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                return $trimmed !== '';
            }
            if (str_contains($trimmed, self::SYNC_MARKER) && str_contains($trimmed, $typeFlag)) {
                $hadExisting = true;
                return false;
            }
            return true;
        }));
        $updated[] = $newLine;

        $this->writeCrontabLines($backend, $updated, $elevation);
        $result = $this->getStatus();
        $result['action'] = $hadExisting ? 'updated' : 'created';
        return $result;
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
        $result = $this->getStatus();
        $result['action'] = 'deleted';
        return $result;
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
        $sudoBin = $this->findExecutable(['/usr/bin/sudo', '/opt/bin/sudo', 'sudo']);
        if ($sudoBin === null || !function_exists('proc_open')) {
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
                '%s -n sh -c %s',
                escapeshellarg($sudoBin),
                escapeshellarg(
                    'cp ' . escapeshellarg($tmpFile) . ' ' . self::QNAP_CRONTAB_PATH
                    . ' && /usr/bin/crontab ' . self::QNAP_CRONTAB_PATH
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
        $sudoBin = $this->findExecutable(['/usr/bin/sudo', '/opt/bin/sudo', 'sudo']);
        if ($sudoBin === null || !function_exists('proc_open')) {
            throw new \RuntimeException('sudo of proc_open is niet beschikbaar op deze server.', 403);
        }
        $tmpFile = @tempnam(sys_get_temp_dir(), 'iptv_cron_');
        if ($tmpFile === false || @file_put_contents($tmpFile, $payload) === false) {
            throw new \RuntimeException('Kon tijdelijk crontab-bestand niet aanmaken.');
        }
        try {
            $shellCmd = 'cp ' . escapeshellarg($tmpFile) . ' ' . self::QNAP_CRONTAB_PATH
                . ' && /usr/bin/crontab ' . self::QNAP_CRONTAB_PATH
                . ' && /etc/init.d/crond.sh restart';
            $proc = @proc_open(
                escapeshellarg($sudoBin) . ' -S -p "" sh -c ' . escapeshellarg($shellCmd),
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
        $hex = bin2hex($payload);
        $phpCli = $this->detectPhpCli();
        $tmpTarget = self::QNAP_CRONTAB_PATH . '.tmp';

        // Pure POSIX printf '%b' octal fallback (0-byte safe for ASCII crontab)
        $octal = '';
        $len = strlen($payload);
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($payload[$i]);
            if ($ord < 32 || $ord > 126 || $payload[$i] === '\\' || $payload[$i] === '%') {
                $octal .= '\\0' . str_pad(decoct($ord), 3, '0', STR_PAD_LEFT);
            } else {
                $octal .= $payload[$i];
            }
        }

        $phpWrite = sprintf(
            '%s -r %s %s %s',
            escapeshellarg($phpCli),
            escapeshellarg('if(file_put_contents($argv[1],hex2bin($argv[2]))===false)exit(1);'),
            escapeshellarg($tmpTarget),
            $hex
        );
        $posixWrite = sprintf('printf %s %s > %s', escapeshellarg('%b'), escapeshellarg($octal), $tmpTarget);

        $remoteCmd = '( ' . $phpWrite . ' || ' . $posixWrite . ' )'
            . ' && mv ' . $tmpTarget . ' ' . self::QNAP_CRONTAB_PATH
            . ' && /usr/bin/crontab ' . self::QNAP_CRONTAB_PATH
            . ' && /etc/init.d/crond.sh restart'
            . ' && (grep -F ' . escapeshellarg(self::SYNC_MARKER) . ' ' . self::QNAP_CRONTAB_PATH . ' || true)';

        $res = $this->executeAsAdminViaSsh($user, $password, $remoteCmd);
        $password = '';
        if (($res['exit_code'] ?? 1) !== 0) {
            $detail = trim((string) ($res['output'] ?? 'Onbekende SSH-fout'));
            throw new \RuntimeException('SSH-uitvoering mislukt: ' . $detail, 403);
        }
    }

    /**
     * @return array{exit_code: int, output: string, active_lines: array<int, string>}
     */
    public function executeAsAdminViaSsh(string $user, string $password, string $remoteCmd, int $port = 22): array
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('proc_open is uitgeschakeld op deze server.', 403);
        }

        $sshBin = $this->findExecutable(['/usr/bin/ssh', '/opt/bin/ssh', 'ssh']);
        if ($sshBin === null) {
            throw new \RuntimeException('OpenSSH client (/usr/bin/ssh) niet gevonden op deze server.', 403);
        }

        $askpassFile = @tempnam(sys_get_temp_dir(), 'iptv_askpass_');
        if ($askpassFile === false) {
            throw new \RuntimeException('Kon tijdelijk SSH_ASKPASS-script niet aanmaken.', 403);
        }

        try {
            $scriptContent = "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg($password) . "\n";
            if (@file_put_contents($askpassFile, $scriptContent) === false) {
                throw new \RuntimeException('Kon tijdelijk SSH_ASKPASS-script niet schrijven.', 403);
            }
            @chmod($askpassFile, 0700);

            $sshCmd = sprintf(
                '%s -T -o LogLevel=ERROR -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PubkeyAuthentication=no -o PreferredAuthentications=password,keyboard-interactive -o ConnectTimeout=8 -p %d %s@127.0.0.1 %s',
                escapeshellarg($sshBin),
                $port,
                escapeshellarg($user),
                escapeshellarg($remoteCmd)
            );

            $env = [
                'SSH_ASKPASS' => $askpassFile,
                'SSH_ASKPASS_REQUIRE' => 'force',
                'DISPLAY' => ':0',
                'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/opt/bin',
            ];

            $runSsh = static function (string $cmd) use ($env): array {
                $proc = @proc_open(
                    $cmd,
                    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    null,
                    $env
                );
                if (!is_resource($proc)) {
                    return ['exit_code' => 1, 'combined' => 'Kon SSH-proces niet starten.'];
                }
                $out = (string) stream_get_contents($pipes[1]);
                $err = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
                return ['exit_code' => $code, 'combined' => trim($out . "\n" . $err)];
            };

            $result = $runSsh($sshCmd);

            // Secundaire fallback voor oudere OpenSSH (< 8.4) zonder SSH_ASKPASS_REQUIRE: gebruik setsid
            if ($result['exit_code'] !== 0) {
                $setsidBin = $this->findExecutable(['/usr/bin/setsid', '/opt/bin/setsid', 'setsid']);
                if ($setsidBin !== null) {
                    $result = $runSsh(escapeshellarg($setsidBin) . ' ' . $sshCmd);
                }
            }

            return [
                'exit_code' => $result['exit_code'],
                'output' => $this->sanitizeSshOutput($result['combined'], $password),
                'active_lines' => $this->extractSyncLines($result['combined']),
            ];
        } finally {
            @unlink($askpassFile);
        }
    }

    /**
     * @param array<int, string> $candidates
     */
    private function findExecutable(array $candidates): ?string
    {
        foreach ($candidates as $bin) {
            if (str_starts_with($bin, '/') && @is_executable($bin)) {
                return $bin;
            }
            if (!str_starts_with($bin, '/') && function_exists('exec')) {
                $out = [];
                @exec('which ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $code);
                if ($code === 0 && !empty($out[0])) {
                    return trim($out[0]);
                }
            }
        }
        return null;
    }

    private function sanitizeSshOutput(string $output, string $password): string
    {
        if ($password !== '') {
            $output = str_replace($password, '***', $output);
        }
        return trim((string) preg_replace('/\r/', '', $output));
    }

    /**
     * @return array<int, string>
     */
    private function extractSyncLines(string $output): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            $t = trim($line);
            if ($t !== '' && str_contains($t, self::SYNC_MARKER) && !str_contains($t, 'echo ')) {
                $lines[] = $t;
            }
        }
        return array_values(array_unique($lines));
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
