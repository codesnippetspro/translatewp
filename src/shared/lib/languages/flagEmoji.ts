import { languages } from '@/shared/lib/languages';

// A browser's own select box cannot show images, so its options carry the
// emoji flag instead. Locales with a region use it; plain languages map to the
// country their flag sprite shows.
const COUNTRY_FOR_LANGUAGE: Record<string, string> = {
    en: 'GB', de: 'DE', fr: 'FR', it: 'IT', es: 'ES', pt: 'PT', nl: 'NL', pl: 'PL', ru: 'RU', uk: 'UA', ja: 'JP', zh: 'CN',
    ko: 'KR', sv: 'SE', da: 'DK', fi: 'FI', no: 'NO', nb: 'NO', nn: 'NO', cs: 'CZ', sk: 'SK', hu: 'HU', ro: 'RO', bg: 'BG',
    el: 'GR', tr: 'TR', he: 'IL', ar: 'SA', hi: 'IN', th: 'TH', vi: 'VN', id: 'ID', ms: 'MY', et: 'EE', lv: 'LV', lt: 'LT',
    sl: 'SI', hr: 'HR', sr: 'RS', bs: 'BA', mk: 'MK', sq: 'AL', is: 'IS', ga: 'IE', mt: 'MT', fa: 'IR', ur: 'PK', bn: 'BD',
    ta: 'LK', sw: 'KE', af: 'ZA', ka: 'GE', hy: 'AM', az: 'AZ', kk: 'KZ', uz: 'UZ', mn: 'MN', ne: 'NP', si: 'LK', km: 'KH',
    lo: 'LA', my: 'MM', tl: 'PH', fil: 'PH',
};

const countryFor = (locale: string): string =>
{
    const entry = (languages as Record<string, { region?: string }>)[locale];
    if (entry?.region) return entry.region.toUpperCase();
    const [language, region] = locale.split('-');
    if (region && region.length === 2) return region.toUpperCase();

    return COUNTRY_FOR_LANGUAGE[language] ?? '';
};

export const flagEmoji = (locale: string): string =>
{
    const country = countryFor(locale);
    if (country.length !== 2) return '';

    return String.fromCodePoint(...Array.from(country).map(letter => 0x1F1E6 + letter.charCodeAt(0) - 65));
};
