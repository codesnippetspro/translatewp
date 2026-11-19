import { TextEncoder } from 'util';
import { webcrypto } from 'crypto';

import { CacheFirstTranslator } from '@/shared/lib/translations/CacheFirstTranslator';
import { computeFingerprintHash } from '@/shared/lib/translations/fingerprintPayload';
import { HeadTitleTranslator } from '@/shared/lib/translations/headTitle';

// The fingerprint 1.1.1 computed for the title "Store hours" (es to ja, default settings).
const PINNED_STORE_HOURS_HASH = '945799284bacf920b42761de80ceeeaab822284fdf2c96f94c02b8cebc654b12';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

if (!globalThis.crypto?.subtle)
{
    Object.defineProperty(globalThis, 'crypto', { value: webcrypto, configurable: true });
}

const API_BASE = 'https://api.example.test';
const PROXY_BASE = 'https://site.example.test/wp-json/translatewp/v1/translation';

type FetchLog = { url: string; body: unknown };

function installContext(): void
{
    Object.defineProperty(window, 'TranslateWP', {
        value: {
            context: {
                site_id: 'site-1',
                translation_base_url: PROXY_BASE,
                remote_api_base: API_BASE,
                admin_base_url: 'https://site.example.test/wp-json/translatewp/v1/admin',
                nonce: 'nonce-1',
                home_url: 'https://site.example.test',
                domain: 'site.example.test',
            },
            options: { ai_settings: {} },
        },
        configurable: true,
        writable: true,
    });
}

// Answer the cache lookup by echoing one marker payload for every hash asked.
function lookupFetch(translatedMarkerPayload: string, log: FetchLog[]): typeof fetch
{
    return (async (url: string | URL, init?: RequestInit) =>
    {
        const href = String(url);
        const body = init?.body ? JSON.parse(String(init.body)) : null;
        log.push({ url: href, body });

        if (href.includes('/v1/translation/cache/lookup'))
        {
            const items: Record<string, string | null> = {};
            const hashes = Array.isArray(body?.hashes)
                ? body.hashes as string[]
                : new URL(href).searchParams.getAll('hash');
            for (const hash of hashes)
            {
                items[hash] = translatedMarkerPayload;
            }
            return { ok: true, status: 200, json: async () => ({ items }) } as unknown as Response;
        }

        return { ok: true, status: 200, json: async () => ({}) } as unknown as Response;
    }) as unknown as typeof fetch;
}

describe('Translator.translateSeoString', () =>
{
    const originalFetch = globalThis.fetch;
    const originalTranslateWP = window.TranslateWP;

    beforeEach(() =>
    {
        installContext();
        jest.spyOn(Math, 'random').mockReturnValue(1);
        try { window.localStorage.clear(); } catch { /* jsdom storage */ }
    });

    afterEach(() =>
    {
        globalThis.fetch = originalFetch;
        Object.defineProperty(window, 'TranslateWP', { value: originalTranslateWP, configurable: true, writable: true });
        jest.restoreAllMocks();
    });

    it('returns the cached translation and never opens the job pipeline', async () =>
    {
        const log: FetchLog[] = [];
        globalThis.fetch = lookupFetch('⟪item_0001⟫Store hours (JA)', log);

        const translator = new CacheFirstTranslator({ sourceLang: 'es', targetLang: 'ja' });
        const result = await translator.translateSeoString('Store hours', 'seo:title');

        expect(result).toBe('Store hours (JA)');

        const lookups = log.filter(entry => entry.url.includes('/cache/lookup'));
        expect(lookups).toHaveLength(1);
        expect(new URL(lookups[0].url).searchParams.getAll('hash')).toHaveLength(1);
        // The title is keyed by its SEO context, not a DOM selector, so its
        // fingerprint matches the server's for the same string.
        expect(log.some(entry => entry.url.includes('/create-job') || entry.url.includes('/push-batch'))).toBe(false);
    });

    it('asks the service even when the local store holds the source title', async () =>
    {
        const log: FetchLog[] = [];
        globalThis.fetch = lookupFetch('⟪item_0001⟫Store hours (JA)', log);

        const staleStore = {
            getMany: async (hashes: string[]) => new Map(hashes.map(hash => [hash, '⟪item_0001⟫Store hours'])),
            putMany: async () => undefined,
            set: async () => undefined,
            recordLanguageUse: () => undefined,
        };
        const translator = new CacheFirstTranslator({ sourceLang: 'es', targetLang: 'ja', store: staleStore as never });
        const result = await translator.translateSeoString('Store hours', 'seo:title');

        expect(result).toBe('Store hours (JA)');
        expect(log.filter(entry => entry.url.includes('/cache/lookup'))).toHaveLength(1);
    });

    it('treats an unchanged cache answer as untranslated so the source title is kept', async () =>
    {
        const log: FetchLog[] = [];
        globalThis.fetch = lookupFetch('⟪item_0001⟫Store hours', log);

        const translator = new CacheFirstTranslator({ sourceLang: 'es', targetLang: 'ja' });
        const result = await translator.translateSeoString('Store hours', 'seo:title');

        expect(result).toBeNull();
    });

    it('returns null for an empty source without any network call', async () =>
    {
        const log: FetchLog[] = [];
        globalThis.fetch = lookupFetch('⟪item_0001⟫x', log);

        const translator = new CacheFirstTranslator({ sourceLang: 'es', targetLang: 'ja' });

        expect(await translator.translateSeoString('   ', 'seo:title')).toBeNull();
        expect(log).toHaveLength(0);
    });

    it('serves a warm title from the local store with zero lookups', async () =>
    {
        const rows = new Map<string, { payload: string; lastUsed: number }>();
        const backend = {
            getMany: async (hashes: string[]) =>
            {
                const found = new Map<string, string>();
                for (const hash of hashes)
                {
                    const row = rows.get(hash);
                    if (row) found.set(hash, row.payload);
                }
                return found;
            },
            putMany: async (entries: Map<string, string>) =>
            {
                for (const [hash, payload] of entries) rows.set(hash, { payload, lastUsed: 1 });
            },
            touch: async () => undefined,
            evictLeastRecentlyUsed: async () => undefined,
            estimateBytes: async () => 0,
        };

        const log: FetchLog[] = [];
        globalThis.fetch = lookupFetch('⟪item_0001⟫Store hours (JA)', log);

        const { HydrationStore } = require('@/shared/lib/translations/hydrationStore');
        const store = new HydrationStore(backend);
        const first = new CacheFirstTranslator({ sourceLang: 'es', targetLang: 'ja', store });
        expect(await first.translateSeoString('Store hours', 'seo:title')).toBe('Store hours (JA)');
        expect(log.filter(entry => entry.url.includes('/cache/lookup'))).toHaveLength(1);

        const warmLog: FetchLog[] = [];
        globalThis.fetch = lookupFetch('⟪item_0001⟫Store hours (JA)', warmLog);
        const warm = new CacheFirstTranslator({ sourceLang: 'es', targetLang: 'ja', store: new HydrationStore(backend) });
        expect(await warm.translateSeoString('Store hours', 'seo:title')).toBe('Store hours (JA)');
        expect(warmLog.filter(entry => entry.url.includes('/cache/lookup'))).toHaveLength(0);
    });

    it('re-asks the title after a cache generation bump', async () =>
    {
        const rows = new Map<string, { payload: string; lastUsed: number }>();
        const backend = {
            getMany: async (hashes: string[]) =>
            {
                const found = new Map<string, string>();
                for (const hash of hashes)
                {
                    const row = rows.get(hash);
                    if (row) found.set(hash, row.payload);
                }
                return found;
            },
            putMany: async (entries: Map<string, string>) =>
            {
                for (const [hash, payload] of entries) rows.set(hash, { payload, lastUsed: 1 });
            },
            touch: async () => undefined,
            evictLeastRecentlyUsed: async () => undefined,
            estimateBytes: async () => 0,
        };

        const log: FetchLog[] = [];
        globalThis.fetch = lookupFetch('⟪item_0001⟫Store hours (JA)', log);

        const { HydrationStore } = require('@/shared/lib/translations/hydrationStore');
        const first = new CacheFirstTranslator({ sourceLang: 'es', targetLang: 'ja', store: new HydrationStore(backend, 1) });
        expect(await first.translateSeoString('Store hours', 'seo:title')).toBe('Store hours (JA)');

        const bumpedLog: FetchLog[] = [];
        globalThis.fetch = lookupFetch('⟪item_0001⟫Store hours neu (JA)', bumpedLog);
        const bumped = new CacheFirstTranslator({ sourceLang: 'es', targetLang: 'ja', store: new HydrationStore(backend, 2) });
        expect(await bumped.translateSeoString('Store hours', 'seo:title')).toBe('Store hours neu (JA)');
        expect(bumpedLog.filter(entry => entry.url.includes('/cache/lookup'))).toHaveLength(1);
    });
});

describe('HeadTitleTranslator through the real cache client', () =>
{
    const originalFetch = globalThis.fetch;
    const originalTranslateWP = window.TranslateWP;
    const TITLE = 'Garden Lantern – SwagDemo';

    beforeEach(() =>
    {
        installContext();
        jest.spyOn(Math, 'random').mockReturnValue(1);
        jest.spyOn(console, 'warn').mockImplementation(() => undefined);
        try { window.localStorage.clear(); } catch { /* jsdom storage */ }
        document.title = TITLE;
    });

    afterEach(() =>
    {
        globalThis.fetch = originalFetch;
        Object.defineProperty(window, 'TranslateWP', { value: originalTranslateWP, configurable: true, writable: true });
        jest.restoreAllMocks();
    });

    async function hashOf(translator: CacheFirstTranslator, text: string): Promise<string>
    {
        return computeFingerprintHash({
            markerPayload: `⟪item_0001⟫${text}`,
            sourceLang: 'es',
            targetLang: 'ja',
            contextKind: 'attribute',
            settings: translator._createCacheClient().aiSettings,
        });
    }

    // Answers the lookup from the stored payloads given by hash; the miss
    // proxy refuses the report, so nothing is polled afterwards.
    function storedFetch(stored: Map<string, string>, log: FetchLog[]): typeof fetch
    {
        return (async (url: string | URL, init?: RequestInit) =>
        {
            const href = String(url);
            const body = init?.body ? JSON.parse(String(init.body)) : null;
            log.push({ url: href, body });
            if (href.includes('/v1/translation/cache/lookup'))
            {
                const items: Record<string, string | null> = {};
                for (const hash of new URL(href).searchParams.getAll('hash')) items[hash] = stored.get(hash) ?? null;
                return { ok: true, status: 200, json: async () => ({ items }) } as unknown as Response;
            }
            return { ok: false, status: 400, json: async () => ({}) } as unknown as Response;
        }) as unknown as typeof fetch;
    }

    const lookups = (log: FetchLog[]): FetchLog[] => log.filter(entry => entry.url.includes('/cache/lookup'));
    const misses = (log: FetchLog[]): FetchLog[] => log.filter(entry => entry.url.endsWith('/miss'));
    const jobs = (log: FetchLog[]): FetchLog[] => log.filter(entry => entry.url.includes('/create-job'));
    const reported = (log: FetchLog[]): string[] => misses(log).flatMap(entry =>
        (entry.body as { items: { markerPayload: string }[] }).items.map(item => item.markerPayload));

    it('asks for both title pieces and the whole title in one lookup and reports one miss in the new form', async () =>
    {
        const log: FetchLog[] = [];
        globalThis.fetch = storedFetch(new Map(), log);
        const translator = new CacheFirstTranslator({ sourceLang: 'es', targetLang: 'ja' });

        await new HeadTitleTranslator(undefined, 'SwagDemo').translate(translator);

        expect(lookups(log)).toHaveLength(1);
        expect(new URL(lookups(log)[0].url).searchParams.getAll('hash').sort()).toEqual((await Promise.all(
            ['Garden Lantern', 'SwagDemo', TITLE].map(text => hashOf(translator, text))
        )).sort());
        expect(misses(log)).toHaveLength(1);
        expect(reported(log)).toEqual(['⟪item_0001⟫Garden Lantern', '⟪item_0001⟫SwagDemo']);
        expect(jobs(log)).toHaveLength(0);
        expect(document.title).toBe(TITLE);
    });

    it('uses a translation stored under the whole title and reports nothing', async () =>
    {
        const log: FetchLog[] = [];
        const translator = new CacheFirstTranslator({ sourceLang: 'es', targetLang: 'ja' });
        globalThis.fetch = storedFetch(new Map([[await hashOf(translator, TITLE), '⟪item_0001⟫Lanterna – SwagDemo']]), log);

        await new HeadTitleTranslator(undefined, 'SwagDemo').translate(translator);

        expect(document.title).toBe('Lanterna – SwagDemo');
        expect(lookups(log)).toHaveLength(1);
        expect(misses(log)).toHaveLength(0);
    });

    it('still asks for the pieces when the whole title was stored unchanged', async () =>
    {
        const log: FetchLog[] = [];
        const translator = new CacheFirstTranslator({ sourceLang: 'es', targetLang: 'ja' });
        globalThis.fetch = storedFetch(new Map([[await hashOf(translator, TITLE), `⟪item_0001⟫${TITLE}`]]), log);

        await new HeadTitleTranslator(undefined, 'SwagDemo').translate(translator);

        expect(document.title).toBe(TITLE);
        expect(reported(log)).toEqual(['⟪item_0001⟫Garden Lantern', '⟪item_0001⟫SwagDemo']);
    });

    it('serves the stored page title piece over the whole title', async () =>
    {
        const log: FetchLog[] = [];
        const translator = new CacheFirstTranslator({ sourceLang: 'es', targetLang: 'ja' });
        globalThis.fetch = storedFetch(new Map([
            [await hashOf(translator, 'Garden Lantern'), '⟪item_0001⟫Lanterna da giardino'],
            [await hashOf(translator, TITLE), '⟪item_0001⟫Lanterna – SwagDemo'],
        ]), log);

        await new HeadTitleTranslator(undefined, 'SwagDemo').translate(translator);

        expect(document.title).toBe('Lanterna da giardino – SwagDemo');
        expect(misses(log)).toHaveLength(0);
    });

    it('keeps the hash a title without the site name had before', async () =>
    {
        const log: FetchLog[] = [];
        globalThis.fetch = storedFetch(new Map(), log);
        document.title = 'Store hours';

        await new HeadTitleTranslator(undefined, 'SwagDemo').translate(new CacheFirstTranslator({ sourceLang: 'es', targetLang: 'ja' }));

        expect(new URL(lookups(log)[0].url).searchParams.getAll('hash')).toEqual([PINNED_STORE_HOURS_HASH]);
        expect(reported(log)).toEqual(['⟪item_0001⟫Store hours']);
    });
});
