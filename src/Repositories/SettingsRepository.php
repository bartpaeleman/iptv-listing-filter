<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Security\CryptoService;
use PDO;

final class SettingsRepository
{
    private PDO $pdo;
    private CryptoService $crypto;

    public function __construct(?PDO $pdo = null, ?CryptoService $crypto = null)
    {
        $this->pdo = $pdo ?? Connection::getInstance();
        $this->crypto = $crypto ?? new CryptoService();
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value, is_encrypted FROM settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();

        if (!$row) {
            return $default;
        }

        $value = (string) ($row['setting_value'] ?? '');
        if ((int) $row['is_encrypted'] === 1 && $value !== '') {
            try {
                return $this->crypto->decrypt($value);
            } catch (\Throwable) {
                return $default;
            }
        }

        return $value;
    }

    public function set(string $key, string $value, bool $encrypt = false): void
    {
        $storedValue = ($encrypt && $value !== '') ? $this->crypto->encrypt($value) : $value;
        $isEncrypted = $encrypt ? 1 : 0;

        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, is_encrypted)
             VALUES (:key, :val, :enc)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = VALUES(is_encrypted)'
        );
        $stmt->execute([
            ':key' => $key,
            ':val' => $storedValue,
            ':enc' => $isEncrypted,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function allDecrypted(): array
    {
        $stmt = $this->pdo->query('SELECT setting_key, setting_value, is_encrypted FROM settings');
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) $row['setting_key'];
            $val = (string) ($row['setting_value'] ?? '');
            if ((int) $row['is_encrypted'] === 1 && $val !== '') {
                try {
                    $val = $this->crypto->decrypt($val);
                } catch (\Throwable) {
                    $val = '';
                }
            }
            $result[$key] = $val;
        }
        return $result;
    }
}
