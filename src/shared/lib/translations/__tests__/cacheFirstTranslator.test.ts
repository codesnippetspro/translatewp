import { TextEncoder } from 'util';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

import { CacheFirstTranslator } from '@/shared/lib/translations/CacheFirstTranslator';
import { DOMExtractor } from '@/shared/lib/translations/DOMExtractor';
import { HydrationStore, type HydrationBackend } from '@/shared/lib/translations/hydrationStore';
import { hydrationLanguageOrder } from '@/shared/lib/translations/hydrationScheduler';
import { orderByViewport } from '@/shared/lib/translations/viewportOrder';
import { MissReportCoalescer } from '@/shared/lib/translations/MissReportCoalescer';
import { TranslationCacheClient } from '@/shared/lib/translations/TranslationCacheClient';
import { TranslationEventClient } from '@/shared/lib/translations/TranslationEventClient';
import golden from '../../../../../tests/fixtures/translation-fingerprint-golden.json';

type GoldenVector = {
    id: string;
    settings: Window['TranslateWP']['options']['ai_settings'];
};

const basicFixture = (golden.vectors as GoldenVector[]).find(vector => vector.id === 'basic-proxy-fixture')!;
const API_BASE = 'https://api.example.test';
const PROXY_BASE = 'https://example.com/wp-json/translatewp/v1/translation';

class InMemoryBackend implements HydrationBackend
{
    rows = new Map<string, { payload: string; lastUsed: number }>();
    async getMany(hashes: string[]): Promise<Map<string, string>>
    {
        const found = new Map<string, string>();
        for (const hash of hashes)
        {
            const row = this.rows.get(hash);
            if (row) found.set(hash, row.payload);
        }
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
            options: { ai_settings: basicFixture.settings },
        },
        configurable: true,
        writable: true,
    });
}

const originalTranslateWP = window.TranslateWP;

type FetchLogEntry = { url: string; body: Record<string, unknown> };

function lookupHashes(url: string, body: Record<string, unknown>): string[]
{
    if (Array.isArray(body.hashes)) return body.hashes as string[];
    return new URL(url).searchParams.getAll('hash');
}

function isLookup(url: string): boolean
{
    return url.includes('/v1/translation/cache/lookup');
}

function createFetch(
    lookupResponses: Array<Map<string, string | null>>,
    missResponse: Record<string, unknown> = { ok: true },
): { impl: typeof fetch; log: FetchLogEntry[] }
{
    const log: FetchLogEntry[] = [];
    const impl = (async (url: string, init?: RequestInit) =>
    {
        const body = init?.body ? JSON.parse(String(init.body)) : {};
        log.push({ url, body });

        if (isLookup(url))
        {
            const responses = lookupResponses.length > 1 ? lookupResponses.shift()! : lookupResponses[0];
            const items: Record<string, string | null> = {};
            for (const hash of lookupHashes(url, body)) items[hash] = responses.get(hash) ?? null;
            return { ok: true, status: 200, json: async () => ({ items }) };
        }
        if (url.endsWith('/miss') || url.endsWith('/hit-local'))
        {
            return { ok: true, status: 200, json: async () => url.endsWith('/miss') ? missResponse : { ok: true } };
        }
        throw new Error(`Unexpected fetch: ${url}`);
    }) as unknown as typeof fetch;

    return { impl, log };
}

async function planHashes(targetLang: string): Promise<string[]>
{
    const entries = new DOMExtractor().extract(document.body);
    const client = new TranslationCacheClient({
        sourceLang: 'en', targetLang, siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
    });
    const plan = await client.prepare(entries);
    return plan.groupsForCache.map(group => group.hash);
}

beforeEach(() =>
{
    installTranslateWP();
    document.body.innerHTML = '<main><p>Hello world</p><p>Second paragraph</p></main>';
});

afterEach(() =>
{
    Object.defineProperty(window, 'TranslateWP', { value: originalTranslateWP, configurable: true, writable: true });
    document.body.innerHTML = '';
});

describe('CacheFirstTranslator', () =>
{
    it('skips a stale local copy after a retranslation bump and serves the freshly overwritten value', async () =>
    {
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);

        // The page was hydrated BEFORE the retranslation (generation 1) with the
        // wrong translation the operator just retranslated away.
        const backend = new InMemoryBackend();
        const staleStore = new HydrationStore(backend, 1);
        const staleByHash = new Map<string, string>();
        for (const group of plan.groupsForCache)
        {
            staleByHash.set(group.hash, group.markerPayload.replace('Hello world', 'WRONG uno').replace('Second paragraph', 'WRONG dos'));
        }
        await staleStore.putMany(staleByHash);

        // The forced retranslation overwrote the API cache with the correct text.
        const freshByHash = new Map<string, string | null>();
        for (const group of plan.groupsForCache)
        {
            freshByHash.set(group.hash, group.markerPayload.replace('Hello world', 'Hola mundo').replace('Second paragraph', 'Segundo párrafo'));
        }

        const { impl, log } = createFetch([freshByHash]);
        // The visitor's next load runs under generation 2 (the bumped value).
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es',
            store: new HydrationStore(backend, 2),
            fetchImpl: impl,
            delay: async () => {},
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
        });

        const result = await translator.translateBatch(new DOMExtractor().extract(document.body));

        assert(result.failures.length === 0, `failures: ${JSON.stringify(result.failures)}`);
        const values = result.translated.map(entry => entry.value);
        assert(values.includes('Hola mundo'), `fresh value must be served, got ${JSON.stringify(values)}`);
        assert(!values.some(value => String(value).includes('WRONG')), `stale local copy must not be served, got ${JSON.stringify(values)}`);
        const lookups = log.filter(entry => isLookup(entry.url));
        assert(lookups.length === 1, `the bumped generation must force a remote lookup, got ${lookups.length}`);
    });

    it('serves from the local store with a hit-local beacon and no lookup', async () =>
    {
        const hashes = await planHashes('es');
        const backend = new InMemoryBackend();
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);
        for (const group of plan.groupsForCache)
        {
            backend.rows.set(group.hash, { payload: group.markerPayload.replace('Hello world', 'Hola mundo').replace('Second paragraph', 'Segundo párrafo'), lastUsed: 1 });
        }

        const { impl, log } = createFetch([new Map()]);
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es',
            store: new HydrationStore(backend),
            fetchImpl: impl,
            delay: async () => {},
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
        });

        const result = await translator.translateBatch(new DOMExtractor().extract(document.body));

        assert(result.failures.length === 0, `failures: ${JSON.stringify(result.failures)}`);
        assert(result.translated.some(entry => entry.value === 'Hola mundo'), `translated: ${JSON.stringify(result.translated.map(e => e.value))}`);
        const lookups = log.filter(entry => isLookup(entry.url));
        const beacons = log.filter(entry => entry.url.endsWith('/hit-local'));
        assert(lookups.length === 0, 'fully local serve must not hit the network lookup');
        assert(beacons.length === 1, 'exactly one hit-local beacon');
        assert(beacons[0].body.siteId === 'site-1' && beacons[0].body.language === 'es');
        assert(hashes.length > 0);
    });

    it('looks up a local entry that is identical to the source instead of applying it', async () =>
    {
        const backend = new InMemoryBackend();
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);
        // A bare entry identical to the source predates the confirmed-same
        // marker; it is looked up once and the answer is stored with the marker.
        for (const group of plan.groupsForCache)
        {
            backend.rows.set(group.hash, { payload: group.markerPayload, lastUsed: 1 });
        }

        const { impl, log } = createFetch([new Map()]);
        const controller = new AbortController();
        let polls = 0;
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es',
            store: new HydrationStore(backend),
            fetchImpl: impl,
            delay: async () => { polls++; if (polls === 2) controller.abort(); },
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
        });

        let aborted: Error | null = null;
        try
        {
            await translator.translateBatch(new DOMExtractor().extract(document.body), { signal: controller.signal });
        }
        catch (error)
        {
            aborted = error as Error;
        }
        assert(aborted?.name === 'AbortError', `the never-served poll ends on the abort, got ${aborted}`);

        const lookups = log.filter(entry => isLookup(entry.url));
        assert(lookups.length >= 1, 'a source-identical local entry must be looked up, not served');
    });

    it('stores a service answer identical to its source as confirmed and never re-asks it', async () =>
    {
        const backend = new InMemoryBackend();
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);
        // The service answers with the source text itself (a proper name).
        const sameAsSource = new Map<string, string | null>();
        for (const group of plan.groupsForCache)
        {
            sameAsSource.set(group.hash, group.markerPayload);
        }

        const { impl, log } = createFetch([sameAsSource]);
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es',
            store: new HydrationStore(backend),
            fetchImpl: impl,
            delay: async () => {},
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
        });

        const first = await translator.translateBatch(entries);
        assert(first.failures.length === 0, `first visit must serve the same-as-source answer, got ${JSON.stringify(first.failures)}`);

        // Second visit: zero lookups and zero miss reports for the stored entry.
        const secondLog: FetchLogEntry[] = [];
        const secondImpl = (async (url: string, init?: RequestInit) =>
        {
            const body = init?.body ? JSON.parse(String(init.body)) : {};
            secondLog.push({ url, body });
            return { ok: true, status: 200, json: async () => ({ items: {} }) };
        }) as unknown as typeof fetch;
        const secondTranslator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es',
            store: new HydrationStore(backend),
            fetchImpl: secondImpl,
            delay: async () => {},
        });
        secondTranslator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: secondImpl,
        });

        const second = await secondTranslator.translateBatch(new DOMExtractor().extract(document.body));
        assert(second.failures.length === 0, `second visit serves from the local store, got ${JSON.stringify(second.failures)}`);
        assert(second.translated.some(entry => entry.value === 'Hello world'), 'the same-as-source text is the served answer');
        const secondRequests = secondLog.filter(entry => isLookup(entry.url) || entry.url.endsWith('/miss'));
        assert(secondRequests.length === 0, `second visit makes no lookup and no miss report, got ${JSON.stringify(secondRequests.map(entry => entry.url))}`);
        const storedPayload = backend.rows.get(plan.groupsForCache[0].hash)?.payload;
        assert(storedPayload === `⟪twp-confirmed-same⟫${plan.groupsForCache[0].markerPayload}`, 'the stored entry carries the confirmed-same marker');
        const missReports = log.filter(entry => entry.url.endsWith('/miss'));
        assert(missReports.length === 0, 'a same-as-source service answer is never reported as a miss');
    });

    it('issues the visible and rest lookups in parallel and applies the visible batch first', async () =>
    {
        document.body.innerHTML = `<main>${Array.from({ length: 30 }, (_, index) => `<p>Visible paragraph ${index}</p>`).join('')}</main>`;
        // jsdom reports zero rects; give the first ten paragraphs in-viewport
        // geometry so the viewport ordering splits visible from rest.
        const paragraphs = Array.from(document.querySelectorAll('p'));
        paragraphs.forEach((paragraph, index) =>
        {
            paragraph.getBoundingClientRect = () => ({
                top: index < 10 ? 100 : 2000, bottom: index < 10 ? 120 : 2020, height: 20, width: 100,
                left: 0, right: 100, x: 0, y: index < 10 ? 100 : 2000, toJSON: () => ({}),
            } as DOMRect);
        });
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);
        assert(plan.groupsForCache.length >= 20, `the parallel test needs several groups, got ${plan.groupsForCache.length}`);

        const visibleHashes = new Set(orderByViewport(plan.groupsForCache).ordered.slice(0, 10).map(group => group.hash));

        // Both lookups are issued before either answers; the visible batch is
        // released first and must apply before the rest answers.
        const log: FetchLogEntry[] = [];
        const order: string[] = [];
        let releaseVisible: (() => void) | null = null;
        let releaseRest: (() => void) | null = null;
        const impl = (async (url: string, init?: RequestInit) =>
        {
            const body = init?.body ? JSON.parse(String(init.body)) : {};
            log.push({ url, body });
            if (!isLookup(url))
            {
                return { ok: true, status: 200, json: async () => ({ ok: true }) };
            }
            const hashes = lookupHashes(url, body);
            const served = new Map<string, string | null>();
            for (const group of plan.groupsForCache)
            {
                const translated = group.markerPayload.replace(/Visible paragraph (\d+)/, 'Párrafo visible $1');
                if (hashes.includes(group.hash)) served.set(group.hash, translated);
            }
            const isVisibleBatch = hashes.every(hash => visibleHashes.has(hash));
            if (isVisibleBatch)
            {
                order.push('visible-issued');
                await new Promise<void>(resolve => { releaseVisible = resolve; });
                order.push('visible-applied');
            }
            else
            {
                order.push('rest-issued');
                await new Promise<void>(resolve => { releaseRest = resolve; });
                order.push('rest-applied');
            }
            const items: Record<string, string | null> = {};
            for (const hash of hashes) items[hash] = served.get(hash) ?? null;
            return { ok: true, status: 200, json: async () => ({ items }) };
        }) as unknown as typeof fetch;

        const applied: string[] = [];
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es',
            store: new HydrationStore(new InMemoryBackend()),
            fetchImpl: impl,
            delay: async () => {},
        });
        const originalApply = translator._applyGroupTranslation.bind(translator);
        translator._applyGroupTranslation = (groupCache, payload, translatedById, options) =>
        {
            applied.push(groupCache.group.id);
            originalApply(groupCache, payload, translatedById, options);
        };
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
        });

        let finished = false;
        const run = translator.translateBatch(entries).then(() => { finished = true; });

        // Let the translator reach both lookups.
        for (let tick = 0; tick < 10 && order.length < 2; tick++)
        {
            await new Promise(resolve => setTimeout(resolve, 0));
        }
        assert(order[0] === 'visible-issued' && order[1] === 'rest-issued', `both lookups must be issued before either answers, got ${JSON.stringify(order)}`);
        assert(!finished, 'the visible batch must not be applied yet');

        releaseVisible?.();
        for (let tick = 0; tick < 10 && !order.includes('visible-applied'); tick++)
        {
            await new Promise(resolve => setTimeout(resolve, 0));
        }
        assert(applied.length > 0, 'the visible batch applies as soon as it answers');
        assert(!order.includes('rest-applied'), 'the rest batch is still pending');

        releaseRest?.();
        await run;

        assert(finished, 'the translation run completes');
    });

    it('fires no beacon on a partially local batch — the lookup counts demand once', async () =>
    {
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);
        assert(plan.groupsForCache.length >= 2, 'test needs at least two groups');

        // Seed only the FIRST group locally; the second resolves via lookup.
        const backend = new InMemoryBackend();
        const [localGroup, ...rest] = plan.groupsForCache;
        backend.rows.set(localGroup.hash, { payload: localGroup.markerPayload.replace('Hello world', 'Hola mundo'), lastUsed: 1 });
        const lookupServed = new Map<string, string | null>();
        for (const group of rest) lookupServed.set(group.hash, group.markerPayload.replace('Second paragraph', 'Segundo párrafo'));

        const { impl, log } = createFetch([lookupServed]);
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es',
            store: new HydrationStore(backend),
            fetchImpl: impl,
            delay: async () => {},
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
        });

        const result = await translator.translateBatch(new DOMExtractor().extract(document.body));

        assert(result.failures.length === 0, `failures: ${JSON.stringify(result.failures)}`);
        const beacons = log.filter(entry => entry.url.endsWith('/hit-local'));
        const lookups = log.filter(entry => isLookup(entry.url));
        assert(beacons.length === 0, 'partial-local batch must NOT fire the beacon');
        assert(lookups.length === 1, 'exactly one lookup counts the demand');
    });

    it('stops polling a group the service never serves after the poll window and reports it pending', async () =>
    {
        // Every lookup answers a miss; the poll must end on its own.
        const { impl, log } = createFetch([new Map()]);
        const backend = new InMemoryBackend();
        let waitedMs = 0;
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es',
            store: new HydrationStore(backend),
            fetchImpl: impl,
            delay: async (ms: number) => { waitedMs += ms; },
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
        });

        const result = await translator.translateBatch(new DOMExtractor().extract(document.body));

        assert(waitedMs >= 60_000 && waitedMs < 70_000, `the poll ends after its window, waited ${waitedMs} ms`);
        assert(result.failures.length > 0 && result.failures.every(failure => failure.error === 'translation_pending'), `unserved groups are pending, got ${JSON.stringify(result.failures)}`);
        const polls = log.filter(entry => entry.url.includes('lookup-batch') || entry.url.includes('/lookup'));
        assert(polls.length < 40, `a bounded number of polls, got ${polls.length}`);
    });

    it('reports misses without any client hash and polls the translation in', async () =>
    {
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);
        const translatedByHash = new Map<string, string | null>();
        for (const group of plan.groupsForCache)
        {
            translatedByHash.set(group.hash, group.markerPayload.replace('Hello world', 'Hola mundo').replace('Second paragraph', 'Segundo párrafo'));
        }

        // First lookup: all misses. Second (poll): all present.
        const { impl, log } = createFetch([new Map(), translatedByHash]);
        const backend = new InMemoryBackend();
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es',
            store: new HydrationStore(backend),
            fetchImpl: impl,
            delay: async () => {},
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
        });

        const result = await translator.translateBatch(new DOMExtractor().extract(document.body));

        assert(result.failures.length === 0, `no failures expected, got ${JSON.stringify(result.failures)}`);
        assert(result.translated.some(entry => entry.value === 'Hola mundo'), 'polled translation must apply');

        const missReports = log.filter(entry => entry.url.endsWith('/miss'));
        assert(missReports.length === 1, 'exactly one miss report');
        const missItems = missReports[0].body.items as Array<Record<string, unknown>>;
        assert(missItems.length > 0);
        for (const item of missItems)
        {
            assert(item.markerPayload && item.groupId && item.contextKind, 'miss items carry content');
            assert(!('hash' in item) && !('cache' in item), 'miss items must never carry a client hash');
        }

        assert(!log.some(entry => entry.url.includes('/create-job')), 'browser job pipeline must not be used');
        assert(backend.rows.size > 0, 'polled translations must be stored for next time');
    });

    it('polls on a capped cadence (first 1s, then 2s) and drops each group as it is served', async () =>
    {
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);
        assert(plan.groupsForCache.length >= 2, 'cadence test needs at least two groups');
        const [firstGroup, ...restGroups] = plan.groupsForCache;

        // lookup #1 (initial remote lookup, no delay): all missing.
        // poll #1 (after the first 1s): serve the first group only.
        // poll #2 (after the 2s cap): serve the remaining groups.
        const servedFirst = new Map<string, string | null>();
        servedFirst.set(firstGroup.hash, firstGroup.markerPayload);
        const servedRest = new Map<string, string | null>(servedFirst);
        for (const group of restGroups) servedRest.set(group.hash, group.markerPayload);

        const { impl, log } = createFetch([new Map(), servedFirst, servedRest]);

        const recordedDelays: number[] = [];
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es',
            store: new HydrationStore(new InMemoryBackend()),
            fetchImpl: impl,
            delay: async (ms: number) => { recordedDelays.push(ms); },
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
        });

        const result = await translator.translateBatch(entries);

        assert(result.failures.length === 0, `no failures expected, got ${JSON.stringify(result.failures)}`);

        // The first poll waits 1s; every later poll waits at most the 2s cap and never grows beyond it.
        assert(recordedDelays.length >= 2, `expected at least two poll delays, got ${JSON.stringify(recordedDelays)}`);
        assert(recordedDelays[0] === 1000, `first poll delay must be 1000ms, got ${recordedDelays[0]}`);
        assert(recordedDelays.slice(1).every(ms => ms === 2000), `later poll delays must hold at the 2000ms cap, got ${JSON.stringify(recordedDelays)}`);
        assert(recordedDelays.every(ms => ms <= 2000), `no poll delay may exceed the 2000ms cap, got ${JSON.stringify(recordedDelays)}`);

        // A served group stops being polled on the very next lookup; still-missing groups keep being polled.
        const requestedHashes = log.filter(entry => isLookup(entry.url)).map(entry => lookupHashes(entry.url, entry.body));
        assert(requestedHashes.length === 3, `expected one initial lookup plus two polls, got ${requestedHashes.length}`);
        assert(requestedHashes[1].includes(firstGroup.hash), 'the first group is still polled before it is served');
        assert(!requestedHashes[2].includes(firstGroup.hash), 'a served group must not be polled again');
        assert(restGroups.every(group => requestedHashes[2].includes(group.hash)), 'still-missing groups keep being polled');
    });

    it('sends the canonical page url (lang stripped) with each miss report', async () =>
    {
        const originalTarget = `${window.location.pathname}${window.location.search}`;
        window.history.replaceState({}, '', '/shop/?lang=es&ref=42');
        try
        {
            const entries = new DOMExtractor().extract(document.body);
            const seedClient = new TranslationCacheClient({
                sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
            });
            const plan = await seedClient.prepare(entries);
            const translatedByHash = new Map<string, string | null>();
            for (const group of plan.groupsForCache)
            {
                translatedByHash.set(group.hash, group.markerPayload.replace('Hello world', 'Hola mundo').replace('Second paragraph', 'Segundo párrafo'));
            }

            // First lookup: all misses (forces a miss report). Second: served.
            const { impl, log } = createFetch([new Map(), translatedByHash]);
            const translator = new CacheFirstTranslator({
                sourceLang: 'en', targetLang: 'es',
                store: new HydrationStore(new InMemoryBackend()),
                fetchImpl: impl,
                delay: async () => {},
            });
            translator._createCacheClient = () => new TranslationCacheClient({
                sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
            });

            await translator.translateBatch(new DOMExtractor().extract(document.body));

            const missReports = log.filter(entry => entry.url.endsWith('/miss'));
            assert(missReports.length >= 1, 'at least one miss report must be sent');
            for (const report of missReports)
            {
                const url = report.body.url;
                assert(typeof url === 'string' && url.length > 0, `miss report must carry a url, got ${JSON.stringify(url)}`);
                assert((url as string).startsWith('https://'), `miss report url must be https-canonical, got ${url}`);
                assert(!(url as string).includes('lang='), `miss report url must strip the lang param, got ${url}`);
                assert((url as string).endsWith('/shop?ref=42'), `miss report url must trim the trailing slash and keep other query, got ${url}`);
            }
        }
        finally
        {
            window.history.replaceState({}, '', originalTarget || '/');
        }
    });

    it('reports every cold-cache group in chunks of at most forty', async () =>
    {
        document.body.innerHTML = `<main>${Array.from({ length: 81 }, (_, index) => `<p>Paragraph ${index}</p>`).join('')}</main>`;
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);
        assert(plan.groupsForCache.length > 40, 'test needs more than one miss-report chunk');

        const { impl, log } = createFetch([new Map()]);
        const controller = new AbortController();
        let polls = 0;
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es',
            store: new HydrationStore(new InMemoryBackend()),
            fetchImpl: impl,
            delay: async () => { polls++; if (polls === 2) controller.abort(); },
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
        });

        let aborted: Error | null = null;
        try
        {
            await translator.translateBatch(entries, { signal: controller.signal });
        }
        catch (error)
        {
            aborted = error as Error;
        }
        assert(aborted?.name === 'AbortError', `the never-served poll ends on the abort, got ${aborted}`);

        const missReports = log.filter(entry => entry.url.endsWith('/miss'));
        const reported = missReports.flatMap(entry => entry.body.items as Array<Record<string, unknown>>);
        assert(missReports.length === Math.ceil(plan.groupsForCache.length / 40), 'every miss-report chunk must be sent');
        assert(missReports.every(entry => (entry.body.items as unknown[]).length <= 40), 'each miss-report chunk must contain at most forty groups');
        assert(reported.length === plan.groupsForCache.length, 'every cold-cache group must be reported');
    });

    it('treats a 200 with skipped > 0 as accepted and warns once with the count', async () =>
    {
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);
        const translatedByHash = new Map<string, string | null>();
        for (const group of plan.groupsForCache)
        {
            translatedByHash.set(group.hash, group.markerPayload.replace('Hello world', 'Hola mundo').replace('Second paragraph', 'Segundo párrafo'));
        }

        const log: FetchLogEntry[] = [];
        const impl = (async (url: string, init?: RequestInit) =>
        {
            const body = init?.body ? JSON.parse(String(init.body)) : {};
            log.push({ url, body });

            if (isLookup(url))
            {
                const responses = log.filter(entry => isLookup(entry.url)).length > 1
                    ? translatedByHash
                    : new Map<string, string | null>();
                const items: Record<string, string | null> = {};
                for (const hash of lookupHashes(url, body)) items[hash] = responses.get(hash) ?? null;
                return { ok: true, status: 200, json: async () => ({ items }) };
            }
            if (url.endsWith('/miss'))
            {
                return { ok: true, status: 200, json: async () => ({ accepted: 1, deduped: 0, skipped: 1 }) };
            }
            throw new Error(`Unexpected fetch: ${url}`);
        }) as unknown as typeof fetch;

        const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
        try
        {
            const translator = new CacheFirstTranslator({
                sourceLang: 'en', targetLang: 'es',
                store: new HydrationStore(new InMemoryBackend()),
                fetchImpl: impl,
                delay: async () => {},
            });
            translator._createCacheClient = () => new TranslationCacheClient({
                sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
            });

            const result = await translator.translateBatch(new DOMExtractor().extract(document.body));

            assert(result.failures.length === 0, `a skipped>0 200 must still be treated as accepted, got ${JSON.stringify(result.failures)}`);
            const skippedWarnings = warnSpy.mock.calls.filter(call => String(call[0]).includes('skipped'));
            assert(skippedWarnings.length === 1, `expected exactly one skipped warning, got ${skippedWarnings.length}`);
            assert(String(skippedWarnings[0][0]).includes('1'), 'warning must name the skipped count');
        }
        finally
        {
            warnSpy.mockRestore();
        }
    });

    it('keeps originals and stops polling the moment the page is aborted', async () =>
    {
        document.body.innerHTML = '<main><p>Hello world</p><p>Second paragraph</p></main>';
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);

        // Every lookup answers empty, so the poll would run forever; the abort
        // is the only honest way out and the visitor keeps the source text.
        const { impl, log } = createFetch([new Map()]);
        const controller = new AbortController();
        let polls = 0;
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es',
            store: new HydrationStore(new InMemoryBackend()),
            fetchImpl: impl,
            delay: async (ms: number) => { polls++; if (polls === 3) controller.abort(); },
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
        });

        let aborted: Error | null = null;
        try
        {
            await translator.translateBatch(entries, { signal: controller.signal });
        }
        catch (error)
        {
            aborted = error as Error;
        }
        assert(aborted?.name === 'AbortError', `the poll loop must stop at the abort with an AbortError, got ${aborted}`);
        assert(polls === 3, `the poll loop must stop at the abort, got ${polls} rounds`);
        assert(log.filter(entry => entry.url.endsWith('/miss')).length === 1, 'exactly one miss report before polling');
    });

    it('still polls independently for its own groups after a coalesced accept', async () =>
    {
        const translatedByHash = new Map<string, string | null>();
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(new DOMExtractor().extract(document.body));
        for (const group of plan.groupsForCache)
        {
            translatedByHash.set(group.hash, group.markerPayload.replace('Hello world', 'Hola mundo').replace('Second paragraph', 'Segundo párrafo'));
        }

        const { impl, log } = createFetch([new Map(), translatedByHash]);
        const coalescer = new MissReportCoalescer({ fetchImpl: impl, delay: async () => {} });
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es',
            store: new HydrationStore(new InMemoryBackend()),
            fetchImpl: impl,
            delay: async () => {},
            missCoalescer: coalescer,
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
        });

        const result = await translator.translateBatch(new DOMExtractor().extract(document.body));

        assert(result.failures.length === 0, `no failures expected, got ${JSON.stringify(result.failures)}`);
        assert(result.translated.some(entry => entry.value === 'Hola mundo'), 'this translator must still poll its own groups in');
        assert(log.filter(entry => entry.url.endsWith('/miss')).length === 1, 'one coalesced miss report');
    });

    it('coalesces misses from several chunk translators sharing one collector into a single call', async () =>
    {
        document.body.innerHTML = `<main>${Array.from({ length: 5 }, (_, index) => `<p>Chunk paragraph ${index}</p>`).join('')}</main>`;
        const { impl, log } = createFetch([new Map()]);

        let releaseDebounce: (() => void) | null = null;
        const debounceDelay = () => new Promise<void>(resolve => { releaseDebounce = resolve; });
        const coalescer = new MissReportCoalescer({ fetchImpl: impl, delay: debounceDelay });

        const paragraphs = Array.from(document.querySelectorAll('p'));
        const extractor = new DOMExtractor();
        const controller = new AbortController();
        let polls = 0;
        const runs = paragraphs.map((paragraph) =>
        {
            const translator = new CacheFirstTranslator({
                sourceLang: 'en', targetLang: 'es',
                store: new HydrationStore(new InMemoryBackend()),
                fetchImpl: impl,
                delay: async () => { polls++; if (polls >= 10) controller.abort(); },
                missCoalescer: coalescer,
            });
            translator._createCacheClient = () => new TranslationCacheClient({
                sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
            });
            return translator.translateBatch(extractor.extract(paragraph), { signal: controller.signal }).catch((error: Error) => error);
        });

        // Let every chunk translator reach the collector before the debounce fires.
        for (let tick = 0; tick < 5; tick++)
        {
            await new Promise(resolve => setTimeout(resolve, 0));
        }
        releaseDebounce?.();

        const results = await Promise.all(runs);

        const missReports = log.filter(entry => entry.url.endsWith('/miss'));
        assert(missReports.length === 1, `five chunks must coalesce into one miss call, got ${missReports.length}`);
        const reportedPayloads = new Set(
            missReports.flatMap(entry => (entry.body.items as Array<Record<string, unknown>>).map(item => item.markerPayload))
        );
        assert(reportedPayloads.size === 5, `all five chunks' distinct content must be reported, got ${reportedPayloads.size}`);
        assert(results.every(result => (result as { failures?: unknown[] }).failures === undefined), 'a 200-with-no-payload accept keeps polling; only the abort ends the run');
    });

    it('stops polling the instant the last pending group is served', async () =>
    {
        document.body.innerHTML = '<main><p>Book a table</p><p>Opening hours</p></main>';
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'de', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);
        const servedAll = new Map<string, string | null>();
        for (const group of plan.groupsForCache)
        {
            servedAll.set(group.hash, group.markerPayload.split('Book a table').join('Tisch reservieren').split('Opening hours').join('Öffnungszeiten'));
        }

        // Lookup #1 (initial): every group missing. Poll #1: every group served.
        const { impl, log } = createFetch([new Map(), servedAll]);
        const recordedDelays: number[] = [];
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'de',
            store: new HydrationStore(new InMemoryBackend()),
            fetchImpl: impl,
            delay: async (ms: number) => { recordedDelays.push(ms); },
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'de', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
        });

        const result = await translator.translateBatch(entries);

        assert(result.failures.length === 0, `no failures expected, got ${JSON.stringify(result.failures)}`);
        assert(result.translated.some(entry => entry.value === 'Tisch reservieren'), 'the polled translation applies');
        const lookups = log.filter(entry => isLookup(entry.url));
        assert(lookups.length === 2, `one initial lookup plus a single poll, then it stops; got ${lookups.length}`);
        assert(recordedDelays.length === 1, `polling stops after the serving round; got ${JSON.stringify(recordedDelays)}`);
    });

    it('applies a healthy stream immediately with no origin poll and persists the values', async () =>
    {
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);
        const translated = new Map(plan.groupsForCache.map(group => [
            group.hash,
            group.markerPayload.replace('Hello world', 'Hola mundo').replace('Second paragraph', 'Segundo párrafo'),
        ]));
        const log: FetchLogEntry[] = [];
        const subscription = {
            url: `${API_BASE}/v1/translation/cache/events?token=signed`,
            expiresAt: new Date(Date.now() + 300_000).toISOString(),
        };
        const impl = (async (url: string, init?: RequestInit) =>
        {
            const body = init?.body ? JSON.parse(String(init.body)) : {};
            log.push({ url, body });
            if (isLookup(url)) return { ok: true, status: 200, json: async () => ({ items: {} }) };
            if (url.endsWith('/miss')) return { ok: true, status: 200, json: async () => ({ accepted: 2, subscription }) };
            if (url.endsWith('/hit-local')) return { ok: true, status: 200, json: async () => ({}) };
            throw new Error(`Unexpected fetch: ${url}`);
        }) as unknown as typeof fetch;
        const eventClient = {
            deliver: jest.fn(async ({ hashes, onReady }) =>
            {
                for (const hash of hashes) onReady(hash, translated.get(hash)!);
                return 'complete' as const;
            }),
        } as unknown as TranslationEventClient;
        const delay = jest.fn(async () => {});
        const backend = new InMemoryBackend();
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es', store: new HydrationStore(backend), fetchImpl: impl, delay, eventClient,
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE,
            aiSettings: basicFixture.settings, sampleOrigin: () => false, fetchImpl: impl,
        });

        const result = await translator.translateBatch(entries);

        expect(result.failures).toHaveLength(0);
        expect(result.translated.map(entry => entry.value)).toEqual(expect.arrayContaining(['Hola mundo', 'Segundo párrafo']));
        expect(delay).not.toHaveBeenCalled();
        expect(log.filter(entry => isLookup(entry.url))).toHaveLength(1);
        expect(eventClient.deliver).toHaveBeenCalledTimes(1);

        const secondLog: FetchLogEntry[] = [];
        const secondImpl = (async (url: string, init?: RequestInit) =>
        {
            secondLog.push({ url, body: init?.body ? JSON.parse(String(init.body)) : {} });
            return { ok: true, status: 200, json: async () => ({}) };
        }) as unknown as typeof fetch;
        const second = new CacheFirstTranslator({ sourceLang: 'en', targetLang: 'es', store: new HydrationStore(backend), fetchImpl: secondImpl });
        second._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE,
            aiSettings: basicFixture.settings, sampleOrigin: () => false, fetchImpl: secondImpl,
        });
        const warm = await second.translateBatch(new DOMExtractor().extract(document.body));
        expect(warm.failures).toHaveLength(0);
        expect(secondLog.filter(entry => isLookup(entry.url))).toHaveLength(0);
    });

    it('starts the origin poller only after stream fallback', async () =>
    {
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'de', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);
        const served = new Map(plan.groupsForCache.map(group => [
            group.hash,
            group.markerPayload.replace('Hello world', 'Hallo Welt').replace('Second paragraph', 'Zweiter Absatz'),
        ]));
        const { impl, log } = createFetch([new Map(), served], {
            subscription: {
                url: `${API_BASE}/v1/translation/cache/events?token=signed`,
                expiresAt: new Date(Date.now() + 300_000).toISOString(),
            },
        });
        const eventClient = { deliver: jest.fn(async () => 'fallback' as const) } as unknown as TranslationEventClient;
        const delays: number[] = [];
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'de', store: new HydrationStore(new InMemoryBackend()), fetchImpl: impl,
            delay: async milliseconds => { delays.push(milliseconds); }, eventClient,
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'de', siteId: 'site-1', apiBaseUrl: API_BASE,
            aiSettings: basicFixture.settings, sampleOrigin: () => false, fetchImpl: impl,
        });

        const result = await translator.translateBatch(entries);

        expect(result.failures).toHaveLength(0);
        expect(delays).toEqual([1000]);
        expect(log.filter(entry => isLookup(entry.url))).toHaveLength(2);
        expect(log.filter(entry => entry.url.includes('lookup-batch'))).toHaveLength(1);
        expect(eventClient.deliver).toHaveBeenCalledTimes(1);
    });

    it('clears only the group reported failed by the stream', async () =>
    {
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);
        const failedHash = plan.groupsForCache[0].hash;
        const translatedGroup = plan.groupsForCache[1];
        const subscription = {
            url: `${API_BASE}/v1/translation/cache/events?token=signed`,
            expiresAt: new Date(Date.now() + 300_000).toISOString(),
        };
        const impl = (async (url: string) =>
        {
            if (isLookup(url)) return { ok: true, status: 200, json: async () => ({ items: {} }) };
            if (url.endsWith('/miss')) return { ok: true, status: 200, json: async () => ({ subscription }) };
            throw new Error(`Unexpected fetch: ${url}`);
        }) as unknown as typeof fetch;
        const eventClient = {
            deliver: jest.fn(async ({ onReady, onFailed }) =>
            {
                onFailed(failedHash, 'translation_failed');
                onReady(translatedGroup.hash, translatedGroup.markerPayload.replace('Second paragraph', 'Segundo párrafo'));
                return 'complete' as const;
            }),
        } as unknown as TranslationEventClient;
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es', store: new HydrationStore(new InMemoryBackend()), fetchImpl: impl,
            delay: async () => {}, eventClient,
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE,
            aiSettings: basicFixture.settings, sampleOrigin: () => false, fetchImpl: impl,
        });

        const result = await translator.translateBatch(entries);

        expect(result.failures).toEqual([{ groupId: plan.groupsForCache[0].group.id, error: 'translation_failed' }]);
        expect(result.translated.some(entry => entry.value === 'Segundo párrafo')).toBe(true);
    });

    it('keeps polling a pending translation past any fixed window until the page is aborted', async () =>
    {
        document.body.innerHTML = '<main><p>Join the waitlist</p><p>Find your seat</p></main>';
        const entries = new DOMExtractor().extract(document.body);
        const createClient = (impl: typeof fetch) => () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'it', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
        });

        const { impl } = createFetch([new Map()]);
        const controller = new AbortController();
        let polls = 0;
        const recordedDelays: number[] = [];
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'it',
            store: new HydrationStore(new InMemoryBackend()),
            fetchImpl: impl,
            delay: async (ms: number) => { recordedDelays.push(ms); polls++; if (polls === 25) controller.abort(); },
        });
        translator._createCacheClient = createClient(impl);

        let aborted: Error | null = null;
        try
        {
            await translator.translateBatch(entries, { signal: controller.signal });
        }
        catch (error)
        {
            aborted = error as Error;
        }
        assert(aborted?.name === 'AbortError', `the loop ends on the abort with an AbortError, got ${aborted}`);
        assert(recordedDelays.length >= 25, `the poll loop runs past any window, got ${recordedDelays.length} rounds`);
        assert(recordedDelays[0] === 1000, `the first poll waits 1000ms, got ${recordedDelays[0]}`);
        assert(recordedDelays.slice(1).every(ms => ms === 2000), `later polls hold at the 2000ms cap, got ${JSON.stringify(recordedDelays.slice(1, 5))}`);
    });

    it('fires one hit-local for a resolved group however many times its text repeats', async () =>
    {
        document.body.innerHTML = '<main><p>Reserve a seat</p><p>Reserve a seat</p><p>Reserve a seat</p></main>';
        const entries = new DOMExtractor().extract(document.body);
        const seedClient = new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'fr', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings,
        });
        const plan = await seedClient.prepare(entries);
        // #158: identical strings collapse to one hash, so the repeated line is a
        // single resolved group, not one per occurrence.
        assert(plan.groupsForCache.length === 1, `repeated text must fold to one group, got ${plan.groupsForCache.length}`);

        const backend = new InMemoryBackend();
        for (const group of plan.groupsForCache)
        {
            backend.rows.set(group.hash, { payload: group.markerPayload.split('Reserve a seat').join('Réservez une place'), lastUsed: 1 });
        }

        const { impl, log } = createFetch([new Map()]);
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'fr',
            store: new HydrationStore(backend),
            fetchImpl: impl,
            delay: async () => {},
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'fr', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: basicFixture.settings, fetchImpl: impl,
        });

        const result = await translator.translateBatch(entries);

        assert(result.failures.length === 0, `failures: ${JSON.stringify(result.failures)}`);
        const translatedValues = result.translated.map(entry => entry.value);
        assert(translatedValues.filter(value => value === 'Réservez une place').length === 3, `every occurrence is served, got ${JSON.stringify(translatedValues)}`);
        const beacons = log.filter(entry => entry.url.endsWith('/hit-local'));
        const lookups = log.filter(entry => isLookup(entry.url));
        assert(lookups.length === 0, 'a fully local serve makes no network lookup');
        assert(beacons.length === 1, `exactly one beacon for the resolved group, not one per occurrence, got ${beacons.length}`);
    });
});

describe('hydrationLanguageOrder', () =>
{
    it('orders active, accept-language, then recent, capped and deduped', () =>
    {
        const order = hydrationLanguageOrder({
            activeLang: 'es',
            configuredLangs: ['es', 'de', 'fr', 'lv', 'it'],
            acceptLanguages: ['de-DE', 'fr'],
            recentLanguages: ['lv', 'es', 'it'],
            sourceLang: 'en',
        });
        assert(JSON.stringify(order) === JSON.stringify(['es', 'de', 'fr', 'lv']), `got ${JSON.stringify(order)}`);
    });

    it('never hydrates the source language or unconfigured locales', () =>
    {
        const order = hydrationLanguageOrder({
            activeLang: 'en',
            configuredLangs: ['en', 'es'],
            acceptLanguages: ['pt-BR', 'es'],
            recentLanguages: ['en'],
            sourceLang: 'en',
        });
        assert(JSON.stringify(order) === JSON.stringify(['es']), `got ${JSON.stringify(order)}`);
    });
});

function assert(condition: unknown, message = 'assertion failed'): asserts condition
{
    if (!condition) throw new Error(message);
}

it.each(['ready', 'cold'] as const)('identifies the %s path from actual initial cache coverage', async path =>
{
    const entries = new DOMExtractor().extract(document.body);
    const seed = new TranslationCacheClient({
        sourceLang: 'en', targetLang: 'fr', siteId: 'site-1', apiBaseUrl: API_BASE,
        aiSettings: basicFixture.settings,
    });
    const plan = await seed.prepare(entries);
    const served = new Map(plan.groupsForCache.map(group => [
        group.hash, group.markerPayload.replace('Hello world', 'Bonjour le monde'),
    ]));
    const { impl } = createFetch(path === 'ready' ? [served] : [new Map(), served]);
    const translator = new CacheFirstTranslator({
        sourceLang: 'en', targetLang: 'fr', store: new HydrationStore(new InMemoryBackend()),
        fetchImpl: impl, delay: async () => {},
    });
    translator._createCacheClient = () => new TranslationCacheClient({
        sourceLang: 'en', targetLang: 'fr', siteId: 'site-1', apiBaseUrl: API_BASE,
        aiSettings: basicFixture.settings, fetchImpl: impl, sampleOrigin: () => false,
    });
    const paths: string[] = [];
    const result = await translator.translateBatch(entries, { onServePath: value => paths.push(value) });
    expect(result.failures).toEqual([]);
    expect(paths).toEqual([path]);
});

it('never counts an unmatched marker fallback as a served DOM target', async () =>
{
    document.body.innerHTML = '<p>Hello <strong>world</strong></p>';
    const { TranslationEngine } = await import('../TranslationEngine');
    const engine = new TranslationEngine({ sourceLang: 'en', targetLang: 'fr' });
    const entries = engine.extract(document.body);
    const seed = new TranslationCacheClient({
        sourceLang: 'en', targetLang: 'fr', siteId: 'site-1', apiBaseUrl: API_BASE,
        aiSettings: basicFixture.settings,
    });
    const plan = await seed.prepare(entries);
    expect(plan.groupsForCache).toHaveLength(1);
    const partial = new Map([[plan.groupsForCache[0].hash, '⟪item_0001⟫Bonjour ']]);
    const { impl } = createFetch([partial]);
    const translator = new CacheFirstTranslator({ sourceLang: 'en', targetLang: 'fr',
        store: new HydrationStore(new InMemoryBackend()), fetchImpl: impl });
    translator._createCacheClient = () => new TranslationCacheClient({
        sourceLang: 'en', targetLang: 'fr', siteId: 'site-1', apiBaseUrl: API_BASE,
        aiSettings: basicFixture.settings, fetchImpl: impl, sampleOrigin: () => false,
    });
    engine.translator = translator;
    const applied: string[] = [];
    await engine.runEntries(entries, { onEntryWritten: ({ entry }) => applied.push(entry.value) });
    expect(document.querySelector('strong')!.textContent).toBe('world');
    expect(applied).toEqual(['Bonjour ']);
});
