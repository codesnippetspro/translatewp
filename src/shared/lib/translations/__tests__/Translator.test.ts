import { TextEncoder } from 'util';

import { EntryType } from '@/shared/lib/translations/constants';
import * as pushBatchLimits from '@/shared/lib/translations/pushBatchLimits';
import {
    TRANSLATION_PUSH_BATCH_MAX_MARKER_PAYLOAD_BYTES,
    estimatePushBatchItemBytes,
} from '@/shared/lib/translations/pushBatchLimits';
import {
    type GroupCacheMeta,
    type TranslationCachePlan,
    TranslationCacheClient,
} from '@/shared/lib/translations/TranslationCacheClient';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';
import { Translator } from '@/shared/lib/translations/Translator';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

const PROXY_BASE = 'https://example.com/wp-json/translatewp/v1/translation';
const API_BASE = 'https://api.example.test';

const createPlan = (): TranslationCachePlan =>
{
    const node = document.createTextNode('Hello');
    const liveEntry = new TranslatableEntry({
        id: 'page_entry_1',
        type: EntryType.TEXT,
        node,
        attributeName: null,
        value: 'Hello',
    });
    const canonicalEntry = new TranslatableEntry({
        id: 'item_0001',
        type: EntryType.TEXT,
        node,
        attributeName: null,
        value: 'Hello',
    });
    const group = {
        id: 'grp_00000001',
        kind: 'text-flow' as const,
        contextKey: 'main > p',
        entries: [liveEntry],
        markerPayload: '⟪page_entry_1⟫Hello',
    };
    const groupCache: GroupCacheMeta = {
        group,
        canonicalEntries: [canonicalEntry],
        markerPayload: '⟪item_0001⟫Hello',
        hash: 'hash-1',
        siteId: 'site-1',
    };

    return {
        groups: [group],
        groupsForCache: [groupCache],
    };
};

describe('Translator job proxy lifecycle', () =>
{
    const originalFetch = globalThis.fetch;
    const originalTranslateWP = window.TranslateWP;

    afterEach(() =>
    {
        globalThis.fetch = originalFetch;
        Object.defineProperty(window, 'TranslateWP', {
            value: originalTranslateWP,
            configurable: true,
            writable: true,
        });
        jest.restoreAllMocks();
    });

    it('routes create, push-batch, and status through the wp-json proxy', async () =>
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
                    ai_settings: {},
                },
            },
            configurable: true,
            writable: true,
        });

        const fetchImpl = jest.fn(async (url: string) =>
        {
            if (url.startsWith(`${PROXY_BASE}/create-job`))
            {
                return {
                    ok: true,
                    json: async () => ({
                        jobId: '00000000-0000-4000-8000-000000000099',
                        sourceLang: 'en',
                        targetLang: 'fr',
                    }),
                };
            }

            if (url.startsWith(`${PROXY_BASE}/push-batch`))
            {
                return { ok: true, json: async () => ({ ok: true }) };
            }

            if (url.startsWith(`${PROXY_BASE}/status`))
            {
                return {
                    ok: true,
                    json: async () => ({ state: 'done', jobId: '00000000-0000-4000-8000-000000000099' }),
                };
            }

            throw new Error(`Unexpected fetch URL: ${url}`);
        });
        globalThis.fetch = fetchImpl as unknown as typeof fetch;

        const cacheClient = {
            lookup: jest.fn()
                .mockResolvedValueOnce({
                    status: 'available',
                    entries: new Map([['hash-1', null]]),
                })
                .mockResolvedValueOnce({
                    status: 'available',
                    entries: new Map([['hash-1', '⟪item_0001⟫Bonjour']]),
                }),
        } as unknown as TranslationCacheClient;
        const onEntryTranslated = jest.fn();
        const translator = new Translator({ sourceLang: 'en', targetLang: 'fr' });

        const result = await translator._translateContextGroups(
            createPlan(),
            cacheClient,
            { onEntryTranslated },
        );

        expect(result.translatedById.get('page_entry_1')).toBe('Bonjour');
        expect(fetchImpl).toHaveBeenCalledWith(
            `${PROXY_BASE}/create-job`,
            expect.objectContaining({ method: 'POST' })
        );
        expect(fetchImpl).toHaveBeenCalledWith(
            `${PROXY_BASE}/push-batch`,
            expect.objectContaining({ method: 'POST' })
        );
        expect(fetchImpl).toHaveBeenCalledWith(
            `${PROXY_BASE}/status?jobId=00000000-0000-4000-8000-000000000099`,
            expect.objectContaining({ method: 'GET' })
        );
        expect(fetchImpl).not.toHaveBeenCalledWith(
            expect.stringContaining(`${API_BASE}/v1/translation/jobs`),
            expect.anything()
        );
    });

    it('reports started groups as failed when the signal aborts mid-flight', async () =>
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
                    ai_settings: {},
                },
            },
            configurable: true,
            writable: true,
        });

        const controller = new AbortController();
        const fetchImpl = jest.fn(async (url: string) =>
        {
            if (url.startsWith(`${PROXY_BASE}/create-job`))
            {
                return {
                    ok: true,
                    json: async () => ({
                        jobId: '00000000-0000-4000-8000-0000000000ab',
                        sourceLang: 'en',
                        targetLang: 'fr',
                    }),
                };
            }

            if (url.startsWith(`${PROXY_BASE}/push-batch`))
            {
                controller.abort();
                return { ok: true, json: async () => ({ ok: true }) };
            }

            if (url.startsWith(`${PROXY_BASE}/status`))
            {
                return { ok: true, json: async () => ({ state: 'pending', jobId: '00000000-0000-4000-8000-0000000000ab' }) };
            }

            throw new Error(`Unexpected fetch URL: ${url}`);
        });
        globalThis.fetch = fetchImpl as unknown as typeof fetch;

        const cacheClient = {
            lookup: jest.fn().mockResolvedValue({
                status: 'available',
                entries: new Map([['hash-1', null]]),
            }),
        } as unknown as TranslationCacheClient;
        const onGroupServeStarted = jest.fn();
        const onGroupServeFailed = jest.fn();
        const translator = new Translator({ sourceLang: 'en', targetLang: 'fr' });

        await expect(translator._translateContextGroups(
            createPlan(),
            cacheClient,
            { signal: controller.signal, onGroupServeStarted, onGroupServeFailed },
        )).rejects.toMatchObject({ name: 'AbortError' });

        expect(onGroupServeStarted).toHaveBeenCalledTimes(1);
        expect(onGroupServeFailed).toHaveBeenCalledTimes(1);
        const failedEntries = onGroupServeFailed.mock.calls[0][0] as Array<{ id: string }>;
        expect(failedEntries.map(entry => entry.id)).toEqual(['page_entry_1']);
    });

    it('sends the canonical page url (lang stripped) with every push-batch request', async () =>
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
                    ai_settings: {},
                },
            },
            configurable: true,
            writable: true,
        });

        const originalTarget = `${window.location.pathname}${window.location.search}`;
        window.history.replaceState({}, '', '/shop/?lang=it&ref=9');

        try
        {
            const pushBatchBodies: Array<Record<string, unknown>> = [];
            const fetchImpl = jest.fn(async (url: string, init?: RequestInit) =>
            {
                if (url.startsWith(`${PROXY_BASE}/create-job`))
                {
                    return {
                        ok: true,
                        json: async () => ({
                            jobId: '00000000-0000-4000-8000-000000000099',
                            sourceLang: 'en',
                            targetLang: 'it',
                        }),
                    };
                }

                if (url.startsWith(`${PROXY_BASE}/push-batch`))
                {
                    pushBatchBodies.push(JSON.parse(String(init?.body ?? '{}')));
                    return { ok: true, json: async () => ({ ok: true }) };
                }

                if (url.startsWith(`${PROXY_BASE}/status`))
                {
                    return {
                        ok: true,
                        json: async () => ({ state: 'done', jobId: '00000000-0000-4000-8000-000000000099' }),
                    };
                }

                throw new Error(`Unexpected fetch URL: ${url}`);
            });
            globalThis.fetch = fetchImpl as unknown as typeof fetch;

            const cacheClient = {
                lookup: jest.fn()
                    .mockResolvedValueOnce({
                        status: 'available',
                        entries: new Map([['hash-1', null]]),
                    })
                    .mockResolvedValueOnce({
                        status: 'available',
                        entries: new Map([['hash-1', '⟪item_0001⟫Ciao']]),
                    }),
            } as unknown as TranslationCacheClient;
            const translator = new Translator({ sourceLang: 'en', targetLang: 'it' });

            await translator._translateContextGroups(createPlan(), cacheClient, {});

            expect(pushBatchBodies.length).toBeGreaterThan(0);
            for (const body of pushBatchBodies)
            {
                const pushedUrl = body.url as string;
                expect(typeof pushedUrl).toBe('string');
                expect(pushedUrl.startsWith('https://')).toBe(true);
                expect(pushedUrl.includes('lang=')).toBe(false);
                expect(pushedUrl.endsWith('/shop?ref=9')).toBe(true);
            }
        }
        finally
        {
            window.history.replaceState({}, '', originalTarget);
        }
    });

    it('keeps source text and records failures when push-batch is rejected', async () =>
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
                    ai_settings: {},
                },
            },
            configurable: true,
            writable: true,
        });

        const warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
        const fetchImpl = jest.fn(async (url: string) =>
        {
            if (url.startsWith(`${PROXY_BASE}/create-job`))
            {
                return {
                    ok: true,
                    json: async () => ({
                        jobId: '00000000-0000-4000-8000-000000000099',
                        sourceLang: 'en',
                        targetLang: 'fr',
                    }),
                };
            }

            if (url.startsWith(`${PROXY_BASE}/push-batch`))
            {
                return { ok: false, status: 400, json: async () => ({ error: 'invalid_marker_payload' }) };
            }

            throw new Error(`Unexpected fetch URL: ${url}`);
        });
        globalThis.fetch = fetchImpl as unknown as typeof fetch;

        const cacheClient = {
            lookup: jest.fn().mockResolvedValue({
                status: 'available',
                entries: new Map([['hash-1', null]]),
            }),
        } as unknown as TranslationCacheClient;
        const translator = new Translator({ sourceLang: 'en', targetLang: 'fr' });

        const result = await translator._translateContextGroups(createPlan(), cacheClient);

        expect(result.translatedById.get('page_entry_1')).toBe('Hello');
        expect(result.failures).toHaveLength(1);
        expect(result.failures[0].error).toContain('invalid_marker_payload');
        expect(warnSpy).toHaveBeenCalledWith(
            expect.stringContaining('1 translation group(s) failed')
        );
        expect(fetchImpl).not.toHaveBeenCalledWith(
            expect.stringContaining(`${PROXY_BASE}/status`),
            expect.anything()
        );
    });

    it('attempts close-only recovery and cancels instead of polling when the final chunk fails', async () =>
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
                    ai_settings: {},
                },
            },
            configurable: true,
            writable: true,
        });

        const node = document.createTextNode('Hello');
        const liveEntryOne = new TranslatableEntry({
            id: 'page_entry_1',
            type: EntryType.TEXT,
            node,
            attributeName: null,
            value: 'Hello',
        });
        const liveEntryTwo = new TranslatableEntry({
            id: 'page_entry_2',
            type: EntryType.TEXT,
            node,
            attributeName: null,
            value: 'World',
        });
        const groupOne = {
            id: 'grp_00000001',
            kind: 'text-flow' as const,
            contextKey: 'main > p:nth-child(1)',
            entries: [liveEntryOne],
            markerPayload: '⟪page_entry_1⟫Hello',
        };
        const groupTwo = {
            id: 'grp_00000002',
            kind: 'text-flow' as const,
            contextKey: 'main > p:nth-child(2)',
            entries: [liveEntryTwo],
            markerPayload: '⟪page_entry_2⟫World',
        };
        const plan: TranslationCachePlan = {
            groups: [groupOne, groupTwo],
            groupsForCache: [
                {
                    group: groupOne,
                    canonicalEntries: [liveEntryOne],
                    markerPayload: '⟪item_0001⟫Hello',
                    hash: 'hash-1',
                    siteId: 'site-1',
                },
                {
                    group: groupTwo,
                    canonicalEntries: [liveEntryTwo],
                    markerPayload: '⟪item_0002⟫World',
                    hash: 'hash-2',
                    siteId: 'site-1',
                },
            ],
        };

        jest.spyOn(pushBatchLimits, 'chunkGroupsForPushBatch').mockImplementation((groups) =>
            [groups.slice(0, 1), groups.slice(1)]
        );

        let pushBatchCalls = 0;
        const fetchImpl = jest.fn(async (url: string, init?: RequestInit) =>
        {
            if (url.startsWith(`${PROXY_BASE}/create-job`))
            {
                return {
                    ok: true,
                    json: async () => ({
                        jobId: '00000000-0000-4000-8000-000000000099',
                        sourceLang: 'en',
                        targetLang: 'fr',
                    }),
                };
            }

            if (url.startsWith(`${PROXY_BASE}/push-batch`))
            {
                pushBatchCalls++;
                const body = JSON.parse(String(init?.body ?? '{}')) as {
                    closeInput?: boolean;
                    items?: unknown[];
                };

                // First two calls are the parallel batches (closeInput=false)
                if (pushBatchCalls <= 2)
                {
                    expect(body.closeInput).toBe(false);
                    // Second batch fails to exercise the failure path
                    if (2 === pushBatchCalls)
                    {
                        return { ok: false, status: 400, json: async () => ({ error: 'invalid_marker_payload' }) };
                    }
                    return { ok: true, json: async () => ({ ok: true }) };
                }

                // Third call is the close-only recovery (closeInput=true, empty items)
                expect(body.closeInput).toBe(true);
                expect(body.items).toEqual([]);
                return { ok: false, status: 503, json: async () => ({ error: 'upstream_unavailable' }) };
            }

            if (url.startsWith(`${PROXY_BASE}/cancel`))
            {
                return { ok: true, json: async () => ({ ok: true }) };
            }

            if (url.startsWith(`${PROXY_BASE}/status`))
            {
                throw new Error('status polling must not run for an unclosed job');
            }

            throw new Error(`Unexpected fetch URL: ${url}`);
        });
        globalThis.fetch = fetchImpl as unknown as typeof fetch;

        const cacheClient = {
            lookup: jest.fn().mockResolvedValue({
                status: 'available',
                entries: new Map([
                    ['hash-1', null],
                    ['hash-2', null],
                ]),
            }),
        } as unknown as TranslationCacheClient;
        const translator = new Translator({ sourceLang: 'en', targetLang: 'fr' });

        const result = await translator._translateContextGroups(plan, cacheClient);

        expect(result.translatedById.get('page_entry_1')).toBe('Hello');
        expect(result.translatedById.get('page_entry_2')).toBe('World');
        expect(pushBatchCalls).toBe(3);
        expect(fetchImpl).toHaveBeenCalledWith(
            `${PROXY_BASE}/cancel`,
            expect.objectContaining({ method: 'POST' })
        );
        expect(fetchImpl).not.toHaveBeenCalledWith(
            expect.stringContaining(`${PROXY_BASE}/status`),
            expect.anything()
        );
    });

    it('rejects oversized singleton groups before creating a translation job', async () =>
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
                    ai_settings: {},
                },
            },
            configurable: true,
            writable: true,
        });

        const oversizedMarker = '⟪item_0001⟫' + '🙂'.repeat(8_000);
        expect(
            estimatePushBatchItemBytes({
                groupId: 'grp_00000001',
                markerPayload: oversizedMarker,
                contextKey: 'main > p',
                contextKind: 'text-flow',
                cache: {
                    siteId: 'site-1',
                    hash: 'hash-1',
                },
            })
        ).toBeGreaterThan(TRANSLATION_PUSH_BATCH_MAX_MARKER_PAYLOAD_BYTES);

        const node = document.createTextNode('Hello');
        const liveEntry = new TranslatableEntry({
            id: 'page_entry_1',
            type: EntryType.TEXT,
            node,
            attributeName: null,
            value: 'Hello',
        });
        const plan: TranslationCachePlan = {
            groups: [{
                id: 'grp_00000001',
                kind: 'text-flow',
                contextKey: 'main > p',
                entries: [liveEntry],
                markerPayload: oversizedMarker,
            }],
            groupsForCache: [{
                group: {
                    id: 'grp_00000001',
                    kind: 'text-flow',
                    contextKey: 'main > p',
                    entries: [liveEntry],
                    markerPayload: oversizedMarker,
                },
                canonicalEntries: [liveEntry],
                markerPayload: oversizedMarker,
                hash: 'hash-1',
                siteId: 'site-1',
            }],
        };

        const fetchImpl = jest.fn();
        globalThis.fetch = fetchImpl as unknown as typeof fetch;

        const cacheClient = {
            lookup: jest.fn().mockResolvedValue({
                status: 'available',
                entries: new Map([['hash-1', null]]),
            }),
        } as unknown as TranslationCacheClient;

        const translator = new Translator({ sourceLang: 'en', targetLang: 'fr' });
        const result = await translator._translateContextGroups(plan, cacheClient);

        expect(result.failures).toHaveLength(1);
        expect(result.failures[0]?.error).toBe('Marker payload exceeds maximum size');
        expect(fetchImpl).not.toHaveBeenCalled();
    });
});

describe('Translator SHA-256 fingerprint', () =>
{
    const originalCrypto = globalThis.crypto;
    const originalFetch = globalThis.fetch;
    const originalTranslateWP = window.TranslateWP;

    afterEach(() =>
    {
        Object.defineProperty(globalThis, 'crypto', {
            value: originalCrypto,
            configurable: true,
        });
        globalThis.fetch = originalFetch;
        Object.defineProperty(window, 'TranslateWP', {
            value: originalTranslateWP,
            configurable: true,
            writable: true,
        });
        jest.restoreAllMocks();
    });

    it('translateBatch reaches cache lookup without crypto.subtle', async () =>
    {
        Object.defineProperty(globalThis, 'crypto', {
            value: {},
            configurable: true,
        });
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
                    ai_settings: {
                        preserve_branded_terms: true,
                        localized_date_time_formats: false,
                        enable_units_conversion: false,
                        context: 'website',
                        writing_style: 'neutral',
                        tone: 'neutral',
                        formality: 0,
                        creativity: 0,
                        lexicon_terms: [],
                    },
                },
            },
            configurable: true,
            writable: true,
        });

        const fetchImpl = jest.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ items: {} }),
        });
        globalThis.fetch = fetchImpl as unknown as typeof fetch;
        jest.spyOn(Translator.prototype, '_translateContextGroups').mockImplementation(async (plan, cacheClient) =>
        {
            await cacheClient.lookup(plan.groupsForCache);

            return {
                translatedById: new Map(),
                failures: [],
            };
        });

        const node = document.createTextNode('Hello');
        const entry = new TranslatableEntry({
            id: 'entry-1',
            type: EntryType.TEXT,
            node,
            attributeName: null,
            value: 'Hello',
        });
        const cacheClient = new TranslationCacheClient({
            sourceLang: 'en',
            targetLang: 'fr',
            siteId: 'site-1',
            apiBaseUrl: API_BASE,
            aiSettings: window.TranslateWP.options.ai_settings,
            sampleOrigin: () => false,
        });
        const plan = await cacheClient.prepare([entry]);
        const translator = new Translator({ sourceLang: 'en', targetLang: 'fr' });

        await expect(translator.translateBatch([entry])).resolves.toMatchObject({
            failures: [],
            totalGroups: 1,
        });
        const [lookupUrl, lookupInit] = fetchImpl.mock.calls[0];
        const parsedLookup = new URL(String(lookupUrl));
        expect(`${parsedLookup.origin}${parsedLookup.pathname}`).toBe(`${API_BASE}/v1/translation/cache/lookup`);
        expect(parsedLookup.searchParams.get('siteId')).toBe('site-1');
        expect(parsedLookup.searchParams.get('language')).toBe('fr');
        expect(parsedLookup.searchParams.get('generation')).toBe('1');
        expect(parsedLookup.searchParams.get('hydration')).toBe('0');
        expect(parsedLookup.searchParams.getAll('hash')).toEqual([plan.groupsForCache[0].hash]);
        expect(lookupInit).toEqual({ method: 'GET', signal: undefined });
    });

    it('caps poll interval at 2s max while processing', async () =>
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

        const sleepIntervals: number[] = [];
        const statusSequence = ['processing', 'processing', 'processing', 'done'];
        let statusIndex = 0;

        jest.spyOn(Translator.prototype, '_fetchJobStatus').mockImplementation(async () =>
            statusSequence[statusIndex++] ?? 'done');
        jest.spyOn(Translator.prototype, '_sleep').mockImplementation(async (ms: number) =>
            { sleepIntervals.push(ms); });

        const translator = new Translator({ sourceLang: 'en', targetLang: 'fr' });
        const result = await translator._waitForJobCompletion('job-1');

        expect(result).toBe('done');
        expect(sleepIntervals).toEqual([1000, 2000, 2000]);
    });

    it('runs push batches concurrently and one failing does not kill the other', async () =>
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

        const nodeA = document.createTextNode('Hello');
        const nodeB = document.createTextNode('World');
        const liveA = new TranslatableEntry({ id: 'a', type: EntryType.TEXT, node: nodeA, attributeName: null, value: 'Hello' });
        const liveB = new TranslatableEntry({ id: 'b', type: EntryType.TEXT, node: nodeB, attributeName: null, value: 'World' });
        const groupA = { id: 'g1', kind: 'text-flow' as const, contextKey: 'p', entries: [liveA], markerPayload: '⟪a⟫Hello' };
        const groupB = { id: 'g2', kind: 'text-flow' as const, contextKey: 'p', entries: [liveB], markerPayload: '⟪b⟫World' };
        const plan: TranslationCachePlan = {
            groups: [groupA, groupB],
            groupsForCache: [
                { group: groupA, canonicalEntries: [liveA], markerPayload: '⟪a⟫Hello', hash: 'h1', siteId: 'site-1' },
                { group: groupB, canonicalEntries: [liveB], markerPayload: '⟪b⟫World', hash: 'h2', siteId: 'site-1' },
            ],
        };

        jest.spyOn(pushBatchLimits, 'chunkGroupsForPushBatch').mockImplementation((groups) =>
            [groups.slice(0, 1), groups.slice(1)]);

        let pushCount = 0;
        let maxInFlight = 0;
        let closeSeen = false;

        const fetchImpl = jest.fn(async (url: string, init?: RequestInit) =>
        {
            if (url.startsWith(`${PROXY_BASE}/create-job`))
            {
                return { ok: true, json: async () => ({ jobId: 'job-parallel', sourceLang: 'en', targetLang: 'fr' }) };
            }
            if (url.startsWith(`${PROXY_BASE}/push-batch`))
            {
                const body = JSON.parse(String(init?.body ?? '{}')) as { closeInput?: boolean; items?: unknown[] };
                if (body.closeInput)
                {
                    closeSeen = true;
                    return { ok: true, json: async () => ({ ok: true }) };
                }
                pushCount++;
                maxInFlight = Math.max(maxInFlight, pushCount);
                const id = pushCount;

                // Batch 1 resolves on the next microtask tick; batch 2 resolves synchronously.
                // Under Promise.all both calls start before either resolves — maxInFlight reaches 2.
                // Under a sequential for-loop batch 2 never starts until batch 1 resolves — maxInFlight stays 1.
                if (1 === id)
                {
                    return new Promise(resolve =>
                    {
                        setTimeout(() =>
                        {
                            pushCount--;
                            resolve({ ok: true, json: async () => ({ ok: true }) } as any);
                        }, 0);
                    });
                }

                pushCount--;
                return { ok: false, status: 400, json: async () => ({ error: 'batch_error' }) };
            }
            if (url.startsWith(`${PROXY_BASE}/status`))
            {
                return { ok: true, json: async () => ({ state: 'done', jobId: 'job-parallel' }) };
            }
            throw new Error(`Unexpected fetch URL: ${url}`);
        });
        globalThis.fetch = fetchImpl as unknown as typeof fetch;

        const cacheClient = {
            lookup: jest.fn()
                .mockResolvedValueOnce({
                    status: 'available',
                    entries: new Map([['h1', null], ['h2', null]]),
                })
                .mockResolvedValueOnce({
                    status: 'available',
                    entries: new Map([['h1', '⟪a⟫Hola'], ['h2', null]]),
                }),
        } as unknown as TranslationCacheClient;
        const translator = new Translator({ sourceLang: 'en', targetLang: 'fr' });
        const result = await translator._translateContextGroups(plan, cacheClient);

        // Concurrency proof: maxInFlight reached 2 (both batches started before either resolved).
        // A sequential for-loop would keep maxInFlight at 1 — batch 2 can't start until batch 1 resolves.
        expect(maxInFlight).toBe(2);
        expect(closeSeen).toBe(true);
        // Batch 1 succeeded -> translation applied
        expect(result.translatedById.get('a')).toBe('Hola');
        // Batch 2 failed -> source text kept, failure recorded
        expect(result.translatedById.get('b')).toBe('World');
        const failedIds = result.failures.map(f => f.groupId);
        expect(failedIds).toContain('g2');
        expect(failedIds).not.toContain('g1');
    });

    it('dedupes groups with the same hash — pushes one, applies to all siblings', async () =>
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

        const node = document.createTextNode('Hello');
        const liveA = new TranslatableEntry({ id: 'a', type: EntryType.TEXT, node, attributeName: null, value: 'Hello' });
        const liveB = new TranslatableEntry({ id: 'b', type: EntryType.TEXT, node, attributeName: null, value: 'Hello' });
        const canonicalA = new TranslatableEntry({ id: 'item_0001', type: EntryType.TEXT, node, attributeName: null, value: 'Hello' });
        const canonicalB = new TranslatableEntry({ id: 'item_0001', type: EntryType.TEXT, node, attributeName: null, value: 'Hello' });
        const groupA = { id: 'g1', kind: 'text-flow' as const, contextKey: 'header', entries: [liveA], markerPayload: '⟪item_0001⟫Hello' };
        const groupB = { id: 'g2', kind: 'text-flow' as const, contextKey: 'footer', entries: [liveB], markerPayload: '⟪item_0001⟫Hello' };
        const plan: TranslationCachePlan = {
            groups: [groupA, groupB],
            groupsForCache: [
                { group: groupA, canonicalEntries: [canonicalA], markerPayload: '⟪item_0001⟫Hello', hash: 'same-hash', siteId: 'site-1' },
                { group: groupB, canonicalEntries: [canonicalB], markerPayload: '⟪item_0001⟫Hello', hash: 'same-hash', siteId: 'site-1' },
            ],
        };

        let pushedItems = 0;
        const fetchImpl = jest.fn(async (url: string, init?: RequestInit) =>
        {
            if (url.startsWith(`${PROXY_BASE}/create-job`))
            {
                return { ok: true, json: async () => ({ jobId: 'job-dedup', sourceLang: 'en', targetLang: 'fr' }) };
            }
            if (url.startsWith(`${PROXY_BASE}/push-batch`))
            {
                const body = JSON.parse(String(init?.body ?? '{}'));
                pushedItems += body.items?.length ?? 0;
                return { ok: true, json: async () => ({ ok: true }) };
            }
            if (url.startsWith(`${PROXY_BASE}/status`))
            {
                return { ok: true, json: async () => ({ state: 'done', jobId: 'job-dedup' }) };
            }
            throw new Error(`Unexpected fetch URL: ${url}`);
        });
        globalThis.fetch = fetchImpl as unknown as typeof fetch;

        const cacheClient = {
            lookup: jest.fn()
                .mockResolvedValueOnce({ status: 'available', entries: new Map([['same-hash', null]]) })
                .mockResolvedValueOnce({ status: 'available', entries: new Map([['same-hash', '⟪item_0001⟫Bonjour']]) }),
        } as unknown as TranslationCacheClient;
        const translator = new Translator({ sourceLang: 'en', targetLang: 'fr' });
        const result = await translator._translateContextGroups(plan, cacheClient);

        expect(pushedItems).toBe(1);
        expect(result.translatedById.get('a')).toBe('Bonjour');
        expect(result.translatedById.get('b')).toBe('Bonjour');
        expect(result.failures).toHaveLength(0);
    });
});
