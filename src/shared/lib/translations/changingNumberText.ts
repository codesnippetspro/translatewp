import { digitRuns, normalizeNumbers } from '@/shared/lib/translations/numericText';

const MAX_PATTERNS = 128;
const MAX_PATTERN_LENGTH = 512;
const PATTERN_TTL_MS = 24 * 60 * 60 * 1000;

type Pattern = { value: string; until: number };

function storageKey(): string | null
{
    const site = window.TranslateWP?.context?.site_id;
    return site ? `translatewp-changing-numbers:${site}` : null;
}

function readPatterns(key: string): Pattern[]
{
    try {
        const stored: unknown = JSON.parse(localStorage.getItem(key) ?? '[]');
        if (!Array.isArray(stored)) return [];
        return stored.filter((item): item is Pattern =>
            item && typeof item.value === 'string' && item.value.length <= MAX_PATTERN_LENGTH
            && typeof item.until === 'number' && item.until > Date.now()
        ).slice(0, MAX_PATTERNS);
    } catch { return []; }
}

export function isChangingNumberText(value: string): boolean
{
    if (!digitRuns(value).length) return false;
    const key = storageKey();
    if (!key) return false;
    const pattern = normalizeNumbers(value);
    return readPatterns(key).some(item => item.value === pattern);
}

export function rememberChangingNumberText(previous: string, current: string): boolean
{
    if (previous === current || !digitRuns(current).length) return false;
    const pattern = normalizeNumbers(current);
    if (normalizeNumbers(previous) !== pattern || pattern.length > MAX_PATTERN_LENGTH) return false;
    const key = storageKey();
    if (!key) return true;
    try {
        const patterns = readPatterns(key).filter(item => item.value !== pattern);
        patterns.unshift({ value: pattern, until: Date.now() + PATTERN_TTL_MS });
        localStorage.setItem(key, JSON.stringify(patterns.slice(0, MAX_PATTERNS)));
    } catch { /* Storage may be disabled for this visitor. */ }
    return true;
}
