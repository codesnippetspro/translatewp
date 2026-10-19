import { TextEncoder } from 'util';
import { webcrypto } from 'crypto';

import golden from '../../../../../tests/fixtures/translation-fingerprint-golden.json';
import {
    computeFingerprintHash,
    fingerprintCanonicalString,
    stableStringify,
    type TranslationFingerprintInput,
} from '@/shared/lib/translations/fingerprintPayload';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

if (!globalThis.crypto?.subtle)
{
    Object.defineProperty(globalThis, 'crypto', {
        value: webcrypto,
        configurable: true,
    });
}

type GoldenVector = TranslationFingerprintInput & {
    id: string;
    canonical: string;
    hash: string;
};

const vectors = golden.vectors as GoldenVector[];

describe('translation fingerprint golden vectors', () =>
{
    beforeAll(() =>
    {
        Object.defineProperty(globalThis, 'crypto', {
            value: webcrypto,
            configurable: true,
        });
    });

    it.each(vectors)('matches shared canonical bytes and hash ($id)', async (vector) =>
    {
        const input: TranslationFingerprintInput = {
            markerPayload: vector.markerPayload,
            sourceLang: vector.sourceLang,
            targetLang: vector.targetLang,
            contextKind: vector.contextKind,
            settings: vector.settings,
        };

        expect(fingerprintCanonicalString(input)).toBe(vector.canonical);
        await expect(computeFingerprintHash(input)).resolves.toBe(vector.hash);
    });

    it('uses crypto.subtle.digest on the secure path', async () =>
    {
        const digestSpy = jest.spyOn(globalThis.crypto.subtle, 'digest');
        const vector = vectors[0];

        await computeFingerprintHash({
            markerPayload: vector.markerPayload,
            sourceLang: vector.sourceLang,
            targetLang: vector.targetLang,
            contextKind: vector.contextKind,
            settings: vector.settings,
        });

        expect(digestSpy).toHaveBeenCalledTimes(1);
        expect(digestSpy.mock.calls[0]?.[0]).toBe('SHA-256');
        expect((digestSpy.mock.calls[0]?.[1] as Uint8Array | undefined)?.byteLength).toBeGreaterThan(0);
        digestSpy.mockRestore();
    });

    it('falls back to pure JS SHA-256 when crypto.subtle is unavailable', async () =>
    {
        const originalCrypto = globalThis.crypto;
        Object.defineProperty(globalThis, 'crypto', {
            value: {},
            configurable: true,
        });

        const vector = vectors[0];
        const hash = await computeFingerprintHash({
            markerPayload: vector.markerPayload,
            sourceLang: vector.sourceLang,
            targetLang: vector.targetLang,
            contextKind: vector.contextKind,
            settings: vector.settings,
        });

        expect(hash).toBe(vector.hash);

        Object.defineProperty(globalThis, 'crypto', {
            value: originalCrypto,
            configurable: true,
        });
    });

    it('sorts reordered settings keys to the same canonical bytes', async () =>
    {
        const sorted = vectors.find(vector => vector.id === 'unicode-text-flow');
        const reordered = vectors.find(vector => vector.id === 'reordered-settings-keys');

        expect(sorted).toBeDefined();
        expect(reordered).toBeDefined();

        const sortedInput: TranslationFingerprintInput = {
            markerPayload: '⟪item_0001⟫Hello',
            sourceLang: 'en',
            targetLang: 'es',
            contextKind: 'text-flow',
            settings: {
                preserve_branded_terms: true,
                localized_date_time_formats: false,
                enable_units_conversion: true,
                context: 'website',
                writing_style: 'neutral',
                tone: 'neutral',
                formality: 0,
                creativity: 0,
                lexicon_terms: [],
            },
        };

        expect(fingerprintCanonicalString(sortedInput)).toBe(reordered!.canonical);
        await expect(computeFingerprintHash(sortedInput)).resolves.toBe(reordered!.hash);
    });

    it('excludes DOM position (contextKey) from the fingerprint bytes', () =>
    {
        const vector = vectors.find(entry => entry.id === 'unicode-text-flow');
        expect(vector).toBeDefined();
        // The fixture vector still carries a position-bearing contextKey as
        // request metadata, but the fingerprint drops it: identical source text
        // resolves to one cache entry regardless of where it sits.
        expect((vector as unknown as { contextKey: string }).contextKey).toContain(':nth-of-type(2)');
        expect(vector!.canonical).not.toContain('contextKey');
        expect(vector!.canonical).not.toContain(':nth-of-type');
        expect(vector!.canonical).toContain('"schemaVersion":"group-v4"');
    });

    it('keys the fingerprint on the model profile only', () =>
    {
        const base = vectors.find(entry => entry.id === 'unicode-text-flow');
        const withTerms = vectors.find(entry => entry.id === 'lexicon-terms-ignored');
        const withLocalizer = vectors.find(entry => entry.id === 'localizer-toggles-ignored');
        expect(base).toBeDefined();
        expect(withTerms).toBeDefined();
        expect(withLocalizer).toBeDefined();
        // The fixture inputs carry lexicon terms and flipped localizer toggles,
        // yet the canonical bytes and the hash are those of the base vector.
        expect(withTerms!.settings.lexicon_terms?.length).toBeGreaterThan(0);
        expect(withTerms!.hash).toBe(base!.hash);
        expect(withLocalizer!.hash).toBe(base!.hash);
        expect(base!.canonical).not.toContain('lexicon_terms');
        expect(base!.canonical).not.toContain('localized_date_time_formats');
        expect(base!.canonical).not.toContain('enable_units_conversion');
    });

    it('serializes booleans and numbers without drift', () =>
    {
        const vector = vectors.find(entry => entry.id === 'boolean-number-settings');
        expect(vector).toBeDefined();
        expect(vector!.canonical).toContain('"formality":7');
        expect(vector!.canonical).toContain('"creativity":3');
        expect(vector!.canonical).toContain('"preserve_branded_terms":false');
        expect(stableStringify({ enabled: true, count: 0 })).toBe('{"count":0,"enabled":true}');
    });
});
