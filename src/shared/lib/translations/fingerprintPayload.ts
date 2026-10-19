import { sha256Hex } from '@/shared/lib/sha256';

// group-v4 keys the fingerprint on the model profile only: the settings that
// change what the model writes. Lexicon terms and the localizer toggles ride to
// the service as instructions but never enter the hash, so editing a term or a
// date format leaves every cached segment valid until the site owner
// retranslates. Mirrors Components/TranslationFingerprint.php.
export const TRANSLATION_FINGERPRINT_SCHEMA_VERSION = 'group-v4';

export const TRANSLATION_FINGERPRINT_MODEL_KEYS = [
    'context',
    'writing_style',
    'tone',
    'formality',
    'creativity',
    'preserve_branded_terms',
] as const;

export const TRANSLATION_FINGERPRINT_CONTEXT_KINDS = [
    'text-flow',
    'alt',
    'attribute',
] as const;

export type TranslationFingerprintContextKind = typeof TRANSLATION_FINGERPRINT_CONTEXT_KINDS[number];

export type TranslationFingerprintSettings = {
    preserve_branded_terms: boolean;
    localized_date_time_formats?: boolean;
    enable_units_conversion?: boolean;
    context: string;
    writing_style: string;
    tone: string;
    formality: number;
    creativity: number;
    lexicon_terms?: {
        term: string;
        description?: string;
        languages?: string[];
    }[];
};

/** The subset of the settings the fingerprint is keyed on. */
export function fingerprintModelProfile(settings: TranslationFingerprintSettings): Record<string, unknown>
{
    const profile: Record<string, unknown> = {};
    for (const key of TRANSLATION_FINGERPRINT_MODEL_KEYS)
    {
        if (key in settings)
        {
            profile[key] = (settings as Record<string, unknown>)[key];
        }
    }
    return profile;
}

export type TranslationFingerprintInput = {
    markerPayload: string;
    sourceLang: string;
    targetLang: string;
    contextKind: TranslationFingerprintContextKind;
    settings: TranslationFingerprintSettings;
};

export function normalizeContextKey(contextKey: string): string
{
    return contextKey.replace(/:nth-of-type\(\d+\)/g, '');
}

export function stableStringify(value: unknown): string
{
    if (Array.isArray(value))
    {
        return `[${value.map(item => stableStringify(item)).join(',')}]`;
    }

    if (value && typeof value === 'object')
    {
        const entries = Object.entries(value as Record<string, unknown>)
            .sort(([left], [right]) => left.localeCompare(right))
            .map(([key, item]) => `${JSON.stringify(key)}:${stableStringify(item)}`);
        return `{${entries.join(',')}}`;
    }

    return JSON.stringify(value);
}

export function buildFingerprintPayload(input: TranslationFingerprintInput): Record<string, unknown>
{
    return {
        schemaVersion: TRANSLATION_FINGERPRINT_SCHEMA_VERSION,
        markerPayload: input.markerPayload,
        sourceLang: input.sourceLang,
        targetLang: input.targetLang,
        contextKind: input.contextKind,
        settings: fingerprintModelProfile(input.settings),
    };
}

export function fingerprintCanonicalString(input: TranslationFingerprintInput): string
{
    return stableStringify(buildFingerprintPayload(input));
}

function bytesToHex(bytes: Uint8Array): string
{
    return Array.from(bytes)
        .map(byte => byte.toString(16).padStart(2, '0'))
        .join('');
}

export async function computeFingerprintHash(input: TranslationFingerprintInput): Promise<string>
{
    const bytes = new TextEncoder().encode(fingerprintCanonicalString(input));

    if (globalThis.crypto?.subtle?.digest)
    {
        const digest = await globalThis.crypto.subtle.digest('SHA-256', bytes);
        return bytesToHex(new Uint8Array(digest));
    }

    return sha256Hex(bytes);
}

export function isTranslationFingerprintContextKind(value: string): value is TranslationFingerprintContextKind
{
    return (TRANSLATION_FINGERPRINT_CONTEXT_KINDS as readonly string[]).includes(value);
}
