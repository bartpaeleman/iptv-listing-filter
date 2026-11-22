<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

final class UserRepository extends BaseRepository
{
    protected string $table = 'users';

    /**
     * @return array<string, mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function countUsers(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function createUser(string $username, string $password): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:u, :p)');
        $stmt->execute([
            ':u' => $username,
            ':p' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
