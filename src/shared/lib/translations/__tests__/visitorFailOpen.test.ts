import { TextEncoder } from 'util';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

import { CacheFirstTranslator } from '@/shared/lib/translations/CacheFirstTranslator';
import { DOMExtractor } from '@/shared/lib/translations/DOMExtractor';
import { HydrationStore, type HydrationBackend } from '@/shared/lib/translations/hydrationStore';
import { TranslationCacheClient } from '@/shared/lib/translations/TranslationCacheClient';
import { TranslationEngine } from '@/shared/lib/translations/TranslationEngine';
import { Translator } from '@/shared/lib/translations/Translator';
import { getTranslationOutcome } from '@/apps/translatewp/translationOutcome';

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
            },
            options: { ai_settings: {} },
        },
        configurable: true,
        writable: true,
    });
}

const originalTranslateWP = window.TranslateWP;

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

const loadingClass = 'translatewp-translating';

const loadingElements = (): Element[] => Array.from(document.querySelectorAll(`.${loadingClass}`));

describe('TranslationEngine loading state', () =>
{
    it('does not mark entries loading before a serve attempt is in flight', async () =>
    {
        let markLoading: (() => void) | null = null;
        const translator = new Translator({ sourceLang: 'en', targetLang: 'es' });
        jest.spyOn(translator, 'translateBatch').mockImplementation(async () =>
        {
            await new Promise<void>(resolve => { markLoading = resolve; });
            return { translated: [], failures: [], totalGroups: 0 };
        });

        const engine = new TranslationEngine({ sourceLang: 'en', targetLang: 'es', translator });
        const runPromise = engine.run(document.body);

        await new Promise(resolve => window.setTimeout(resolve, 0));
        expect(loadingElements()).toHaveLength(0);

        markLoading?.();
        await runPromise;
    });

    it('shows no loading state over text without letters', async () =>
    {
        document.body.innerHTML = '<main><p>Hello world</p><p><span>12:30</span></p></main>';
        let release: (() => void) | null = null;
        const translator = new Translator({ sourceLang: 'en', targetLang: 'es' });
        jest.spyOn(translator, 'translateBatch').mockImplementation(async (entries, options = {}) =>
        {
            options.onGroupServeStarted?.(entries);
            await new Promise<void>(resolve => { release = resolve; });
            return { translated: [], failures: [], totalGroups: 0 };
        });

        const engine = new TranslationEngine({ sourceLang: 'en', targetLang: 'es', translator });
        const runPromise = engine.run(document.body);

        await new Promise(resolve => window.setTimeout(resolve, 0));
        expect(loadingElements().map(element => element.textContent)).toEqual(['Hello world']);

        release?.();
        await runPromise;
        expect(loadingElements()).toHaveLength(0);
    });

    it('clears loading immediately when the serve attempt fails', async () =>
    {
        const translator = new Translator({ sourceLang: 'en', targetLang: 'es' });
        jest.spyOn(translator, 'translateBatch').mockImplementation(async () =>
        {
            const entries = new DOMExtractor().extract(document.body);
            for (const entry of entries)
            {
                (entry.node.parentElement as Element).classList.add(loadingClass);
            }
            throw new Error('lookup failed');
        });

        const engine = new TranslationEngine({ sourceLang: 'en', targetLang: 'es', translator });
        await expect(engine.run(document.body)).rejects.toThrow('lookup failed');

        expect(loadingElements()).toHaveLength(0);
        expect(document.body.textContent).toContain('Hello world');
    });
});

describe('CacheFirstTranslator miss polling', () =>
{
    const missStatusFetch = (status: number, headers: Record<string, string> = {}) =>
        (async (url: string) =>
        {
            if (url.includes('/v1/translation/cache/lookup'))
            {
                return { ok: true, status: 200, json: async () => ({ items: {} }) } as Response;
            }
            if (url.endsWith('/miss'))
            {
                return { ok: status >= 200 && status < 300, status, headers: new Headers(headers), json: async () => ({}) } as Response;
            }
            throw new Error(`Unexpected fetch: ${url}`);
        }) as unknown as typeof fetch;

    const translatorFor = (fetchImpl: typeof fetch, delays: number[] = [], random: () => number = () => 0, onDelay?: (round: number) => void) =>
    {
        const translator = new CacheFirstTranslator({
            sourceLang: 'en', targetLang: 'es',
            store: new HydrationStore(new InMemoryBackend()),
            fetchImpl,
            delay: async (ms: number) => { delays.push(ms); onDelay?.(delays.length); },
            random,
        });
        translator._createCacheClient = () => new TranslationCacheClient({
            sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: {},
            sampleOrigin: () => false,
            fetchImpl: translator.fetchImpl,
        });
        return translator;
    };

    it('clears loading and keeps originals readable on a cold-cache outage', async () =>
    {
        const failingFetch = (async () =>
        {
            throw new Error('network down');
        }) as unknown as typeof fetch;
        const translator = translatorFor(failingFetch);
        const engine = new TranslationEngine({ sourceLang: 'en', targetLang: 'es', translator });

        const result = await engine.run(document.body);

        expect(result.failures.length).toBeGreaterThan(0);
        expect(document.body.textContent).toContain('Hello world');
        expect(document.body.textContent).toContain('Second paragraph');
        for (const element of Array.from(document.querySelectorAll('p')))
        {
            expect(element.getAttribute('data-translatewp-loading-count')).toBeNull();
            expect(element.classList.contains(loadingClass)).toBe(false);
        }
        expect(console).toHaveWarned();
    });

    it('keeps the skeleton visible while an accepted miss report is still polling', async () =>
    {
        const delays: number[] = [];
        const controller = new AbortController();
        let lookupCalls = 0;
        let loadingDuringPoll: string[] | null = null;
        const fetchImpl = (async (url: string) =>
        {
            if (url.includes('/v1/translation/cache/lookup'))
            {
                lookupCalls += 1;
                if (lookupCalls === 2)
                {
                    loadingDuringPoll = Array.from(document.querySelectorAll('p'))
                        .map((element) => element.getAttribute('data-translatewp-loading-count') ?? '');
                }
                return { ok: true, status: 200, json: async () => ({ items: {} }) } as Response;
            }
            if (url.endsWith('/miss'))
            {
                return { ok: true, status: 200, json: async () => ({ ok: true }) } as Response;
            }
            throw new Error(`Unexpected fetch: ${url}`);
        }) as unknown as typeof fetch;
        const translator = translatorFor(fetchImpl, delays, () => 0, round => { if (round === 2) controller.abort(); });
        const engine = new TranslationEngine({ sourceLang: 'en', targetLang: 'es', translator });

        await expect(engine.run(document.body, undefined, { signal: controller.signal }))
            .rejects.toMatchObject({ name: 'AbortError' });

        expect(loadingDuringPoll).not.toBeNull();
        expect(loadingDuringPoll!.every((count) => count === '1')).toBe(true);
        for (const element of Array.from(document.querySelectorAll('p')))
        {
            expect(element.getAttribute('data-translatewp-loading-count')).toBeNull();
            expect(element.classList.contains(loadingClass)).toBe(false);
        }
    });

    it.each([400, 409])('fails open without retrying on structurally invalid miss-report status %i', async (status) =>
    {
        const delays: number[] = [];
        const translator = translatorFor(missStatusFetch(status), delays);

        const result = await translator.translateBatch(new DOMExtractor().extract(document.body));

        expect(result.failures.length).toBeGreaterThan(0);
        expect(result.failures.every(failure => failure.error === 'miss_report_rejected')).toBe(true);
        expect(getTranslationOutcome({ failures: result.failures, totalGroups: result.totalGroups })).not.toBe('pending');
        expect(result.translated.every(entry => entry.value === 'Hello world' || entry.value === 'Second paragraph')).toBe(true);
        expect(delays).toHaveLength(0);
        expect(console).toHaveWarned();
    });

    it.each([500, 503])('retries twice with backoff on a server error (%i), then fails open', async (status) =>
    {
        const delays: number[] = [];
        let missCalls = 0;
        const fetchImpl = (async (url: string) =>
        {
            if (url.includes('/v1/translation/cache/lookup'))
            {
                return { ok: true, status: 200, json: async () => ({ items: {} }) } as Response;
            }
            if (url.endsWith('/miss'))
            {
                missCalls += 1;
                return { ok: false, status, headers: new Headers(), json: async () => ({}) } as Response;
            }
            throw new Error(`Unexpected fetch: ${url}`);
        }) as unknown as typeof fetch;
        const translator = translatorFor(fetchImpl, delays);

        const result = await translator.translateBatch(new DOMExtractor().extract(document.body));

        expect(missCalls).toBe(3);
        expect(delays).toEqual([1000, 2000]);
        expect(result.failures.length).toBeGreaterThan(0);
        expect(result.failures.every(failure => failure.error === 'miss_report_rejected')).toBe(true);
        expect(result.translated.every(entry => entry.value === 'Hello world' || entry.value === 'Second paragraph')).toBe(true);
        expect(console).toHaveWarned();
    });

    it('retries on 429 honoring Retry-After for as long as the poll window has budget, then fails open', async () =>
    {
        const delays: number[] = [];
        let missCalls = 0;
        const fetchImpl = (async (url: string) =>
        {
            if (url.includes('/v1/translation/cache/lookup'))
            {
                return { ok: true, status: 200, json: async () => ({ items: {} }) } as Response;
            }
            if (url.endsWith('/miss'))
            {
                missCalls += 1;
                return { ok: false, status: 429, headers: new Headers({ 'Retry-After': '2' }), json: async () => ({}) } as Response;
            }
            throw new Error(`Unexpected fetch: ${url}`);
        }) as unknown as typeof fetch;
        const translator = translatorFor(fetchImpl, delays);

        const result = await translator.translateBatch(new DOMExtractor().extract(document.body));

        // 90s budget / 2s Retry-After = 45 retries before the window is spent.
        expect(missCalls).toBe(46);
        expect(delays).toHaveLength(45);
        expect(delays.every(ms => ms === 2000)).toBe(true);
        expect(result.failures.length).toBeGreaterThan(0);
        expect(result.translated.every(entry => entry.value === 'Hello world' || entry.value === 'Second paragraph')).toBe(true);
        expect(console).toHaveWarned();
    });

    it('caps the 429 retry delay at the poll timeout', async () =>
    {
        const delays: number[] = [];
        let missCalls = 0;
        const fetchImpl = (async (url: string) =>
        {
            if (url.includes('/v1/translation/cache/lookup'))
            {
                return { ok: true, status: 200, json: async () => ({ items: {} }) } as Response;
            }
            if (url.endsWith('/miss'))
            {
                missCalls += 1;
                return { ok: false, status: 429, headers: new Headers({ 'Retry-After': '3600' }), json: async () => ({}) } as Response;
            }
            throw new Error(`Unexpected fetch: ${url}`);
        }) as unknown as typeof fetch;
        const translator = translatorFor(fetchImpl, delays);

        await translator.translateBatch(new DOMExtractor().extract(document.body));

        expect(missCalls).toBe(2);
        expect(delays).toEqual([90000]);
        expect(console).toHaveWarned();
    });

    it('polls at a capped cadence for one minute, then leaves the group pending for a later visit', async () =>
    {
        const delays: number[] = [];
        const translator = translatorFor(missStatusFetch(200), delays, () => 0, () => undefined);

        const result = await translator.translateBatch(new DOMExtractor().extract(document.body));

        expect(delays[0]).toBe(1000);
        expect(delays.slice(1).every(ms => ms === 2000)).toBe(true);
        expect(delays.reduce((sum, ms) => sum + ms, 0)).toBeGreaterThanOrEqual(60_000);
        expect(delays.length).toBeLessThan(40);
        expect(result.failures.every(failure => failure.error === 'translation_pending')).toBe(true);
    });
});

describe('TranslationEngine loading counter balance', () =>
{
    it('unmarks an entry whose serve started twice and was translated once', async () =>
    {
        document.body.innerHTML = '<main><p>Hello world</p></main>';
        const translator = new Translator({ sourceLang: 'en', targetLang: 'es' });
        jest.spyOn(translator, 'translateBatch').mockImplementation(async (entries, options = {}) =>
        {
            // The cache lookup announces the serve, then the miss path announces it again.
            options.onGroupServeStarted?.(entries);
            options.onGroupServeStarted?.(entries);
            const translated = entries.map(entry => entry.withTranslation('Hola mundo'));
            for (const entry of translated) options.onEntryTranslated?.(entry);
            return { translated, failures: [], totalGroups: 1 };
        });
        const engine = new TranslationEngine({ sourceLang: 'en', targetLang: 'es', translator });
        await engine.run(document.body);

        const p = document.querySelector('p') as HTMLElement;
        expect(p.textContent).toBe('Hola mundo');
        expect(p.classList.contains(loadingClass)).toBe(false);
        expect(p.getAttribute('data-translatewp-loading-count')).toBeNull();
        expect(p.getAttribute('aria-busy')).toBeNull();
    });

    it('leaves an element shared by two runs unmarked once both are over', async () =>
    {
        document.head.innerHTML = '<title>Hello world</title>';
        document.body.innerHTML = '<main><p>Body</p></main>';
        const releases: Array<() => void> = [];
        const make = () =>
        {
            const translator = new Translator({ sourceLang: 'en', targetLang: 'es' });
            jest.spyOn(translator, 'translateBatch').mockImplementation(async (entries, options = {}) =>
            {
                options.onGroupServeStarted?.(entries);
                await new Promise<void>(resolve => { releases.push(resolve); });
                return { translated: [], failures: [], totalGroups: 1 };
            });
            return new TranslationEngine({ sourceLang: 'en', targetLang: 'es', translator });
        };
        const first = make().run(document.head);
        await new Promise(resolve => window.setTimeout(resolve, 0));
        const second = make().run(document.head);
        await new Promise(resolve => window.setTimeout(resolve, 0));
        const title = document.querySelector('title') as HTMLElement;
        expect(title.getAttribute('data-translatewp-loading-count')).toBe('2');

        releases[0]?.();
        await first;
        releases[1]?.();
        await second;

        expect(title.classList.contains(loadingClass)).toBe(false);
        expect(title.getAttribute('aria-busy')).toBeNull();
        document.head.innerHTML = '';
    });

    it('unmarks an entry the batch settled without translating or failing', async () =>
    {
        document.body.innerHTML = '<main><p>Hello world</p></main>';
        const translator = new Translator({ sourceLang: 'en', targetLang: 'es' });
        jest.spyOn(translator, 'translateBatch').mockImplementation(async (entries, options = {}) =>
        {
            options.onGroupServeStarted?.(entries);
            return { translated: [], failures: [], totalGroups: 1 };
        });
        const engine = new TranslationEngine({ sourceLang: 'en', targetLang: 'es', translator });
        await engine.run(document.body);

        expect(loadingElements()).toHaveLength(0);
    });
});
