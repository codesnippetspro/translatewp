import { TextEncoder } from 'util';
import { webcrypto } from 'crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import golden from '../../../../../tests/fixtures/translation-fingerprint-golden.json';
import { DOMExtractor } from '@/shared/lib/translations/DOMExtractor';
import { computeFingerprintHash } from '@/shared/lib/translations/fingerprintPayload';
import { TranslationCacheClient } from '@/shared/lib/translations/TranslationCacheClient';
import { TranslationSession } from '@/shared/lib/translations/TranslationSession';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';
import { Translator } from '@/shared/lib/translations/Translator';

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

const PROXY_BASE = 'https://example.com/wp-json/translatewp/v1/translation';
const API_BASE = 'https://api.example.test';

type GoldenVector = {
    id: string;
    markerPayload: string;
    sourceLang: string;
    targetLang: string;
    contextKind: 'text-flow' | 'alt' | 'attribute';
    contextKey: string;
    settings: Window['TranslateWP']['options']['ai_settings'];
    hash: string;
};

const basicFixture = (golden.vectors as GoldenVector[]).find(vector => vector.id === 'basic-proxy-fixture');
const TRANSLATED_GREETING = 'Guten Tag';

function requestHashes(url: string, init?: RequestInit): string[]
{
    if (init?.body)
    {
        return (JSON.parse(String(init.body)) as { hashes?: string[] }).hashes ?? [];
    }
    return new URL(url).searchParams.getAll('hash');
}

function installTranslateWP(aiSettings: Window['TranslateWP']['options']['ai_settings']): void
{
    Object.defineProperty(window, 'TranslateWP', {
        value: {
            context: {
                site_id: 'site-1',
                translation_base_url: PROXY_BASE,
                remote_api_base: API_BASE,
                admin_base_url: 'https://example.com/wp-json/translatewp/v1/admin',
                nonce: 'nonce-1',
                home_url: 'https://example.com',
                domain: 'example.com',
            },
            options: {
                ai_settings: aiSettings,
            },
        },
        configurable: true,
        writable: true,
    });
}

function createLookupFetch(storedByHash: Map<string, string>): typeof fetch
{
    return jest.fn(async (url: string, init?: RequestInit) =>
    {
        if (url.startsWith(`${API_BASE}/v1/translation/cache/lookup`))
        {
            const items: Record<string, string> = {};

            for (const hash of requestHashes(url, init))
            {
                const payload = storedByHash.get(hash);
                if (payload)
                {
                    items[hash] = payload;
                }
            }

            return {
                ok: true,
                json: async () => ({ items }),
            };
        }

        throw new Error(`Unexpected fetch URL: ${url}`);
    }) as unknown as typeof fetch;
}

function createJobFetch(storedByHash: Map<string, string>): typeof fetch
{
    let lookupCount = 0;

    return jest.fn(async (url: string, init?: RequestInit) =>
    {
        if (url.startsWith(`${PROXY_BASE}/create-job`))
        {
            return {
                ok: true,
                json: async () => ({
                    jobId: '00000000-0000-4000-8000-000000000099',
                    sourceLang: 'en',
                    targetLang: 'de',
                }),
            };
        }

        if (url.startsWith(`${PROXY_BASE}/push-batch`))
        {
            const body = JSON.parse(String(init?.body ?? '{}')) as {
                items?: { cache?: { hash?: string }; markerPayload?: string }[];
            };

            for (const item of body.items ?? [])
            {
                const hash = item.cache?.hash;
                if (hash && item.markerPayload)
                {
                    storedByHash.set(
                        hash,
                        item.markerPayload.replace(/Hello/g, TRANSLATED_GREETING)
                    );
                }
            }

            return { ok: true, json: async () => ({ ok: true }) };
        }

        if (url.startsWith(`${PROXY_BASE}/status`))
        {
            return {
                ok: true,
                json: async () => ({ state: 'done' }),
            };
        }

        if (url.startsWith(`${API_BASE}/v1/translation/cache/lookup`))
        {
            lookupCount++;
            const items: Record<string, string | null> = {};

            for (const hash of requestHashes(url, init))
            {
                items[hash] = lookupCount > 1 ? (storedByHash.get(hash) ?? null) : null;
            }

            return {
                ok: true,
                json: async () => ({ items }),
            };
        }

        throw new Error(`Unexpected fetch URL: ${url}`);
    }) as unknown as typeof fetch;
}

describe('translation cache roundtrip', () =>
{
    const originalFetch = globalThis.fetch;
    const originalTranslateWP = window.TranslateWP;

    beforeEach(() =>
    {
        document.body.innerHTML = '<main><p>Hello</p></main>';
        installTranslateWP(basicFixture!.settings);
    });

    afterEach(() =>
    {
        globalThis.fetch = originalFetch;
        Object.defineProperty(window, 'TranslateWP', {
            value: originalTranslateWP,
            configurable: true,
            writable: true,
        });
        jest.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it('computes the same lookup hash as the golden PHP fingerprint seam', async () =>
    {
        const entries = new DOMExtractor().extract(document.body);
        const client = new TranslationCacheClient({
            sourceLang: 'en',
            targetLang: basicFixture!.targetLang,
            siteId: 'site-1',
            apiBaseUrl: API_BASE,
            aiSettings: basicFixture!.settings,
        });
        const plan = await client.prepare(entries);
        const groupCache = plan.groupsForCache[0];

        expect(groupCache.markerPayload).toBe(basicFixture!.markerPayload);
        expect(groupCache.hash).toBe(
            await computeFingerprintHash({
                markerPayload: groupCache.markerPayload,
                sourceLang: 'en',
                targetLang: basicFixture!.targetLang,
                contextKind: groupCache.group.kind,
                contextKey: groupCache.group.contextKey,
                settings: basicFixture!.settings,
            })
        );
        expect(groupCache.hash).toBe(basicFixture!.hash);
    });

    it('applies stored marker payloads keyed by the canonical fingerprint after job completion', async () =>
    {
        const entries = new DOMExtractor().extract(document.body);
        const client = new TranslationCacheClient({
            sourceLang: 'en',
            targetLang: 'de',
            siteId: 'site-1',
            apiBaseUrl: API_BASE,
            aiSettings: basicFixture!.settings,
        });
        const plan = await client.prepare(entries);
        const groupCache = plan.groupsForCache[0];
        const storedByHash = new Map<string, string>();

        globalThis.fetch = createJobFetch(storedByHash);

        const translator = new Translator({ sourceLang: 'en', targetLang: 'de' });
        const paragraph = document.querySelector('p');
        const onEntryTranslated = jest.fn((entry: TranslatableEntry) =>
        {
            if (paragraph?.firstChild)
            {
                paragraph.firstChild.nodeValue = entry.value;
            }
        });

        const result = await translator._translateContextGroups(plan, client, { onEntryTranslated });

        expect(storedByHash.has(groupCache.hash)).toBe(true);
        expect(storedByHash.get(groupCache.hash)).toBe(`⟪item_0001⟫${TRANSLATED_GREETING}`);
        expect(result.translatedById.get(entries[0].id)).toBe(TRANSLATED_GREETING);
        expect(paragraph?.textContent).toBe(TRANSLATED_GREETING);
        expect(onEntryTranslated).toHaveBeenCalled();
    });

    it('applies lookup results when marker ids use DOM ids instead of canonical item ids', async () =>
    {
        const entries = new DOMExtractor().extract(document.body);
        const client = new TranslationCacheClient({
            sourceLang: 'en',
            targetLang: 'de',
            siteId: 'site-1',
            apiBaseUrl: API_BASE,
            aiSettings: basicFixture!.settings,
        });
        const plan = await client.prepare(entries);
        const groupCache = plan.groupsForCache[0];
        const domMarkerId = entries[0].id;
        const storedPayload = `⟪${domMarkerId}⟫${TRANSLATED_GREETING}`;

        globalThis.fetch = createLookupFetch(new Map([[groupCache.hash, storedPayload]]));

        const translator = new Translator({ sourceLang: 'en', targetLang: 'de' });
        const result = await translator._translateContextGroups(plan, client);

        expect(result.translatedById.get(entries[0].id)).toBe(TRANSLATED_GREETING);
    });

    it('keeps translated DOM text when the mutation observer is active', async () =>
    {
        const storedByHash = new Map<string, string>();

        globalThis.fetch = createJobFetch(storedByHash);

        const session = new TranslationSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: { includeAlt: false, includeAttributes: false },
        });

        await session.switchTo('de');

        expect(document.querySelector('p')?.textContent).toBe(TRANSLATED_GREETING);
        await new Promise(resolve => window.setTimeout(resolve, 0));
        expect(document.querySelector('p')?.textContent).toBe(TRANSLATED_GREETING);

        session.dispose();
    });

    it('applies exact-id matches from a count-mismatched payload and keeps source for the rest', async () =>
    {
        document.body.innerHTML = '<main><p>Hello <span>world</span></p></main>';
        installTranslateWP(basicFixture!.settings);
        const entries = new DOMExtractor().extract(document.body);
        const client = new TranslationCacheClient({
            sourceLang: 'en',
            targetLang: 'ja',
            siteId: 'site-1',
            apiBaseUrl: API_BASE,
            aiSettings: basicFixture!.settings,
        });
        const plan = await client.prepare(entries);
        const groupCache = plan.groupsForCache[0];

        // Model returned a merged marker with a different id — count mismatches (2 sent, 1 returned).
        // _isCompletePayload rejects it (no canonical id match, not legacy pattern) -> cache miss -> job created.
        // The job fetch also returns the merged payload on the second lookup -> _isCompletePayload still rejects.
        const mergedPayload = '⟪merged_id⟫こんにちは世界';
        globalThis.fetch = jest.fn(async (url: string, init?: RequestInit) =>
        {
            if (url.startsWith(`${API_BASE}/v1/translation/cache/lookup`))
            {
                const items: Record<string, string> = {};
                for (const hash of requestHashes(url, init))
                {
                    items[hash] = mergedPayload;
                }
                return { ok: true, json: async () => ({ items }) };
            }
            if (url.startsWith(`${PROXY_BASE}/create-job`))
            {
                return { ok: true, json: async () => ({ jobId: 'job-merged', sourceLang: 'en', targetLang: 'ja' }) };
            }
            if (url.startsWith(`${PROXY_BASE}/push-batch`))
            {
                return { ok: true, json: async () => ({ ok: true }) };
            }
            if (url.startsWith(`${PROXY_BASE}/status`))
            {
                return { ok: true, json: async () => ({ state: 'done', jobId: 'job-merged' }) };
            }
            throw new Error(`Unexpected fetch URL: ${url}`);
        }) as unknown as typeof fetch;

        const translator = new Translator({ sourceLang: 'en', targetLang: 'ja' });
        const result = await translator._translateContextGroups(plan, client);

        // No canonical id matched the merged marker -> all entries keep source
        for (const entry of entries)
        {
            expect(result.translatedById.get(entry.id)).toBe(entry.value);
        }
    });

    it('applies exact-id matches and keeps source for unmatched ids in a partial payload', async () =>
    {
        const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
        document.body.innerHTML = '<main><p>Alpha <span>Beta</span> Gamma</p></main>';
        installTranslateWP(basicFixture!.settings);
        const entries = new DOMExtractor().extract(document.body);
        const client = new TranslationCacheClient({
            sourceLang: 'en',
            targetLang: 'ar',
            siteId: 'site-1',
            apiBaseUrl: API_BASE,
            aiSettings: basicFixture!.settings,
        });
        const plan = await client.prepare(entries);
        const groupCache = plan.groupsForCache[0];

        // Model returned only item_0001 and item_0003 (dropped item_0002).
        const canonicalIds = groupCache.canonicalEntries.map(e => e.id);
        const partialPayload = `⟪${canonicalIds[0]}⟫ألفا⟪${canonicalIds[2]}⟫غاما`;
        globalThis.fetch = createLookupFetch(new Map([[groupCache.hash, partialPayload]]));

        const translator = new Translator({ sourceLang: 'en', targetLang: 'ar' });
        const result = await translator._translateContextGroups(plan, client);

        const liveEntries = plan.groups[0].entries;
        const alphaEntry = liveEntries.find(e => e.value.trim() === 'Alpha');
        const gammaEntry = liveEntries.find(e => e.value.trim() === 'Gamma');
        const betaEntry = liveEntries.find(e => e.value.trim() === 'Beta');

        expect(result.translatedById.get(alphaEntry!.id)).toBe('ألفا');
        expect(result.translatedById.get(gammaEntry!.id)).toBe('غاما');
        expect(result.translatedById.get(betaEntry!.id)).toBe(betaEntry!.value);

        // Exact totals: 2 canonical ids matched (applied), 1 source-fallback (unmatched)
        const appliedCount = [...result.translatedById.values()].filter(v => v !== 'Alpha ' && v !== ' Gamma' && v !== 'Beta').length;
        const sourceKeptCount = [...result.translatedById.values()].filter(v => v === 'Beta').length;
        expect(appliedCount).toBe(2);
        expect(sourceKeptCount).toBe(1);
        const applyWarning = warnSpy.mock.calls.map(args => String(args[0])).find(m => m.includes('Translation apply mismatch for group'));
        expect(applyWarning).toBeDefined();
        expect(applyWarning).toContain('applied 2, unmatched 1');
    });

    it('rejects positional fallback for count-equal hybrid payload with unknown ids', async () =>
    {
        document.body.innerHTML = '<main><p>Alpha <span>Beta</span> Gamma</p></main>';
        installTranslateWP(basicFixture!.settings);
        const entries = new DOMExtractor().extract(document.body);
        const client = new TranslationCacheClient({
            sourceLang: 'en',
            targetLang: 'hi',
            siteId: 'site-1',
            apiBaseUrl: API_BASE,
            aiSettings: basicFixture!.settings,
        });
        const plan = await client.prepare(entries);
        const groupCache = plan.groupsForCache[0];

        // 3 canonical entries sent, 3 markers returned (count matches),
        // but marker[1] has an unknown id — hybrid payload.
        const canonicalIds = groupCache.canonicalEntries.map(e => e.id);
        const hybridPayload = `⟪${canonicalIds[0]}⟫अल्फा⟪unknown_id⟫WRONG⟪${canonicalIds[2]}⟫गामा`;
        globalThis.fetch = createLookupFetch(new Map([[groupCache.hash, hybridPayload]]));

        const translator = new Translator({ sourceLang: 'en', targetLang: 'hi' });
        const result = await translator._translateContextGroups(plan, client);

        const liveEntries = plan.groups[0].entries;
        const alphaEntry = liveEntries.find(e => e.value.trim() === 'Alpha');
        const gammaEntry = liveEntries.find(e => e.value.trim() === 'Gamma');
        const betaEntry = liveEntries.find(e => e.value.trim() === 'Beta');

        // Exact-id matches applied; unknown-id marker NOT positionally applied to Beta
        expect(result.translatedById.get(alphaEntry!.id)).toBe('अल्फा');
        expect(result.translatedById.get(gammaEntry!.id)).toBe('गामा');
        expect(result.translatedById.get(betaEntry!.id)).toBe(betaEntry!.value);
    });

    it('rejects positional fallback for count-equal payload with duplicate ids', async () =>
    {
        document.body.innerHTML = '<main><p>Alpha <span>Beta</span> Gamma</p></main>';
        installTranslateWP(basicFixture!.settings);
        const entries = new DOMExtractor().extract(document.body);
        const client = new TranslationCacheClient({
            sourceLang: 'en',
            targetLang: 'ko',
            siteId: 'site-1',
            apiBaseUrl: API_BASE,
            aiSettings: basicFixture!.settings,
        });
        const plan = await client.prepare(entries);
        const groupCache = plan.groupsForCache[0];

        // 3 canonical entries sent, 3 markers returned (count matches),
        // but marker[0] and marker[1] both have the same canonical id — duplicate.
        // _isCompletePayload rejects duplicate-id payloads -> cache miss -> job path.
        const canonicalIds = groupCache.canonicalEntries.map(e => e.id);
        const dupPayload = `⟪${canonicalIds[0]}⟫알파⟪${canonicalIds[0]}⟫WRONG⟪${canonicalIds[2]}⟫감마`;
        globalThis.fetch = jest.fn(async (url: string, init?: RequestInit) =>
        {
            if (url.startsWith(`${API_BASE}/v1/translation/cache/lookup`))
            {
                const items: Record<string, string> = {};
                for (const hash of requestHashes(url, init)) items[hash] = dupPayload;
                return { ok: true, json: async () => ({ items }) };
            }
            if (url.startsWith(`${PROXY_BASE}/create-job`))
            {
                return { ok: true, json: async () => ({ jobId: 'job-dup', sourceLang: 'en', targetLang: 'ko' }) };
            }
            if (url.startsWith(`${PROXY_BASE}/push-batch`))
            {
                return { ok: true, json: async () => ({ ok: true }) };
            }
            if (url.startsWith(`${PROXY_BASE}/status`))
            {
                return { ok: true, json: async () => ({ state: 'done', jobId: 'job-dup' }) };
            }
            throw new Error(`Unexpected fetch URL: ${url}`);
        }) as unknown as typeof fetch;

        const translator = new Translator({ sourceLang: 'en', targetLang: 'ko' });
        const result = await translator._translateContextGroups(plan, client);

        const liveEntries = plan.groups[0].entries;
        const alphaEntry = liveEntries.find(e => e.value.trim() === 'Alpha');
        const gammaEntry = liveEntries.find(e => e.value.trim() === 'Gamma');
        const betaEntry = liveEntries.find(e => e.value.trim() === 'Beta');

        // Duplicate-id payload is rejected by _isCompletePayload -> all entries keep source
        expect(result.translatedById.get(alphaEntry!.id)).toBe(alphaEntry!.value);
        expect(result.translatedById.get(gammaEntry!.id)).toBe(gammaEntry!.value);
        expect(result.translatedById.get(betaEntry!.id)).toBe(betaEntry!.value);
    });

    it('applies exact-id-only translations to a synthetic Elementor shop fixture', async () =>
    {
        const fixtureHtml = readFileSync(
            resolve(__dirname, '../../../../../tests/fixtures/elementor-shop-capture.html'),
            'utf8',
        );
        document.body.innerHTML = fixtureHtml;
        installTranslateWP(basicFixture!.settings);
        const entries = new DOMExtractor().extract(document.body);
        const client = new TranslationCacheClient({
            sourceLang: 'en',
            targetLang: 'nl',
            siteId: 'site-1',
            apiBaseUrl: API_BASE,
            aiSettings: basicFixture!.settings,
        });
        const plan = await client.prepare(entries);

        // Pin fixture identity: exact entry count and specific fixture strings
        expect(entries.length).toBe(13);
        const entryValues = entries.map(e => e.value);
        expect(entryValues).toContain('€');
        expect(entryValues).toContain('\u00A00,00');
        expect(entryValues).toContain('home');
        expect(entryValues).toContain('Main Site');
        expect(entryValues).toContain('Shop');
        expect(entryValues).toContain('My account');
        expect(entryValues).toContain('Sample hero heading for the demo shop template');
        expect(entryValues).toContain('Everyday mugs, tote bags, and notebook sets for the demo storefront. Simple designs, sturdy materials, sample copy only.');
        expect(plan.groupsForCache.length).toBeGreaterThan(0);

        // Sample returned payload for the amount group:
        // ⟪item_0001⟫€⟪item_0002⟫ 14,50, the amount translation (" 14,50") DIFFERS from the
        // fixture source ("\u00A00,00"), so application is observable on the DOM node.
        const cartGroup = plan.groupsForCache.find(g =>
            g.canonicalEntries.some(e => e.value === '€')
        );
        expect(cartGroup).toBeDefined();

        const sampleReturnedPayload = `⟪${cartGroup!.canonicalEntries[0].id}⟫€⟪${cartGroup!.canonicalEntries[1].id}⟫ 14,50`;
        // Partial derived from the sample payload: drop the first marker, keep the differing one.
        const partialPayload = sampleReturnedPayload.slice(sampleReturnedPayload.indexOf('⟪', 1));
        expect(partialPayload).toBe(`⟪${cartGroup!.canonicalEntries[1].id}⟫ 14,50`);

        globalThis.fetch = jest.fn(async (url: string, init?: RequestInit) =>
        {
            if (url.startsWith(`${API_BASE}/v1/translation/cache/lookup`))
            {
                const items: Record<string, string> = {};
                for (const hash of requestHashes(url, init))
                {
                    if (hash === cartGroup!.hash) items[hash] = partialPayload;
                    else items[hash] = '';
                }
                return { ok: true, json: async () => ({ items }) };
            }
            if (url.startsWith(`${PROXY_BASE}/create-job`))
            {
                return { ok: true, json: async () => ({ jobId: 'job-elem', sourceLang: 'en', targetLang: 'nl' }) };
            }
            if (url.startsWith(`${PROXY_BASE}/push-batch`))
            {
                return { ok: true, json: async () => ({ ok: true }) };
            }
            if (url.startsWith(`${PROXY_BASE}/status`))
            {
                return { ok: true, json: async () => ({ state: 'done', jobId: 'job-elem' }) };
            }
            throw new Error(`Unexpected fetch URL: ${url}`);
        }) as unknown as typeof fetch;

        const amountNode = [...document.querySelectorAll('*')]
            .find(el => el.childNodes.length && [...el.childNodes].some(n => n.nodeType === 3 && n.textContent === '\u00A00,00'));
        const heroNode = document.querySelector('h1');
        expect(amountNode).toBeDefined();
        expect(heroNode?.textContent).toBe('Sample hero heading for the demo shop template');

        const session = new TranslationSession({
            root: document.body,
            sourceLang: 'en',
            extractionOptions: { includeAlt: false, includeAttributes: false },
        });
        await session.switchTo('nl');

        // Applied: the amount node text changed to the captured translated value.
        expect(amountNode!.textContent).toContain(' 14,50');
        // Omitted sibling marker: the currency symbol node keeps source.
        expect([...document.querySelectorAll('*')].some(el =>
            [...el.childNodes].some(n => n.nodeType === 3 && n.textContent === '€'))).toBe(true);
        // Unrelated group: hero stays source.
        expect(heroNode?.textContent).toBe('Sample hero heading for the demo shop template');

        session.dispose();
    });
});
