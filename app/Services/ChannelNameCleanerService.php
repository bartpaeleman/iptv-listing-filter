<?php

declare(strict_types=1);

namespace App\Services;

final class ChannelNameCleanerService
{
    private const QUALITY_TOKENS = 'SD|HD|FHD|UHD|4K|8K|HEVC|H\.?265|RAW';

    public static function stripQualityTags(string $name): string
    {
        $original = trim($name);
        if ($original === '') {
            return '';
        }

        // Remove bracketed quality tags like [HD], (FHD), [4K], (H.265)
        $cleaned = preg_replace('/[\[\(]\s*(?:' . self::QUALITY_TOKENS . ')\s*[\]\)]/iu', ' ', $original);
        // Remove standalone word-boundary quality tokens
        $cleaned = preg_replace('/\b(?:' . self::QUALITY_TOKENS . ')\b/iu', ' ', (string) $cleaned);
        // Collapse multiple spaces
        $cleaned = preg_replace('/\s{2,}/u', ' ', (string) $cleaned);
        // Trim whitespace and trailing/leading separators (-, |, :)
        $cleaned = trim((string) $cleaned, " \t\n\r\0\x0B-|:");
        $cleaned = trim((string) $cleaned);

        return $cleaned !== '' ? $cleaned : $original;
    }

    public static function matchesQuality(string $name, string $quality): bool
    {
        $q = strtoupper(trim($quality));
        if ($q === '') {
            return false;
        }

        $pattern = $q === 'HEVC'
            ? '(?:HEVC|H\.?265)'
            : preg_quote($q, '/');

        return preg_match('/(?:[\[\(]\s*' . $pattern . '\s*[\]\)]|\b' . $pattern . '\b)/iu', $name) === 1;
    }

    public static function isCommentLine(string $name): bool
    {
        return substr_count($name, '#') >= 3;
    }
}
