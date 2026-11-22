<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\EmojiRuleRepository;
use PDO;

final class EmojiTagService
{
    public const FALLBACK_PATTERN = '_FALLBACK_';
    public const DEFAULT_FALLBACK_EMOJI = '🏷️';

    private EmojiRuleRepository $repo;

    /** @var array<string, string>|null */
    private ?array $countryMap = null;

    /** @var array<int, array{pattern: string, emoji: string}>|null */
    private ?array $keywordRules = null;

    private string $fallbackEmoji = self::DEFAULT_FALLBACK_EMOJI;

    public function __construct(?PDO $pdo = null, ?EmojiRuleRepository $repo = null)
    {
        $this->repo = $repo ?? new EmojiRuleRepository($pdo ?? Database::getInstance());
        $this->rulesCacheFile = dirname(__DIR__, 2) . '/storage/cache/emoji_rules.json';
    }

    public function getRulesCacheFile(): string
    {
        if (!isset($this->rulesCacheFile) || $this->rulesCacheFile === '') {
            $this->rulesCacheFile = dirname(__DIR__, 2) . '/storage/cache/emoji_rules.json';
        }
        return $this->rulesCacheFile;
    }

    public function seedDefaultsIfEmpty(): void
    {
        if ($this->repo->countAll() === 0) {
            $this->repo->seedDefaults(self::getDefaultRules());
            $this->resetCache();
        }
    }

    public function resetCache(): void
    {
        $this->countryMap = null;
        $this->keywordRules = null;
        $this->fallbackEmoji = self::DEFAULT_FALLBACK_EMOJI;
    }

    public static function invalidateRulesCache(): void
    {
        $cacheFile = dirname(__DIR__, 2) . '/storage/cache/emoji_rules.json';
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    public function formatGroupName(string $rawName): string
    {
        return $this->parseName($rawName, true)['display_name'];
    }

    public function formatChannelName(string $rawName): string
    {
        return $this->parseName($rawName, false)['display_name'];
    }

    /**
     * Parseert een ruwe groep- of kanaalnaam op basis van de centrale emoji_rules tabel.
     * Laat de originele naam ongemoeid.
     *
     * @return array{
     *     original_name: string,
     *     clean_name: string,
     *     country_code: string|null,
     *     country_emoji: string|null,
     *     keyword_emojis: array<int, string>,
     *     display_name: string
     * }
     */
    public function parseName(string $rawName, bool $includeKeywords = true): array
    {
        $this->loadRules();

        $cleanName = trim($rawName);
        $countryCode = null;
        $countryEmoji = null;

        // Stap A: Herken landtag vooraan met optionele |-tekens: |BE|, BE|, |BE
        if (preg_match('/^\s*(?:\|([A-Za-z0-9]{2,6})\||([A-Za-z0-9]{2,6})\||\|([A-Za-z0-9]{2,6})(?=\s|$))\s*(.*)$/u', $rawName, $matches)) {
            $rawCode = strtoupper($matches[1] !== '' ? $matches[1] : ($matches[2] !== '' ? $matches[2] : $matches[3]));
            $rest = trim($matches[4]);
            $countryCode = $rawCode;
            $countryEmoji = $this->countryMap[$rawCode] ?? $this->fallbackEmoji;
            if ($rest !== '') {
                $cleanName = $rest;
            }
        } elseif (preg_match('/^\s*(?:\[([A-Za-z0-9]{2,6})\]|([A-Za-z0-9]{2,6})(?:\s+-\s+|:\s+))\s*(.*)$/u', $rawName, $matches)) {
            $rawCode = strtoupper($matches[1] !== '' ? $matches[1] : $matches[2]);
            if (isset($this->countryMap[$rawCode])) {
                $rest = trim($matches[3]);
                $countryCode = $rawCode;
                $countryEmoji = $this->countryMap[$rawCode];
                if ($rest !== '') {
                    $cleanName = $rest;
                }
            }
        } elseif (preg_match('/^\s*\|\s*(.+)$/u', $rawName, $pipeMatches)) {
            // Los |-teken zonder landcode (bijv. "| DISNEY+ SERIES"): strip enkel het |-teken
            $cleanName = trim($pipeMatches[1]);
        }

        $keywordEmojis = [];
        if ($includeKeywords && $this->keywordRules !== null) {
            $searchTarget = $cleanName !== '' ? $cleanName : $rawName;
            foreach ($this->keywordRules as $rule) {
                if (mb_stripos($searchTarget, $rule['pattern'], 0, 'UTF-8') !== false) {
                    if (!in_array($rule['emoji'], $keywordEmojis, true)) {
                        $keywordEmojis[] = $rule['emoji'];
                    }
                }
            }
        }

        $prefixParts = [];
        if ($countryEmoji !== null) {
            $prefixParts[] = $countryEmoji;
        }
        foreach ($keywordEmojis as $kwEmoji) {
            $prefixParts[] = $kwEmoji;
        }

        $displayName = $prefixParts !== []
            ? trim(implode(' ', $prefixParts) . ' ' . $cleanName)
            : $cleanName;

        return [
            'original_name' => $rawName,
            'clean_name' => $cleanName,
            'country_code' => $countryCode,
            'country_emoji' => $countryEmoji,
            'keyword_emojis' => $keywordEmojis,
            'display_name' => $displayName,
        ];
    }

    public static function isoToFlag(string $iso): string
    {
        $iso = strtoupper(trim($iso));
        if (!preg_match('/^[A-Z]{2}$/', $iso)) {
            return self::DEFAULT_FALLBACK_EMOJI;
        }

        $first = mb_chr(0x1F1E6 + ord($iso[0]) - ord('A'), 'UTF-8');
        $second = mb_chr(0x1F1E6 + ord($iso[1]) - ord('A'), 'UTF-8');

        return ($first !== false && $second !== false) ? ($first . $second) : self::DEFAULT_FALLBACK_EMOJI;
    }

    private function loadRules(): void
    {
        if ($this->countryMap !== null && $this->keywordRules !== null) {
            return;
        }

        $cacheFile = $this->getRulesCacheFile();
        if (is_file($cacheFile) && is_readable($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['countryMap'], $cached['keywordRules'])) {
                $this->countryMap = $cached['countryMap'];
                $this->keywordRules = $cached['keywordRules'];
                $this->fallbackEmoji = (string) ($cached['fallbackEmoji'] ?? self::DEFAULT_FALLBACK_EMOJI);
                return;
            }
        }

        $this->countryMap = [];
        $this->keywordRules = [];
        $this->fallbackEmoji = self::DEFAULT_FALLBACK_EMOJI;

        foreach ($this->repo->allActive() as $row) {
            $type = (string) $row['match_type'];
            $pattern = (string) $row['pattern'];
            $emoji = (string) $row['emoji'];

            if ($type === 'country') {
                $upper = strtoupper($pattern);
                if ($upper === self::FALLBACK_PATTERN) {
                    $this->fallbackEmoji = $emoji !== '' ? $emoji : self::DEFAULT_FALLBACK_EMOJI;
                } else {
                    $this->countryMap[$upper] = $emoji;
                }
            } elseif ($type === 'keyword') {
                $this->keywordRules[] = [
                    'pattern' => $pattern,
                    'emoji' => $emoji,
                ];
            }
        }

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $cacheFile,
            json_encode([
                'countryMap' => $this->countryMap,
                'keywordRules' => $this->keywordRules,
                'fallbackEmoji' => $this->fallbackEmoji,
            ], JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * @return array<int, array{match_type: string, pattern: string, emoji: string, label: string}>
     */
    public static function getDefaultRules(): array
    {
        $rules = [];

        // Fallback-icoon voor onherkende tags
        $rules[] = [
            'match_type' => 'country',
            'pattern' => self::FALLBACK_PATTERN,
            'emoji' => self::DEFAULT_FALLBACK_EMOJI,
            'label' => 'Standaard fallback-icoon',
        ];

        // Alle standaard ISO 3166-1 alpha-2 landcodes
        $isoCodes = [
            'AD' => 'Andorra', 'AE' => 'Verenigde Arabische Emiraten', 'AF' => 'Afghanistan',
            'AG' => 'Antigua en Barbuda', 'AI' => 'Anguilla', 'AL' => 'Albanië',
            'AM' => 'Armenië', 'AO' => 'Angola', 'AQ' => 'Antarctica',
            'AR' => 'Argentinië', 'AS' => 'Amerikaans-Samoa', 'AT' => 'Oostenrijk',
            'AU' => 'Australië', 'AW' => 'Aruba', 'AX' => 'Åland',
            'AZ' => 'Azerbeidzjan', 'BA' => 'Bosnië en Herzegovina', 'BB' => 'Barbados',
            'BD' => 'Bangladesh', 'BE' => 'België', 'BF' => 'Burkina Faso',
            'BG' => 'Bulgarije', 'BH' => 'Bahrein', 'BI' => 'Burundi',
            'BJ' => 'Benin', 'BL' => 'Saint-Barthélemy', 'BM' => 'Bermuda',
            'BN' => 'Brunei', 'BO' => 'Bolivia', 'BQ' => 'Caribisch Nederland',
            'BR' => 'Brazilië', 'BS' => 'Bahama\'s', 'BT' => 'Bhutan',
            'BV' => 'Bouveteiland', 'BW' => 'Botswana', 'BY' => 'Wit-Rusland',
            'BZ' => 'Belize', 'CA' => 'Canada', 'CC' => 'Cocoseilanden',
            'CD' => 'Congo-Kinshasa', 'CF' => 'Centraal-Afrikaanse Republiek', 'CG' => 'Congo-Brazzaville',
            'CH' => 'Zwitserland', 'CI' => 'Ivoorkust', 'CK' => 'Cookeilanden',
            'CL' => 'Chili', 'CM' => 'Kameroen', 'CN' => 'China',
            'CO' => 'Colombia', 'CR' => 'Costa Rica', 'CU' => 'Cuba',
            'CV' => 'Kaapverdië', 'CW' => 'Curaçao', 'CX' => 'Christmaseiland',
            'CY' => 'Cyprus', 'CZ' => 'Tsjechië', 'DE' => 'Duitsland',
            'DJ' => 'Djibouti', 'DK' => 'Denemarken', 'DM' => 'Dominica',
            'DO' => 'Dominicaanse Republiek', 'DZ' => 'Algerije', 'EC' => 'Ecuador',
            'EE' => 'Estland', 'EG' => 'Egypte', 'EH' => 'Westelijke Sahara',
            'ER' => 'Eritrea', 'ES' => 'Spanje', 'ET' => 'Ethiopië',
            'FI' => 'Finland', 'FJ' => 'Fiji', 'FK' => 'Falklandeilanden',
            'FM' => 'Micronesia', 'FO' => 'Faeröer', 'FR' => 'Frankrijk',
            'GA' => 'Gabon', 'GB' => 'Verenigd Koninkrijk', 'GD' => 'Grenada',
            'GE' => 'Georgië', 'GF' => 'Frans-Guyana', 'GG' => 'Guernsey',
            'GH' => 'Ghana', 'GI' => 'Gibraltar', 'GL' => 'Groenland',
            'GM' => 'Gambia', 'GN' => 'Guinee', 'GP' => 'Guadeloupe',
            'GQ' => 'Equatoriaal-Guinea', 'GR' => 'Griekenland', 'GS' => 'Zuid-Georgia',
            'GT' => 'Guatemala', 'GU' => 'Guam', 'GW' => 'Guinee-Bissau',
            'GY' => 'Guyana', 'HK' => 'Hongkong', 'HM' => 'Heard en McDonaldeilanden',
            'HN' => 'Honduras', 'HR' => 'Kroatië', 'HT' => 'Haïti',
            'HU' => 'Hongarije', 'ID' => 'Indonesië', 'IE' => 'Ierland',
            'IL' => 'Israël', 'IM' => 'Isle of Man', 'IN' => 'India',
            'IO' => 'Brits Indische Oceaanterritorium', 'IQ' => 'Irak', 'IR' => 'Iran',
            'IS' => 'IJsland', 'IT' => 'Italië', 'JE' => 'Jersey',
            'JM' => 'Jamaica', 'JO' => 'Jordanië', 'JP' => 'Japan',
            'KE' => 'Kenia', 'KG' => 'Kirgizië', 'KH' => 'Cambodja',
            'KI' => 'Kiribati', 'KM' => 'Comoren', 'KN' => 'Saint Kitts en Nevis',
            'KP' => 'Noord-Korea', 'KR' => 'Zuid-Korea', 'KW' => 'Koeweit',
            'KY' => 'Kaaimaneilanden', 'KZ' => 'Kazachstan', 'LA' => 'Laos',
            'LB' => 'Libanon', 'LC' => 'Saint Lucia', 'LI' => 'Liechtenstein',
            'LK' => 'Sri Lanka', 'LR' => 'Liberia', 'LS' => 'Lesotho',
            'LT' => 'Litouwen', 'LU' => 'Luxemburg', 'LV' => 'Letland',
            'LY' => 'Libië', 'MA' => 'Marokko', 'MC' => 'Monaco',
            'MD' => 'Moldavië', 'ME' => 'Montenegro', 'MF' => 'Sint-Maarten (FR)',
            'MG' => 'Madagaskar', 'MH' => 'Marshalleilanden', 'MK' => 'Noord-Macedonië',
            'ML' => 'Mali', 'MM' => 'Myanmar', 'MN' => 'Mongolië',
            'MO' => 'Macau', 'MP' => 'Noordelijke Marianen', 'MQ' => 'Martinique',
            'MR' => 'Mauritanië', 'MS' => 'Montserrat', 'MT' => 'Malta',
            'MU' => 'Mauritius', 'MV' => 'Maldiven', 'MW' => 'Malawi',
            'MX' => 'Mexico', 'MY' => 'Maleisië', 'MZ' => 'Mozambique',
            'NA' => 'Namibië', 'NC' => 'Nieuw-Caledonië', 'NE' => 'Niger',
            'NF' => 'Norfolk', 'NG' => 'Nigeria', 'NI' => 'Nicaragua',
            'NL' => 'Nederland', 'NO' => 'Noorwegen', 'NP' => 'Nepal',
            'NR' => 'Nauru', 'NU' => 'Niue', 'NZ' => 'Nieuw-Zeeland',
            'OM' => 'Oman', 'PA' => 'Panama', 'PE' => 'Peru',
            'PF' => 'Frans-Polynesië', 'PG' => 'Papoea-Nieuw-Guinea', 'PH' => 'Filipijnen',
            'PK' => 'Pakistan', 'PL' => 'Polen', 'PM' => 'Saint-Pierre en Miquelon',
            'PN' => 'Pitcairneilanden', 'PR' => 'Puerto Rico', 'PS' => 'Palestina',
            'PT' => 'Portugal', 'PW' => 'Palau', 'PY' => 'Paraguay',
            'QA' => 'Qatar', 'RE' => 'Réunion', 'RO' => 'Roemenië',
            'RS' => 'Servië', 'RU' => 'Rusland', 'RW' => 'Rwanda',
            'SA' => 'Saoedi-Arabië', 'SB' => 'Salomonseilanden', 'SC' => 'Seychellen',
            'SD' => 'Soedan', 'SE' => 'Zweden', 'SG' => 'Singapore',
            'SH' => 'Sint-Helena', 'SI' => 'Slovenië', 'SJ' => 'Spitsbergen en Jan Mayen',
            'SK' => 'Slowakije', 'SL' => 'Sierra Leone', 'SM' => 'San Marino',
            'SN' => 'Senegal', 'SO' => 'Somalië', 'SR' => 'Suriname',
            'SS' => 'Zuid-Soedan', 'ST' => 'Sao Tomé en Principe', 'SV' => 'El Salvador',
            'SX' => 'Sint-Maarten (NL)', 'SY' => 'Syrië', 'SZ' => 'Eswatini',
            'TC' => 'Turks- en Caicoseilanden', 'TD' => 'Tsjaad', 'TF' => 'Franse Zuidelijke Gebieden',
            'TG' => 'Togo', 'TH' => 'Thailand', 'TJ' => 'Tadzjikistan',
            'TK' => 'Tokelau', 'TL' => 'Oost-Timor', 'TM' => 'Turkmenistan',
            'TN' => 'Tunesië', 'TO' => 'Tonga', 'TR' => 'Turkije',
            'TT' => 'Trinidad en Tobago', 'TV' => 'Tuvalu', 'TW' => 'Taiwan',
            'TZ' => 'Tanzania', 'UA' => 'Oekraïne', 'UG' => 'Oeganda',
            'UM' => 'Kleine afgelegen eilanden van de VS', 'US' => 'Verenigde Staten', 'UY' => 'Uruguay',
            'UZ' => 'Oezbekistan', 'VA' => 'Vaticaanstad', 'VC' => 'Saint Vincent en de Grenadines',
            'VE' => 'Venezuela', 'VG' => 'Britse Maagdeneilanden', 'VI' => 'Amerikaanse Maagdeneilanden',
            'VN' => 'Vietnam', 'VU' => 'Vanuatu', 'WF' => 'Wallis en Futuna',
            'WS' => 'Samoa', 'YE' => 'Jemen', 'YT' => 'Mayotte',
            'ZA' => 'Zuid-Afrika', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
        ];

        foreach ($isoCodes as $code => $label) {
            $rules[] = [
                'match_type' => 'country',
                'pattern' => $code,
                'emoji' => self::isoToFlag($code),
                'label' => $label,
            ];
        }

        // Gangbare IPTV-aliassen (overschrijven/aanvullen waar nodig)
        $aliases = [
            'UK' => ['emoji' => '🇬🇧', 'label' => 'Verenigd Koninkrijk (alias)'],
            'EN' => ['emoji' => '🇬🇧', 'label' => 'Engels / VK (alias)'],
            'USA' => ['emoji' => '🇺🇸', 'label' => 'Verenigde Staten (alias)'],
            'AR' => ['emoji' => '🇸🇦', 'label' => 'Arabisch (IPTV alias)'],
            'LATAM' => ['emoji' => '🌎', 'label' => 'Latijns-Amerika (alias)'],
            'EXYU' => ['emoji' => '🌍', 'label' => 'Ex-Joegoslavië (alias)'],
            'SCANDI' => ['emoji' => '🇸🇪', 'label' => 'Scandinavië (alias)'],
            'EU' => ['emoji' => '🇪🇺', 'label' => 'Europa'],
        ];

        foreach ($aliases as $pattern => $info) {
            $found = false;
            foreach ($rules as &$r) {
                if ($r['match_type'] === 'country' && $r['pattern'] === $pattern) {
                    $r['emoji'] = $info['emoji'];
                    $r['label'] = $info['label'];
                    $found = true;
                    break;
                }
            }
            unset($r);
            if (!$found) {
                $rules[] = [
                    'match_type' => 'country',
                    'pattern' => $pattern,
                    'emoji' => $info['emoji'],
                    'label' => $info['label'],
                ];
            }
        }

        // Standaard categorie-regels (keyword, case-insensitive)
        $keywords = [
            ['pattern' => 'netflix', 'emoji' => '🎬', 'label' => 'Netflix'],
            ['pattern' => 'apple+', 'emoji' => '🎬', 'label' => 'Apple TV+'],
            ['pattern' => 'm+', 'emoji' => '🎬', 'label' => 'M+'],
            ['pattern' => 'hulu', 'emoji' => '🎬', 'label' => 'Hulu'],
            ['pattern' => 'movies', 'emoji' => '🎬', 'label' => 'Movies'],
            ['pattern' => 'prime', 'emoji' => '🎬', 'label' => 'Prime Video'],
            ['pattern' => 'disney+', 'emoji' => '🎬', 'label' => 'Disney+'],
            ['pattern' => 'collections', 'emoji' => '🎬', 'label' => 'Collections'],
            ['pattern' => 'marvel', 'emoji' => '🎬', 'label' => 'Marvel'],
            ['pattern' => 'live stream', 'emoji' => '🎬', 'label' => 'Live Stream'],
            ['pattern' => 'film', 'emoji' => '🎬', 'label' => 'Film'],
            ['pattern' => 'films', 'emoji' => '🎬', 'label' => 'Films'],
            ['pattern' => 'filme', 'emoji' => '🎬', 'label' => 'Filme'],
            ['pattern' => 'filmes', 'emoji' => '🎬', 'label' => 'Filmes'],
            ['pattern' => 'cinema', 'emoji' => '🎬', 'label' => 'Cinema'],
            ['pattern' => 'series', 'emoji' => '📺', 'label' => 'Series'],
            ['pattern' => 'serie', 'emoji' => '📺', 'label' => 'Serie'],
            ['pattern' => 'séries', 'emoji' => '📺', 'label' => 'Séries'],
            ['pattern' => 'serien', 'emoji' => '📺', 'label' => 'Serien'],
            ['pattern' => 'formula one', 'emoji' => '🏎️', 'label' => 'Formula One'],
            ['pattern' => 'formula 1', 'emoji' => '🏎️', 'label' => 'Formula 1'],
            ['pattern' => 'formule 1', 'emoji' => '🏎️', 'label' => 'Formule 1'],
            ['pattern' => 'adult', 'emoji' => '❤️🔥', 'label' => 'Adult'],
            ['pattern' => '18+', 'emoji' => '❤️🔥', 'label' => '18+'],
        ];

        foreach ($keywords as $kw) {
            $rules[] = [
                'match_type' => 'keyword',
                'pattern' => $kw['pattern'],
                'emoji' => $kw['emoji'],
                'label' => $kw['label'],
            ];
        }

        return $rules;
    }
}
