import { TextEncoder } from 'util';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

import { CacheFirstTranslator } from '@/shared/lib/translations/CacheFirstTranslator';
import { HydrationStore, type HydrationBackend } from '@/shared/lib/translations/hydrationStore';
import { TranslationCacheClient } from '@/shared/lib/translations/TranslationCacheClient';
import { TranslationEngine } from '@/shared/lib/translations/TranslationEngine';
import golden from '../../../../../tests/fixtures/translation-fingerprint-golden.json';

type GoldenVector = { id: string; settings: Window['TranslateWP']['options']['ai_settings'] };
const SETTINGS = (golden.vectors as GoldenVector[]).find(vector => vector.id === 'basic-proxy-fixture')!.settings;

const API_BASE = 'https://api.example.test';
const PROXY_BASE = 'https://example.com/wp-json/translatewp/v1/translation';
const TEXT_ONLY = { includeAlt: false, includeAttributes: false };

class InMemoryBackend implements HydrationBackend
{
    rows = new Map<string, { payload: string; lastUsed: number }>();
    async getMany(hashes: string[]): Promise<Map<string, string>>
    {
        const found = new Map<string, string>();
        for (const hash of hashes) { const row = this.rows.get(hash); if (row) found.set(hash, row.payload); }
        return found;
    }
    async putMany(entries: Map<string, string>): Promise<void>
    {
        for (const [hash, payload] of entries) this.rows.set(hash, { payload, lastUsed: Date.now() });
    }
    async touch(): Promise<void> {}
    async evictLeastRecentlyUsed(): Promise<void> {}
    async estimateBytes(): Promise<number> { return 0; }
}

type FetchLogEntry = { url: string; body: Record<string, unknown> };

// Serves a fixed map of translations on the SECOND lookup (the poll): the first
// lookup misses, forcing a miss report, then the poll serves everything.
function createFetch(served: Map<string, string | null>): { impl: typeof fetch; log: FetchLogEntry[] }
{
    const log: FetchLogEntry[] = [];
    const impl = (async (url: string, init?: RequestInit) =>
    {
        const body = init?.body ? JSON.parse(String(init.body)) : {};
        log.push({ url, body });

        if (url.includes('/v1/translation/cache/lookup'))
        {
            const lookups = log.filter(entry => entry.url.includes('/v1/translation/cache/lookup')).length;
            const responses = lookups > 1 ? served : new Map<string, string | null>();
            const items: Record<string, string | null> = {};
            const hashes = Array.isArray(body.hashes) ? body.hashes as string[] : new URL(url).searchParams.getAll('hash');
            for (const hash of hashes) items[hash] = responses.get(hash) ?? null;
            return { ok: true, status: 200, json: async () => ({ items }) };
        }
        if (url.endsWith('/miss') || url.endsWith('/hit-local'))
        {
            return { ok: true, status: 200, json: async () => ({ ok: true }) };
        }
        throw new Error(`Unexpected fetch: ${url}`);
    }) as unknown as typeof fetch;

    return { impl, log };
}

function installTranslateWP(): void
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
                cache_version: 1,
            },
            options: { ai_settings: SETTINGS },
        },
        configurable: true,
        writable: true,
    });
}

const originalTranslateWP = window.TranslateWP;

async function seedPlan(targetLang: string)
{
    const client = new TranslationCacheClient({
        sourceLang: 'en', targetLang, siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: SETTINGS,
    });
    const extractor = new (await import('@/shared/lib/translations/DOMExtractor')).DOMExtractor();
    return client.prepare(extractor.extract(document.body, TEXT_ONLY));
}

function runVisitorTranslation(targetLang: string, impl: typeof fetch): Promise<unknown>
{
    const translator = new CacheFirstTranslator({
        sourceLang: 'en', targetLang,
        store: new HydrationStore(new InMemoryBackend()),
        fetchImpl: impl,
        delay: async () => {},
    });
    translator._createCacheClient = () => new TranslationCacheClient({
        sourceLang: 'en', targetLang, siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: SETTINGS,
        sampleOrigin: () => false, fetchImpl: impl,
    });
    const engine = new TranslationEngine({ sourceLang: 'en', targetLang, translator });
    return engine.run(document.body, TEXT_ONLY);
}

beforeEach(() => { installTranslateWP(); });
afterEach(() =>
{
    Object.defineProperty(window, 'TranslateWP', { value: originalTranslateWP, configurable: true, writable: true });
    document.body.innerHTML = '';
});

describe('visitor render consistency', () =>
{
    it('renders one source string identically in three placements from a single miss', async () =>
    {
        const TITLE = 'Our smoked-fish platter won a local award';
        const FR = 'Notre plateau de poisson fumé a remporté un prix local';
        document.body.innerHTML =
            `<main>`
            + `<article class="card"><h3>${TITLE}</h3></article>`
            + `<header><h1>${TITLE}</h1></header>`
            + `<div class="comment-meta"><span>${TITLE}</span></div>`
            + `</main>`;

        const plan = await seedPlan('fr');
        // Defect (1): the same text in three DOM positions is one cache entry.
        expect(plan.groupsForCache).toHaveLength(1);

        const served = new Map<string, string | null>();
        served.set(plan.groupsForCache[0].hash, plan.groupsForCache[0].markerPayload.replace(TITLE, FR));

        const { impl, log } = createFetch(served);
        await runVisitorTranslation('fr', impl);

        const missReports = log.filter(entry => entry.url.endsWith('/miss'));
        expect(missReports).toHaveLength(1);
        expect((missReports[0].body.items as unknown[])).toHaveLength(1);

        const rendered = Array.from(document.querySelectorAll('h3, h1, .comment-meta span')).map(el => el.textContent);
        expect(rendered).toEqual([FR, FR, FR]);
    });

    it('decodes HTML entities in a served translation so a badge never shows a literal &amp;', async () =>
    {
        document.body.innerHTML = '<main><span class="badge">Food &amp; Drink</span></main>';
        const badge = document.querySelector('.badge') as HTMLSpanElement;
        expect(badge.textContent).toBe('Food & Drink');

        const plan = await seedPlan('de');
        expect(plan.groupsForCache).toHaveLength(1);
        // Defect (2): the server returns the translation from a serialized
        // context with the ampersand re-encoded as an entity.
        const served = new Map<string, string | null>();
        served.set(plan.groupsForCache[0].hash, plan.groupsForCache[0].markerPayload.replace('Food & Drink', 'Essen &amp; Trinken'));

        const { impl } = createFetch(served);
        await runVisitorTranslation('de', impl);

        expect(badge.textContent).toBe('Essen & Trinken');
        expect(badge.textContent).not.toContain('&amp;');
    });

    it('translates a nested inline child inside a heading when the payload preserves both markers', async () =>
    {
        document.body.innerHTML = '<main><h1>Sign up <em>now</em></h1></main>';

        const plan = await seedPlan('ar');
        // The outer text and the inline <em> child share one heading group, as
        // two marker entries.
        expect(plan.groupsForCache).toHaveLength(1);
        expect(plan.groupsForCache[0].canonicalEntries).toHaveLength(2);

        const served = new Map<string, string | null>();
        served.set(
            plan.groupsForCache[0].hash,
            plan.groupsForCache[0].markerPayload.replace('Sign up ', 'اشترك ').replace('now', 'الآن'),
        );

        const { impl } = createFetch(served);
        await runVisitorTranslation('ar', impl);

        // Both the outer text node and the nested <em> child are written back.
        expect(document.querySelector('em')!.textContent).toBe('الآن');
        expect(document.querySelector('h1')!.textContent).toBe('اشترك الآن');
    });

    it('serves short list items robustly and silently, sharing one entry across repeats', async () =>
    {
        const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
        const errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
        try
        {
            document.body.innerHTML =
                `<main><ul class="amenities">`
                + `<li><svg class="icon"></svg>Free Wi-Fi</li>`
                + `<li><span class="icon" aria-hidden="true"></span>Complimentary breakfast</li>`
                + `</ul>`
                + `<footer><ul><li><svg class="icon"></svg>Free Wi-Fi</li></ul></footer></main>`;

            const plan = await seedPlan('fr');
            // Defect (3): three short list-item occurrences, but "Free Wi-Fi"
            // repeats, so it is one cache entry, giving two misses for three nodes.
            expect(plan.groupsForCache).toHaveLength(2);

            const served = new Map<string, string | null>();
            for (const group of plan.groupsForCache)
            {
                served.set(
                    group.hash,
                    group.markerPayload
                        .replace('Free Wi-Fi', 'Wi-Fi gratuit')
                        .replace('Complimentary breakfast', 'Petit-déjeuner offert'),
                );
            }

            const { impl, log } = createFetch(served);
            await runVisitorTranslation('fr', impl);

            const missReports = log.filter(entry => entry.url.endsWith('/miss'));
            expect((missReports[0].body.items as unknown[])).toHaveLength(2);

            const items = Array.from(document.querySelectorAll('li')).map(li => li.textContent);
            expect(items).toEqual(['Wi-Fi gratuit', 'Petit-déjeuner offert', 'Wi-Fi gratuit']);
            // A clean serve logs nothing to the visitor.
            expect(warnSpy).not.toHaveBeenCalled();
            expect(errorSpy).not.toHaveBeenCalled();
        }
        finally
        {
            warnSpy.mockRestore();
            errorSpy.mockRestore();
        }
    });
});
