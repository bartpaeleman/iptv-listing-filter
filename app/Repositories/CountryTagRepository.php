<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

final class CountryTagRepository extends BaseRepository
{
    protected string $table = 'country_tags';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allOrdered(): array
    {
        return $this->pdo->query(
            'SELECT id, tag_pattern, country_code, country_name, flag_emoji, auto_group, sort_order
             FROM country_tags
             ORDER BY sort_order ASC, id ASC'
        )->fetchAll();
    }

    public function saveTag(string $pattern, string $countryCode, string $countryName, string $flagEmoji, int $sortOrder = 0): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO country_tags (tag_pattern, country_code, country_name, flag_emoji, sort_order)
             VALUES (:pat, :code, :name, :flag, :sort)
             ON DUPLICATE KEY UPDATE country_code = VALUES(country_code), country_name = VALUES(country_name), flag_emoji = VALUES(flag_emoji), sort_order = VALUES(sort_order)'
        );
        $stmt->execute([
            ':pat' => trim($pattern),
            ':code' => strtoupper(trim($countryCode)),
            ':name' => trim($countryName),
            ':flag' => trim($flagEmoji),
            ':sort' => $sortOrder,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
