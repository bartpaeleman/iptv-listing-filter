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
    public function executeAsAdminViaSsh(string $user, string $password, string $remoteCmd): array
    {
        $sshArgs = sprintf(
            '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=8 -p 22 %s@127.0.0.1 %s',
            escapeshellarg($user),
            escapeshellarg($remoteCmd)
        );

        // Optie 1: Python3 PTY (standaard aanwezig op QNAP QTS)
        $pythonBin = $this->findExecutable(['/usr/bin/python3', '/opt/bin/python3', 'python3']);
        if ($pythonBin !== null && function_exists('proc_open')) {
            $pyScript = <<<'PY'
import os, pty, select, sys, time
cmd = ['/usr/bin/ssh', '-o', 'StrictHostKeyChecking=no', '-o', 'UserKnownHostsFile=/dev/null', '-o', 'ConnectTimeout=8', '-p', '22', sys.argv[1] + '@127.0.0.1', sys.argv[2]]
if not os.path.exists(cmd[0]):
    cmd[0] = 'ssh'
pid, fd = pty.fork()
if pid == 0:
    os.execvp(cmd[0], cmd)
pw = os.environ.get('IPT_SSH_PASS', '')
sent = False
out = bytearray()
deadline = time.time() + 20
while time.time() < deadline:
    r, _, _ = select.select([fd], [], [], 0.5)
    if fd in r:
        try:
            chunk = os.read(fd, 4096)
        except OSError:
            break
        if not chunk:
            break
        out.extend(chunk)
        low = out.lower()
        if not sent and (b'password:' in low or b'assword:' in low):
            os.write(fd, (pw + '\n').encode())
            sent = True
    else:
        wpid, status = os.waitpid(pid, os.WNOHANG)
        if wpid != 0:
            code = os.waitstatus_to_exitcode(status) if hasattr(os, 'waitstatus_to_exitcode') else (status >> 8)
            sys.stdout.buffer.write(out)
            sys.exit(code)
_, status = os.waitpid(pid, 0)
code = os.waitstatus_to_exitcode(status) if hasattr(os, 'waitstatus_to_exitcode') else (status >> 8)
sys.stdout.buffer.write(out)
sys.exit(code)
PY;
            $proc = @proc_open(
                sprintf('%s -c %s %s %s', escapeshellarg($pythonBin), escapeshellarg($pyScript), escapeshellarg($user), escapeshellarg($remoteCmd)),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['IPT_SSH_PASS' => $password, 'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin']
            );
            if (is_resource($proc)) {
                fclose($pipes[0]);
                $out = (string) stream_get_contents($pipes[1]);
                $err = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
                $combined = trim($out . "\n" . $err);
                return [
                    'exit_code' => $code,
                    'output' => $this->sanitizeSshOutput($combined, $password),
                    'active_lines' => $this->extractSyncLines($combined),
                ];
            }
        }

        // Optie 2: `script -q -c` PTY fallback
        $scriptBin = $this->findExecutable(['/usr/bin/script', 'script']);
        if ($scriptBin !== null && function_exists('proc_open')) {
            $sshCmd = 'ssh ' . $sshArgs;
            $proc = @proc_open(
                sprintf('%s -q -c %s /dev/null', escapeshellarg($scriptBin), escapeshellarg($sshCmd)),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (is_resource($proc)) {
                usleep(400000);
                fwrite($pipes[0], $password . "\n");
                fclose($pipes[0]);
                $out = (string) stream_get_contents($pipes[1]);
                $err = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
                $combined = trim($out . "\n" . $err);
                return [
                    'exit_code' => $code,
                    'output' => $this->sanitizeSshOutput($combined, $password),
                    'active_lines' => $this->extractSyncLines($combined),
                ];
            }
        }

        // Optie 3: PHP ssh2_connect
        if (function_exists('ssh2_connect')) {
            $conn = @ssh2_connect('127.0.0.1', 22);
            if ($conn === false) {
                throw new \RuntimeException('Kon geen SSH-verbinding maken met 127.0.0.1:22.', 403);
            }
            if (!@ssh2_auth_password($conn, $user, $password)) {
                throw new \RuntimeException('SSH-authenticatie mislukt voor gebruiker ' . $user . '.', 403);
            }
            $stream = @ssh2_exec($conn, $remoteCmd);
            if ($stream === false) {
                throw new \RuntimeException('Kon SSH-commando niet uitvoeren.', 403);
            }
            stream_set_blocking($stream, true);
            $errStream = @ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
            $out = (string) stream_get_contents($stream);
            $err = $errStream ? (string) stream_get_contents($errStream) : '';
            fclose($stream);
            if ($errStream) {
                fclose($errStream);
            }
            $combined = trim($out . "\n" . $err);
            $failed = str_contains(strtolower($combined), 'permission denied') || str_contains(strtolower($combined), 'error');
            return [
                'exit_code' => $failed ? 1 : 0,
                'output' => $this->sanitizeSshOutput($combined, $password),
                'active_lines' => $this->extractSyncLines($combined),
            ];
        }

        throw new \RuntimeException('Geen geschikte SSH PTY-runner beschikbaar (python3, script of php-ssh2 ontbreekt).', 403);
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
