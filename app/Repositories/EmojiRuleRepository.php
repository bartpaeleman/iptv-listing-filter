<?php

declare(strict_types=1);

namespace App\Repositories;

final class EmojiRuleRepository extends BaseRepository
{
    protected string $table = 'emoji_rules';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allOrdered(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, match_type, pattern, emoji, label, is_active
             FROM emoji_rules
             ORDER BY match_type ASC, pattern ASC, id ASC'
        );
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allActive(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, match_type, pattern, emoji, label, is_active
             FROM emoji_rules
             WHERE is_active = 1
             ORDER BY match_type ASC, LENGTH(pattern) DESC, pattern ASC'
        );
        return $stmt->fetchAll();
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM emoji_rules')->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, match_type, pattern, emoji, label, is_active
             FROM emoji_rules
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function saveRule(
        string $matchType,
        string $pattern,
        string $emoji,
        ?string $label,
        bool $isActive = true,
        ?int $id = null
    ): int {
        if ($id !== null && $id > 0) {
            $stmt = $this->pdo->prepare(
                'UPDATE emoji_rules
                 SET match_type = :match_type,
                     pattern = :pattern,
                     emoji = :emoji,
                     label = :label,
                     is_active = :is_active
                 WHERE id = :id'
            );
            $stmt->execute([
                ':match_type' => $matchType,
                ':pattern' => $pattern,
                ':emoji' => $emoji,
                ':label' => $label,
                ':is_active' => $isActive ? 1 : 0,
                ':id' => $id,
            ]);
            return $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO emoji_rules (match_type, pattern, emoji, label, is_active)
             VALUES (:match_type, :pattern, :emoji, :label, :is_active)
             ON DUPLICATE KEY UPDATE
                emoji = VALUES(emoji),
                label = VALUES(label),
                is_active = VALUES(is_active)'
        );
        $stmt->execute([
            ':match_type' => $matchType,
            ':pattern' => $pattern,
            ':emoji' => $emoji,
            ':label' => $label,
            ':is_active' => $isActive ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function toggleActive(int $id, bool $isActive): void
    {
        $stmt = $this->pdo->prepare('UPDATE emoji_rules SET is_active = :is_active WHERE id = :id');
        $stmt->execute([
            ':is_active' => $isActive ? 1 : 0,
            ':id' => $id,
        ]);
    }

    public function deleteById(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM emoji_rules WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * @param array<int, array{match_type: string, pattern: string, emoji: string, label: string}> $rules
     */
    public function seedDefaults(array $rules): void
    {
        if ($rules === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO emoji_rules (match_type, pattern, emoji, label, is_active)
             VALUES (:match_type, :pattern, :emoji, :label, 1)'
        );

        foreach ($rules as $rule) {
            $stmt->execute([
                ':match_type' => $rule['match_type'],
                ':pattern' => $rule['pattern'],
                ':emoji' => $rule['emoji'],
                ':label' => $rule['label'],
            ]);
        }
    }
}
