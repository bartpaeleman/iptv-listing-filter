<?php

declare(strict_types=1);

namespace App\Core;

final class FlagRegistry
{
    public const DEFAULT_FALLBACK_ICON = '🏷️';

    /**
     * Central ISO 3166-1 alpha-2 + common IPTV alias flag registry.
     *
     * @var array<string, string>
     */
    public const FLAGS = [
        'NL' => '🇳🇱', 'BE' => '🇧🇪', 'DE' => '🇩🇪', 'FR' => '🇫🇷', 'UK' => '🇬🇧', 'GB' => '🇬🇧', 'EN' => '🇬🇧',
        'US' => '🇺🇸', 'USA' => '🇺🇸', 'ES' => '🇪🇸', 'IT' => '🇮🇹', 'PT' => '🇵🇹', 'TR' => '🇹🇷', 'AR' => '🇸🇦',
        'SA' => '🇸🇦', 'PL' => '🇵🇱', 'RU' => '🇷🇺', 'RO' => '🇷🇴', 'GR' => '🇬🇷', 'SE' => '🇸🇪', 'NO' => '🇳🇴',
        'DK' => '🇩🇰', 'FI' => '🇫🇮', 'CH' => '🇨🇭', 'AT' => '🇦🇹', 'CA' => '🇨🇦', 'AU' => '🇦🇺', 'BR' => '🇧🇷',
        'MX' => '🇲🇽', 'IN' => '🇮🇳', 'PK' => '🇵🇰', 'JP' => '🇯🇵', 'KR' => '🇰🇷', 'CN' => '🇨🇳', 'TW' => '🇹🇼',
        'HK' => '🇭🇰', 'AE' => '🇦🇪', 'UAE' => '🇦🇪', 'EG' => '🇪🇬', 'MA' => '🇲🇦', 'DZ' => '🇩🇿', 'TN' => '🇹🇳',
        'ZA' => '🇿🇦', 'NG' => '🇳🇬', 'IE' => '🇮🇪', 'CZ' => '🇨🇿', 'SK' => '🇸🇰', 'HU' => '🇭🇺', 'BG' => '🇧🇬',
        'HR' => '🇭🇷', 'RS' => '🇷🇸', 'BA' => '🇧🇦', 'SI' => '🇸🇮', 'AL' => '🇦🇱', 'MK' => '🇲🇰', 'UA' => '🇺🇦',
        'BY' => '🇧🇾', 'LT' => '🇱🇹', 'LV' => '🇱🇻', 'EE' => '🇪🇪', 'IL' => '🇮🇱', 'IR' => '🇮🇷', 'IQ' => '🇮🇶',
        'LB' => '🇱🇧', 'JO' => '🇯🇴', 'SY' => '🇸🇾', 'AF' => '🇦🇫', 'TH' => '🇹🇭', 'VN' => '🇻🇳', 'ID' => '🇮🇩',
        'MY' => '🇲🇾', 'PH' => '🇵🇭', 'SG' => '🇸🇬', 'NZ' => '🇳🇿', 'CL' => '🇨🇱', 'CO' => '🇨🇴', 'PE' => '🇵🇪',
        'VE' => '🇻🇪', 'EC' => '🇪🇨', 'UY' => '🇺🇾', 'PY' => '🇵🇾', 'BO' => '🇧🇴', 'CR' => '🇨🇷', 'PA' => '🇵🇦',
        'DO' => '🇩🇴', 'CU' => '🇨🇺', 'PR' => '🇵🇷', 'ARG' => '🇦🇷', 'LU' => '🇱🇺', 'IS' => '🇮🇸', 'MT' => '🇲🇹',
        'CY' => '🇨🇾', 'GE' => '🇬🇪', 'AM' => '🇦🇲', 'AZ' => '🇦🇿', 'KZ' => '🇰🇿', 'UZ' => '🇺🇿', 'EXYU' => '🇷🇸',
    ];

    public static function resolve(string $code): ?string
    {
        $key = strtoupper(trim($code, " \t\n\r\0\x0B|[]()"));
        return self::FLAGS[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::FLAGS;
    }
}
