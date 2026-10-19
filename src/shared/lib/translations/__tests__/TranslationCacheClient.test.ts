import { TextEncoder } from 'util';
import { webcrypto } from 'crypto';

import { EntryType } from '@/shared/lib/translations/constants';
import {
    TranslationCacheClient,
    type GroupCacheMeta,
} from '@/shared/lib/translations/TranslationCacheClient';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';

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

const AI_SETTINGS: Window['TranslateWP']['options']['ai_settings'] = {
    preserve_branded_terms: true,
    localized_date_time_formats: false,
    enable_units_conversion: false,
    context: 'website',
    writing_style: 'neutral',
    tone: 'neutral',
    formality: 0,
    creativity: 0,
    lexicon_terms: [],
};

const API_BASE_URL = 'https://api.example.test';
const SITE_ID = 'site-1';

function expectEdgeLookupRequest(
    fetchImpl: jest.Mock,
    hashes: string[],
    language = 'es',
    generation = 7,
    hydration = false
): void
{
    const [requestUrl, init] = fetchImpl.mock.calls.at(-1) ?? [];
    const url = new URL(String(requestUrl));

    expect(`${url.origin}${url.pathname}`).toBe(`${API_BASE_URL}/v1/translation/cache/lookup`);
    expect(url.searchParams.get('siteId')).toBe(SITE_ID);
    expect(url.searchParams.get('language')).toBe(language);
    expect(url.searchParams.get('generation')).toBe(String(generation));
    expect(url.searchParams.get('hydration')).toBe(hydration ? '1' : '0');
    expect(url.searchParams.getAll('hash')).toEqual(hashes);
    expect(init).toEqual({ method: 'GET', signal: undefined });
}

function expectOriginLookupRequest(
    fetchImpl: jest.Mock,
    hashes: string[],
    language = 'es'
): void
{
    expect(fetchImpl).toHaveBeenCalledWith(
        `${API_BASE_URL}/v1/translation/cache/lookup-batch`,
        {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            signal: undefined,
            body: JSON.stringify({
                siteId: SITE_ID,
                language,
                hashes,
                hydration: false,
            }),
        }
    );
}

function createTextEntry(id: string, value: string): TranslatableEntry
{
    return new TranslatableEntry({
        id,
        type: EntryType.TEXT,
        node: document.createTextNode(value),
        attributeName: null,
        value,
    });
}

function createClient(fetchImpl?: typeof fetch): TranslationCacheClient
{
    return new TranslationCacheClient({
        sourceLang: 'en',
        targetLang: 'es',
        siteId: SITE_ID,
        apiBaseUrl: API_BASE_URL,
        aiSettings: AI_SETTINGS,
        generation: 7,
        sampleOrigin: () => false,
        fetchImpl,
    });
}

function createGroupMeta(hash: string): GroupCacheMeta
{
    return {
        group: {
            id: `group-${hash}`,
            kind: 'text-flow',
            contextKey: 'body',
            entries: [],
            markerPayload: '',
        },
        canonicalEntries: [],
        markerPayload: '',
        hash,
        siteId: SITE_ID,
    };
}

describe('TranslationCacheClient.coverage', () =>
{
    it('returns empty when there are no groups', () =>
    {
        const client = createClient();

        expect(client.coverage([], new Map())).toBe('empty');
    });

    it('returns full when every hash has a payload', () =>
    {
        const client = createClient();
        const groups = [createGroupMeta('hash-a'), createGroupMeta('hash-b')];
        const cachedByHash = new Map<string, string | null>([
            ['hash-a', '⟪e1⟫Hola'],
            ['hash-b', '⟪e2⟫Mundo'],
        ]);

        expect(client.coverage(groups, cachedByHash)).toBe('full');
    });

    it('returns partial when any hash is null', () =>
    {
        const client = createClient();
        const groups = [createGroupMeta('hash-a'), createGroupMeta('hash-b')];
        const cachedByHash = new Map<string, string | null>([
            ['hash-a', '⟪e1⟫Hola'],
            ['hash-b', null],
        ]);

        expect(client.coverage(groups, cachedByHash)).toBe('partial');
    });
});

describe('TranslationCacheClient.prepare', () =>
{
    it('produces a stable hash for identical entries and settings', async () =>
    {
        const entries = [createTextEntry('entry-1', 'Hello world')];
        const first = await createClient().prepare(entries);
        const second = await createClient().prepare(entries);

        expect(first.groupsForCache).toHaveLength(1);
        expect(second.groupsForCache).toHaveLength(1);
        expect(first.groupsForCache[0].hash).toBe(second.groupsForCache[0].hash);
        expect(first.groupsForCache[0].hash).toMatch(/^[a-f0-9]{64}$/);
    });

    it('collapses duplicate source text across blocks into one cache entry and fans write-back to every occurrence', async () =>
    {
        document.body.innerHTML = '<header><p>Contact us.</p></header><footer><p>Contact us.</p></footer>';
        const nodes = Array.from(document.querySelectorAll('p')).map(element => element.firstChild as Text);
        const entries = nodes.map((node, index) => new TranslatableEntry({
            id: `entry-${index + 1}`,
            type: EntryType.TEXT,
            node,
            attributeName: null,
            value: node.nodeValue ?? '',
        }));

        const plan = await createClient().prepare(entries);

        // Two DOM blocks, but identical text of the same kind shares one hash,
        // so it becomes one cache entry and one miss.
        expect(plan.groups).toHaveLength(2);
        expect(plan.groupsForCache).toHaveLength(1);
        expect(plan.groupsForCache[0].canonicalEntries).toHaveLength(1);
        // The single group still carries every occurrence for write-back fan-out.
        expect(plan.groupsForCache[0].group.entries).toHaveLength(2);
        expect(plan.groupsForCache[0].group.entries.map(entry => entry.node)).toEqual(nodes);
    });

    it('keeps cache identity stable when unrelated sibling order and entry IDs change', async () =>
    {
        document.body.innerHTML = '<main><section><p>Hello world</p></section></main>';
        const firstNode = document.querySelector('p')?.firstChild as Text;
        const first = await createClient().prepare([
            new TranslatableEntry({
                id: 'page-entry-42',
                type: EntryType.TEXT,
                node: firstNode,
                attributeName: null,
                value: firstNode.nodeValue ?? '',
            }),
        ]);

        document.querySelector('main')?.insertAdjacentHTML('afterbegin', '<section>Unrelated content</section>');
        const secondNode = document.querySelectorAll('p')[0].firstChild as Text;
        const second = await createClient().prepare([
            new TranslatableEntry({
                id: 'page-entry-99',
                type: EntryType.TEXT,
                node: secondNode,
                attributeName: null,
                value: secondNode.nodeValue ?? '',
            }),
        ]);

        expect(second.groupsForCache[0].hash).toBe(first.groupsForCache[0].hash);
        expect(second.groupsForCache[0].markerPayload).toBe('⟪item_0001⟫Hello world');
    });
});

describe('TranslationCacheClient.lookup', () =>
{
    it('rejects when the cache lookup request fails', async () =>
    {
        const fetchImpl = jest.fn().mockResolvedValue({
            ok: false,
            status: 500,
        });
        const client = createClient(fetchImpl as unknown as typeof fetch);
        const groups = [createGroupMeta('hash-a')];

        await expect(client.lookup(groups)).rejects.toThrow(
            '[Translator] Failed to lookup cache batch (500).'
        );
        expectEdgeLookupRequest(fetchImpl as jest.Mock, ['hash-a']);
    });

    it('preserves mixed-case targetLang in lookup batch bodies', async () =>
    {
        const fetchImpl = jest.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ items: { 'hash-a': '⟪item_0001⟫Olá' } }),
        });
        const client = new TranslationCacheClient({
            sourceLang: 'en',
            targetLang: 'pt-BR',
            siteId: SITE_ID,
            apiBaseUrl: API_BASE_URL,
            aiSettings: AI_SETTINGS,
            generation: 7,
            sampleOrigin: () => false,
            fetchImpl: fetchImpl as unknown as typeof fetch,
        });

        await client.lookup([createGroupMeta('hash-a')]);

        expectEdgeLookupRequest(fetchImpl as jest.Mock, ['hash-a'], 'pt-BR');
    });

    it('turns an incomplete cached payload into a repairable miss', async () =>
    {
        const entry = createTextEntry('entry-1', 'Hello world');
        const group = (await createClient().prepare([entry])).groupsForCache[0];
        const fetchImpl = jest.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                items: {
                    [group.hash]: '⟪different_marker⟫Hola',
                },
            }),
        });

        const lookup = await createClient(fetchImpl as unknown as typeof fetch).lookup([group]);

        expectEdgeLookupRequest(fetchImpl as jest.Mock, [group.hash]);
        expect(lookup.status).toBe('available');
        if (lookup.status === 'available')
        {
            expect(lookup.entries.get(group.hash)).toBeNull();
        }
    });

    it('returns an unavailable result for a typed cache outage', async () =>
    {
        const fetchImpl = jest.fn().mockResolvedValue({
            ok: false,
            status: 503,
        });

        const lookup = await createClient(fetchImpl as unknown as typeof fetch).lookup([createGroupMeta('hash-a')]);

        expectEdgeLookupRequest(fetchImpl as jest.Mock, ['hash-a']);
        expect(lookup).toEqual({ status: 'unavailable' });
    });

    it('dedupes duplicate lookup hashes into one request body entry', async () =>
    {
        const groups = [
            createGroupMeta('shared-hash'),
            createGroupMeta('shared-hash'),
            createGroupMeta('other-hash'),
        ];
        const fetchImpl = jest.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                items: {
                    'shared-hash': '⟪item_0001⟫Shared',
                    'other-hash': '⟪item_0001⟫Other',
                },
            }),
        });

        await createClient(fetchImpl as unknown as typeof fetch).lookup(groups);

        expectEdgeLookupRequest(fetchImpl as jest.Mock, ['other-hash', 'shared-hash']);
    });

    it('chunks lookup-batch requests to 100 hashes', async () =>
    {
        const groups = Array.from({ length: 101 }, (_, index) => createGroupMeta(`hash-${index + 1}`));

        const fetchImpl = jest.fn()
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    items: Object.fromEntries(
                        Array.from({ length: 100 }, (_, index) => [`hash-${index + 1}`, `⟪item_0001⟫Value ${index + 1}`])
                    ),
                }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    items: {
                        'hash-101': '⟪item_0001⟫Value 101',
                    },
                }),
            });

        const client = createClient(fetchImpl as unknown as typeof fetch);
        const lookup = await client.lookupOrigin(groups);

        expect(fetchImpl).toHaveBeenCalledTimes(2);
        expectOriginLookupRequest(
            fetchImpl as jest.Mock,
            Array.from({ length: 100 }, (_, index) => `hash-${index + 1}`)
        );
        expect(JSON.parse(String(fetchImpl.mock.calls[1]?.[1]?.body)).hashes).toEqual(['hash-101']);
        expect(lookup.status).toBe('available');
    });
});

describe('TranslationCacheClient SHA-256 fingerprint', () =>
{
    const originalCrypto = globalThis.crypto;

    afterEach(() =>
    {
        Object.defineProperty(globalThis, 'crypto', {
            value: originalCrypto,
            configurable: true,
        });
    });

    it('matches SubtleCrypto when crypto.subtle is unavailable', async () =>
    {
        const entries = [createTextEntry('entry-1', 'Hello world')];
        const withSubtle = await createClient().prepare(entries);

        Object.defineProperty(globalThis, 'crypto', {
            value: {},
            configurable: true,
        });

        const withoutSubtle = await createClient().prepare(entries);

        expect(withoutSubtle.groupsForCache[0].hash).toBe(withSubtle.groupsForCache[0].hash);
        expect(withoutSubtle.groupsForCache[0].hash).toMatch(/^[a-f0-9]{64}$/);
    });

    it('prepare and lookup-batch work when crypto.subtle is unavailable', async () =>
    {
        Object.defineProperty(globalThis, 'crypto', {
            value: {},
            configurable: true,
        });

        const fetchImpl = jest.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ items: {} }),
        });
        const client = createClient(fetchImpl as unknown as typeof fetch);
        const entries = [createTextEntry('entry-1', 'Hello world')];
        const plan = await client.prepare(entries);

        await client.lookup(plan.groupsForCache);

        expectEdgeLookupRequest(fetchImpl as jest.Mock, [plan.groupsForCache[0].hash]);
    });

    it('uses origin POST for a sampled active lookup', async () =>
    {
        const fetchImpl = jest.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ items: {} }),
        });
        const client = new TranslationCacheClient({
            sourceLang: 'en',
            targetLang: 'es',
            siteId: SITE_ID,
            apiBaseUrl: API_BASE_URL,
            aiSettings: AI_SETTINGS,
            generation: 7,
            sampleOrigin: () => true,
            fetchImpl: fetchImpl as unknown as typeof fetch,
        });

        await client.lookup([createGroupMeta('hash-a')]);

        expectOriginLookupRequest(fetchImpl, ['hash-a']);
    });

    it('keeps hydration on edge GET when active sampling selects origin', async () =>
    {
        const fetchImpl = jest.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ items: {} }),
        });
        const client = new TranslationCacheClient({
            sourceLang: 'en',
            targetLang: 'es',
            siteId: SITE_ID,
            apiBaseUrl: API_BASE_URL,
            aiSettings: AI_SETTINGS,
            generation: 7,
            hydration: true,
            sampleOrigin: () => true,
            fetchImpl: fetchImpl as unknown as typeof fetch,
        });

        await client.lookup([createGroupMeta('hash-a')]);

        expectEdgeLookupRequest(fetchImpl, ['hash-a'], 'es', 7, true);
    });

    it('keeps every canonical GET below the URL budget', async () =>
    {
        const groups = Array.from({ length: 101 }, (_, index) =>
            createGroupMeta(index.toString(16).padStart(64, '0'))
        );
        const fetchImpl = jest.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ items: {} }),
        });

        await createClient(fetchImpl as unknown as typeof fetch).lookup(groups);

        expect(fetchImpl).toHaveBeenCalledTimes(2);
        for (const [requestUrl, init] of fetchImpl.mock.calls)
        {
            expect(String(requestUrl).length).toBeLessThanOrEqual(7500);
            expect(init.method).toBe('GET');
        }
    });
});
