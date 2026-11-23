<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class GroupCategoryService
{
    public const CATEGORIES = ['Live TV', 'Sport', 'Animatie', 'TV Series', 'Film', 'Muziek', 'Adult'];

    /**
     * @param array<int, array{pattern: string, category: string}> $customRules
     */
    public static function classify(string $groupName, array $customRules = []): string
    {
        $name = trim($groupName);
        if ($name === '') {
            return 'Live TV';
        }

        foreach ($customRules as $rule) {
            $pattern = trim((string) ($rule['pattern'] ?? ''));
            $cat = (string) ($rule['category'] ?? 'Live TV');
            if ($pattern !== '' && in_array($cat, self::CATEGORIES, true) && mb_stripos($name, $pattern, 0, 'UTF-8') !== false) {
                return $cat;
            }
        }

        $lower = mb_strtolower($name, 'UTF-8');
        if (preg_match('/\b(adult|xxx|18\+|erotic|erotiek|porn|playboy|hustler|brazzers|vivid|dorcel|private|sex|nsfw)\b/u', $lower) || str_contains($lower, '18+')) {
            return 'Adult';
        }
        if (preg_match('/\b(muziek|music|concert|concerten|radio|mtv|hits|dj|schlager|klassiek|classical)\b/u', $lower)) {
            return 'Muziek';
        }
        if (preg_match('/\b(sport|sports|voetbal|football|soccer|f1|formula|motorsport|tennis|golf|ufc|boxing|dazn|espn|viaplay)\b/u', $lower)) {
            return 'Sport';
        }
        if (preg_match('/\b(animatie|animation|anime|cartoon|kids|kinderen|disney|nick|nickelodeon|jeugd)\b/u', $lower)) {
            return 'Animatie';
        }
        if (preg_match('/\b(tv\s*series?|series?|show|shows|seizoen)\b/u', $lower)) {
            return 'TV Series';
        }
        if (preg_match('/\b(film|films|movie|movies|cinema|vod|bioscoop)\b/u', $lower)) {
            return 'Film';
        }

        return 'Live TV';
    }

    public function classifyUnlockedGroups(?PDO $pdo = null): int
    {
        $pdo = $pdo ?? Database::getInstance();
        $rules = $pdo->query(
            'SELECT pattern, category FROM group_category_rules WHERE is_active = 1 ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $rows = $pdo->query(
            'SELECT id, original_name, category FROM m3u_groups WHERE category_locked = 0'
        )->fetchAll(PDO::FETCH_ASSOC);

        $upd = $pdo->prepare('UPDATE m3u_groups SET category = :cat WHERE id = :id');
        $updated = 0;
        foreach ($rows as $row) {
            $newCat = self::classify((string) $row['original_name'], $rules);
            if ($newCat !== (string) $row['category']) {
                $upd->execute([':cat' => $newCat, ':id' => (int) $row['id']]);
                $updated++;
            }
        }

        return $updated;
    }
}
