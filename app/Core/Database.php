<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $instance = null;

    private function __construct()
    {
    }

    public static function getInstance(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        EnvLoader::load();

        $host = EnvLoader::get('DB_HOST', '127.0.0.1');
        $port = EnvLoader::get('DB_PORT', '3306');
        $dbName = EnvLoader::get('DB_NAME', 'iptv_manager');
        $user = EnvLoader::get('DB_USER', '');
        $pass = EnvLoader::get('DB_PASS', '');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbName);

        try {
            self::$instance = new PDO($dsn, (string) $user, (string) $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $offset = (new \DateTimeImmutable('now'))->format('P');
            self::$instance->exec("SET time_zone = '{$offset}'");
        } catch (PDOException $e) {
            throw new RuntimeException('Databaseverbinding mislukt: ' . $e->getMessage(), 0, $e);
        }

        return self::$instance;
    }

    public static function reconnectIfNeeded(): PDO
    {
        if (self::$instance instanceof PDO) {
            try {
                self::$instance->query('SELECT 1');
                return self::$instance;
            } catch (PDOException) {
                self::$instance = null;
            }
        }

        return self::getInstance();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
