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
     * @return array<int, array{label: string, ok: bool}>
     */
    public function getSystemChecks(): array
    {
        $rootDir = dirname(__DIR__, 2);
        return [
            ['label' => 'PHP >= 8.2 (' . PHP_VERSION . ')', 'ok' => version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['label' => 'Extensie: pdo_mysql', 'ok' => extension_loaded('pdo_mysql')],
            ['label' => 'Extensie: xmlreader', 'ok' => extension_loaded('xmlreader')],
            ['label' => 'Extensie: curl', 'ok' => extension_loaded('curl')],
            ['label' => 'Extensie: openssl', 'ok' => extension_loaded('openssl')],
            ['label' => 'Schrijfrechten root (.env)', 'ok' => is_writable($rootDir)],
            ['label' => 'Schrijfrechten storage/', 'ok' => is_writable($rootDir . '/storage')],
        ];
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
