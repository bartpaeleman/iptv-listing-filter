export const DEFAULT_FALLBACK_ICON = '🏷️';

export const FLAG_MAP = {
    NL: '🇳🇱', BE: '🇧🇪', DE: '🇩🇪', FR: '🇫🇷', UK: '🇬🇧', GB: '🇬🇧', EN: '🇬🇧',
    US: '🇺🇸', USA: '🇺🇸', ES: '🇪🇸', IT: '🇮🇹', PT: '🇵🇹', TR: '🇹🇷', AR: '🇸🇦',
    SA: '🇸🇦', PL: '🇵🇱', RU: '🇷🇺', RO: '🇷🇴', GR: '🇬🇷', SE: '🇸🇪', NO: '🇳🇴',
    DK: '🇩🇰', FI: '🇫🇮', CH: '🇨🇭', AT: '🇦🇹', CA: '🇨🇦', AU: '🇦🇺', BR: '🇧🇷',
    MX: '🇲🇽', IN: '🇮🇳', PK: '🇵🇰', JP: '🇯🇵', KR: '🇰🇷', CN: '🇨🇳', TW: '🇹🇼',
    HK: '🇭🇰', AE: '🇦🇪', UAE: '🇦🇪', EG: '🇪🇬', MA: '🇲🇦', DZ: '🇩🇿', TN: '🇹🇳',
    ZA: '🇿🇦', NG: '🇳🇬', IE: '🇮🇪', CZ: '🇨🇿', SK: '🇸🇰', HU: '🇭🇺', BG: '🇧🇬',
    HR: '🇭🇷', RS: '🇷🇸', BA: '🇧🇦', SI: '🇸🇮', AL: '🇦🇱', MK: '🇲🇰', UA: '🇺🇦',
    BY: '🇧🇾', LT: '🇱🇹', LV: '🇱🇻', EE: '🇪🇪', IL: '🇮🇱', IR: '🇮🇷', IQ: '🇮🇶',
    LB: '🇱🇧', JO: '🇯🇴', SY: '🇸🇾', AF: '🇦🇫', TH: '🇹🇭', VN: '🇻🇳', ID: '🇮🇩',
    MY: '🇲🇾', PH: '🇵🇭', SG: '🇸🇬', NZ: '🇳🇿', CL: '🇨🇱', CO: '🇨🇴', PE: '🇵🇪',
    VE: '🇻🇪', EC: '🇪🇨', UY: '🇺🇾', PY: '🇵🇾', BO: '🇧🇴', CR: '🇨🇷', PA: '🇵🇦',
    DO: '🇩🇴', CU: '🇨🇺', PR: '🇵🇷', ARG: '🇦🇷', LU: '🇱🇺', IS: '🇮🇸', MT: '🇲🇹',
    CY: '🇨🇾', GE: '🇬🇪', AM: '🇦🇲', AZ: '🇦🇿', KZ: '🇰🇿', UZ: '🇺🇿', EXYU: '🇷🇸',
};

/**
 * Parses leading country/prefix tags like |BE|, BE|, |BE, |VIP|, |4K|
 * and returns { icon, cleanName, rawTag } for visual display without mutating DB values.
 *
 * @param {string} name
 * @param {Array<{tag_pattern?: string, flag_emoji?: string}>} customTags
 */
export function formatTagPrefix(name, customTags = []) {
    const raw = String(name || '');
    const match = raw.match(/^(?:\|\s*([A-Za-z0-9_-]{1,12})\s*\||\|\s*([A-Za-z0-9_-]{1,12})\b|\b([A-Za-z0-9_-]{1,12})\s*\|)\s*/);
    if (!match) {
        return { icon: '', cleanName: raw, rawTag: '' };
    }

    const code = (match[1] || match[2] || match[3] || '').toUpperCase();
    const rawTag = match[0].trim();
    const cleanName = raw.slice(match[0].length).trim() || raw;

    let icon = '';
    if (Array.isArray(customTags) && customTags.length > 0) {
        const found = customTags.find(t => String(t.tag_pattern || '').replace(/\|/g, '').trim().toUpperCase() === code);
        if (found?.flag_emoji) icon = found.flag_emoji;
    }
    if (!icon) {
        icon = FLAG_MAP[code] || DEFAULT_FALLBACK_ICON;
    }

    return { icon, cleanName, rawTag };
}
