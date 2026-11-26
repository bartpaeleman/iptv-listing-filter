<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;

final class CrontabManagerService
{
    private const CRONTAB_PATH = '/etc/config/crontab';
    private const VALID_MODES = ['direct', 'sudo_nopasswd', 'sudo_password'];

    private SettingsRepository $settings;

    public function __construct(?SettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new SettingsRepository();
    }

    public function getExecMode(): string
    {
        $mode = (string) $this->settings->get('cron_exec_mode', 'direct');
        return in_array($mode, self::VALID_MODES, true) ? $mode : 'direct';
    }

    /**
     * @return array{can_write: bool, mode: string, message: string, fix_command: string}
     */
    public function getStatus(): array
    {
        $mode = $this->getExecMode();
        if (!file_exists(self::CRONTAB_PATH)) {
            return [
                'can_write' => false,
                'mode' => $mode,
                'message' => 'Bestand /etc/config/crontab niet gevonden op deze server.',
                'fix_command' => 'chmod 664 /etc/config/crontab && chgrp httpdusr /etc/config/crontab',
            ];
        }

        if ($mode === 'direct') {
            $writable = is_writable(self::CRONTAB_PATH);
            return [
                'can_write' => $writable,
                'mode' => $mode,
                'message' => $writable
                    ? 'Directe schrijfrechten op /etc/config/crontab zijn actief.'
                    : 'Geen schrijfrechten op /etc/config/crontab als webserver-gebruiker (httpdusr).',
                'fix_command' => 'chmod 664 /etc/config/crontab && chgrp httpdusr /etc/config/crontab',
            ];
        }

        if ($mode === 'sudo_nopasswd') {
            $out = [];
            $code = 0;
            @exec('sudo -n true 2>&1', $out, $code);
            $ok = ($code === 0);
            return [
                'can_write' => $ok,
                'mode' => $mode,
                'message' => $ok
                    ? 'Wachtwoordloze sudo-rechten zijn actief.'
                    : 'Wachtwoordloze sudo (sudo -n) is niet geconfigureerd voor httpdusr.',
                'fix_command' => 'echo "httpdusr ALL=(ALL) NOPASSWD: /bin/tee, /usr/bin/crontab, /etc/init.d/crond.sh" >> /etc/sudoers',
            ];
        }

        // sudo_password
        $pass = (string) $this->settings->get('cron_sudo_password', '');
        if ($pass === '') {
            return [
                'can_write' => false,
                'mode' => $mode,
                'message' => 'Geen sudo/root wachtwoord opgeslagen.',
                'fix_command' => 'echo "httpdusr ALL=(ALL) NOPASSWD: /bin/tee, /usr/bin/crontab, /etc/init.d/crond.sh" >> /etc/sudoers',
            ];
        }

        $out = [];
        $code = 0;
        $cmd = sprintf('printf \'%%s\n\' %s | sudo -S -p \'\' true 2>&1', escapeshellarg($pass));
        @exec($cmd, $out, $code);
        $ok = ($code === 0);

        return [
            'can_write' => $ok,
            'mode' => $mode,
            'message' => $ok
                ? 'Sudo-authenticatie met opgeslagen wachtwoord geslaagd.'
                : 'Sudo-authenticatie met opgeslagen wachtwoord mislukt.',
            'fix_command' => 'echo "httpdusr ALL=(ALL) NOPASSWD: /bin/tee, /usr/bin/crontab, /etc/init.d/crond.sh" >> /etc/sudoers',
        ];
    }

    public function writeCrontab(string $content): void
    {
        $mode = $this->getExecMode();

        if ($mode === 'direct') {
            if (!is_writable(self::CRONTAB_PATH)) {
                throw new \RuntimeException('Geen schrijfrechten op /etc/config/crontab.');
            }
            if (@file_put_contents(self::CRONTAB_PATH, $content, LOCK_EX) === false) {
                throw new \RuntimeException('Schrijven naar /etc/config/crontab mislukt.');
            }
            $this->restartCron();
            return;
        }

        $cacheDir = dirname(__DIR__, 2) . '/storage/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        $tmpFile = $cacheDir . '/crontab_' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmpFile, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Kon tijdelijk crontab-bestand niet aanmaken in storage/cache.');
        }

        try {
            $out = [];
            $code = 0;
            if ($mode === 'sudo_nopasswd') {
                $cmd = sprintf('sudo -n tee %s < %s >/dev/null 2>&1', escapeshellarg(self::CRONTAB_PATH), escapeshellarg($tmpFile));
                @exec($cmd, $out, $code);
            } else {
                $pass = (string) $this->settings->get('cron_sudo_password', '');
                if ($pass === '') {
                    throw new \RuntimeException('Geen sudo/root wachtwoord geconfigureerd.');
                }
                $cmd = sprintf(
                    'printf \'%%s\n\' %s | sudo -S -p \'\' tee %s < %s >/dev/null 2>&1',
                    escapeshellarg($pass),
                    escapeshellarg(self::CRONTAB_PATH),
                    escapeshellarg($tmpFile)
                );
                @exec($cmd, $out, $code);
            }

            if ($code !== 0) {
                $errText = $this->maskOutput(implode(' ', $out));
                AppLogger::log('CRON', 'ERROR', 'Schrijven naar crontab via sudo mislukt.', ['mode' => $mode, 'exit_code' => $code, 'output' => $errText]);
                throw new \RuntimeException('Schrijven naar /etc/config/crontab via sudo mislukt: ' . ($errText !== '' ? $errText : "exit code {$code}"));
            }
        } finally {
            @unlink($tmpFile);
        }

        $this->restartCron();
    }

    public function restartCron(): string
    {
        $mode = $this->getExecMode();
        $out = [];
        $code = 0;

        if ($mode === 'direct') {
            @exec('crontab /etc/config/crontab && /etc/init.d/crond.sh restart 2>&1', $out, $code);
        } elseif ($mode === 'sudo_nopasswd') {
            @exec('sudo -n crontab /etc/config/crontab && sudo -n /etc/init.d/crond.sh restart 2>&1', $out, $code);
        } else {
            $pass = (string) $this->settings->get('cron_sudo_password', '');
            if ($pass === '') {
                AppLogger::log('CRON', 'ERROR', 'Crontab herstarten mislukt: geen sudo wachtwoord ingesteld.');
                throw new \RuntimeException('Geen sudo/root wachtwoord geconfigureerd.');
            }
            $cmd = sprintf(
                'printf \'%%s\n\' %s | sudo -S -p \'\' sh -c %s 2>&1',
                escapeshellarg($pass),
                escapeshellarg('crontab /etc/config/crontab && /etc/init.d/crond.sh restart')
            );
            @exec($cmd, $out, $code);
        }

        $outputText = $this->maskOutput(trim(implode("\n", $out)));
        if ($code !== 0) {
            AppLogger::log('CRON', 'ERROR', 'Crontab herstarten mislukt.', [
                'mode' => $mode,
                'exit_code' => $code,
                'output' => $outputText,
            ]);
            throw new \RuntimeException('Crontab herstarten mislukt' . ($outputText !== '' ? ': ' . $outputText : " (exit code {$code})."));
        }

        AppLogger::log('CRON', 'SUCCESS', 'Crontab succesvol herstart.', ['mode' => $mode]);
        return $outputText !== '' ? $outputText : 'Crontab succesvol herstart.';
    }

    private function maskOutput(string $text): string
    {
        $pass = (string) $this->settings->get('cron_sudo_password', '');
        if ($pass !== '') {
            $text = str_replace($pass, '***', $text);
        }
        return $text;
    }
}
