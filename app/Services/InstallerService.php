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

    /**
     * @return array{app_user: string, app_pass: string}
     */
    public function generateAppUserCredentials(string $dbName = 'iptv_manager'): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*-_';
        $max = strlen($alphabet) - 1;
        $pass = '';
        for ($i = 0; $i < 24; $i++) {
            $pass .= $alphabet[random_int(0, $max)];
        }

        return [
            'app_user' => 'iptv_user',
            'app_pass' => $pass,
        ];
    }

    /**
     * @param array{
     *   db_host: string,
     *   db_port: string,
     *   db_name: string,
     *   admin_user: string,
     *   admin_pass: string,
     *   app_user: string,
     *   app_pass: string,
     *   app_url: string,
     *   lan_subnets: string,
     *   use_existing_user: bool
     * } $config
     * @return array{app_user: string, db_name: string}
     */
    public function provisionDatabaseAndUser(array $config): array
    {
        $host = trim($config['db_host']);
        $port = trim($config['db_port']);
        $dbName = trim($config['db_name']);
        $appUser = trim($config['app_user']);
        $appPass = $config['app_pass'];
        $useExisting = (bool) ($config['use_existing_user'] ?? false);

        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $dbName)) {
            throw new \RuntimeException('Ongeldige databasenaam (enkel letters, cijfers en _ toegestaan).');
        }
        if (!preg_match('/^[a-zA-Z0-9_]{1,32}$/', $appUser)) {
            throw new \RuntimeException('Ongeldige applicatie-gebruikersnaam (enkel letters, cijfers en _ toegestaan).');
        }

        if (!$useExisting) {
            $adminDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);
            $adminPdo = new PDO($adminDsn, $config['admin_user'], $config['admin_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $adminPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            foreach (['localhost', '127.0.0.1'] as $hostScope) {
                $stmt = $adminPdo->prepare("CREATE USER IF NOT EXISTS :u@'{$hostScope}' IDENTIFIED BY :p");
                $stmt->execute([':u' => $appUser, ':p' => $appPass]);

                $alter = $adminPdo->prepare("ALTER USER :u@'{$hostScope}' IDENTIFIED BY :p");
                $alter->execute([':u' => $appUser, ':p' => $appPass]);

                $grant = $adminPdo->prepare(
                    "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP ON `{$dbName}`.* TO :u@'{$hostScope}'"
                );
                $grant->execute([':u' => $appUser]);
            }

            $adminPdo->exec('FLUSH PRIVILEGES');
            unset($adminPdo);
        }

        // Clear admin credentials from memory
        $config['admin_user'] = '';
        $config['admin_pass'] = '';

        // Verify restricted user connection & import schema
        $appDsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
        $appPdo = new PDO($appDsn, $appUser, $appPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $rootDir = dirname(__DIR__, 2);
        $sql = file_get_contents($rootDir . '/database/schema.sql');
        if ($sql === false) {
            throw new \RuntimeException('Kan database/schema.sql niet lezen.');
        }
        $appPdo->exec($sql);

        $appKey = bin2hex(random_bytes(32));
        $envContent = implode("\n", [
            'APP_ENV=production',
            "APP_KEY={$appKey}",
            'APP_URL=' . rtrim($config['app_url'], '/'),
            "DB_HOST={$host}",
            "DB_PORT={$port}",
            "DB_NAME={$dbName}",
            "DB_USER={$appUser}",
            'DB_PASS="' . str_replace('"', '\"', $appPass) . '"',
            "LAN_SUBNET_ALLOW={$config['lan_subnets']}",
            '',
        ]);

        if (file_put_contents($rootDir . '/.env', $envContent) === false) {
            throw new \RuntimeException('Kan .env niet wegschrijven.');
        }

        return ['app_user' => $appUser, 'db_name' => $dbName];
    }
}
