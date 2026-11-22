<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\EnvLoader;
use App\Repositories\UserRepository;
use PDO;

final class InstallerService
{
    public static function isInstalled(): bool
    {
        $envFile = dirname(__DIR__, 2) . '/.env';
        if (!is_file($envFile)) {
            return false;
        }

        try {
            EnvLoader::load($envFile);
            $pdo = Database::getInstance();
            return (new UserRepository($pdo))->countUsers() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, array{
     *   id: string,
     *   label: string,
     *   passed: bool,
     *   current_value: string,
     *   fix_title: string,
     *   fix_command: string
     * }>
     */
    public function checkPrerequisites(): array
    {
        $rootDir = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
        $storageDir = realpath($rootDir . '/storage') ?: ($rootDir . '/storage');
        $webUser = $this->detectWebUser();
        $phpIni = php_ini_loaded_file() ?: '/etc/config/php.ini';

        $rootPerms = $this->getOctalPerms($rootDir);
        $storagePerms = $this->getOctalPerms($storageDir);

        return [
            [
                'id' => 'php_version',
                'label' => 'PHP >= 8.2',
                'passed' => version_compare(PHP_VERSION, '8.2.0', '>='),
                'current_value' => PHP_VERSION,
                'fix_title' => 'PHP 8.2 of hoger is vereist voor strict types en moderne OpenSSL/XML functies.',
                'fix_command' => "# QNAP: Schakel PHP 8.2+ in via Web Station / Apache instellingen\n# Of installeer via Entware: opkg update && opkg install php8-cli php8-fpm",
            ],
            $this->extCheck('ext_pdo_mysql', 'pdo_mysql', $phpIni, 'Vereist voor MariaDB/MySQL databaseverbindingen.'),
            $this->extCheck('ext_xmlreader', 'xmlreader', $phpIni, 'Vereist voor streaming EPG XMLTV parsing met laag RAM-verbruik.'),
            $this->extCheck('ext_curl', 'curl', $phpIni, 'Vereist voor het downloaden van externe M3U- en EPG-bestanden.'),
            $this->extCheck('ext_openssl', 'openssl', $phpIni, 'Vereist voor AES-256-GCM encryptie van gevoelige bron-URLs.'),
            [
                'id' => 'perm_root',
                'label' => 'Schrijfrechten root (.env)',
                'passed' => is_writable($rootDir),
                'current_value' => "user: {$webUser}, chmod: {$rootPerms}",
                'fix_title' => "De webserver ({$webUser}) moet .env kunnen aanmaken in {$rootDir}.",
                'fix_command' => "chown -R {$webUser}:administrators {$rootDir} && chmod 775 {$rootDir}",
            ],
            [
                'id' => 'perm_storage',
                'label' => 'Schrijfrechten storage/',
                'passed' => is_writable($storageDir) && is_writable($storageDir . '/cache') && is_writable($storageDir . '/logs'),
                'current_value' => "user: {$webUser}, chmod: {$storagePerms}",
                'fix_title' => "De webserver ({$webUser}) moet cache- en logbestanden kunnen schrijven in {$storageDir}.",
                'fix_command' => "chown -R {$webUser}:administrators {$storageDir} && chmod -R 775 {$storageDir}",
            ],
        ];
    }

    /**
     * @return array{id: string, label: string, passed: bool, current_value: string, fix_title: string, fix_command: string}
     */
    private function extCheck(string $id, string $ext, string $phpIni, string $reason): array
    {
        $loaded = extension_loaded($ext);
        return [
            'id' => $id,
            'label' => "Extensie: {$ext}",
            'passed' => $loaded,
            'current_value' => $loaded ? 'Actief' : 'Ontbreekt',
            'fix_title' => $reason,
            'fix_command' => "# Voeg toe aan {$phpIni} (of schakel in via QNAP Web Station):\nextension={$ext}.so\n# Herstart daarna Apache/PHP-FPM: /etc/init.d/Qthttpd.sh restart",
        ];
    }

    private function detectWebUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && !empty($info['name'])) {
                return (string) $info['name'];
            }
        }
        return get_current_user() ?: 'httpdusr';
    }

    private function getOctalPerms(string $path): string
    {
        $perms = @fileperms($path);
        return $perms !== false ? substr(sprintf('%o', $perms), -4) : '----';
    }

    public function setupDatabaseAndEnv(
        string $host,
        string $port,
        string $dbName,
        string $user,
        string $pass,
        string $appUrl,
        string $subnets
    ): void {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $rootDir = dirname(__DIR__, 2);
        $appKey = bin2hex(random_bytes(32));
        $envContent = implode("\n", [
            'APP_ENV=production',
            "APP_KEY={$appKey}",
            'APP_URL=' . rtrim($appUrl, '/'),
            "DB_HOST={$host}",
            "DB_PORT={$port}",
            "DB_NAME={$dbName}",
            "DB_USER={$user}",
            'DB_PASS="' . str_replace('"', '\"', $pass) . '"',
            "LAN_SUBNET_ALLOW={$subnets}",
            '',
        ]);

        if (file_put_contents($rootDir . '/.env', $envContent) === false) {
            throw new \RuntimeException('Kan .env niet wegschrijven.');
        }

        $sql = file_get_contents($rootDir . '/database/schema.sql');
        if ($sql === false) {
            throw new \RuntimeException('Kan database/schema.sql niet lezen.');
        }
        $pdo->exec($sql);
    }
}
