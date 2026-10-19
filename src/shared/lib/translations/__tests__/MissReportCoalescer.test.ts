import { MissReportCoalescer } from '@/shared/lib/translations/MissReportCoalescer';
import type { TranslationCachePlan } from '@/shared/lib/translations/TranslationCacheClient';

type GroupCache = TranslationCachePlan['groupsForCache'][number];

const BASE_URL = 'https://example.com/wp-json/translatewp/v1/translation';
const API_URL = 'https://api.example.test';

function makeGroup(id: string): GroupCache
{
    return {
        hash: `hash-${id}`,
        markerPayload: `⟪item_0001⟫content ${id}`,
        canonicalEntries: [],
        siteId: 'site-1',
        group: {
            id,
            contextKey: 'p',
            kind: 'text-flow',
            entries: [],
        },
    } as unknown as GroupCache;
}

type FetchLogEntry = { url: string; body: { items: Array<Record<string, unknown>>; pageTitle?: string } };

function createFetch(responses: Array<{ ok: boolean; status: number; headers?: Record<string, string>; body?: Record<string, unknown> }> = []): { impl: typeof fetch; log: FetchLogEntry[] }
{
    const log: FetchLogEntry[] = [];
    let call = 0;
    const impl = (async (url: string, init?: RequestInit) =>
    {
        const body = init?.body ? JSON.parse(String(init.body)) : { items: [] };
        log.push({ url, body });
        const response = responses[call] ?? responses[responses.length - 1] ?? { ok: true, status: 200 };
        call++;
        return {
            ok: response.ok,
            status: response.status,
            headers: new Headers(response.headers ?? {}),
            json: async () => response.body ?? {},
        } as Response;
    }) as unknown as typeof fetch;

    return { impl, log };
}

describe('MissReportCoalescer', () =>
{
    it('merges reports from several contributors arriving within the debounce window into one call', async () =>
    {
        const { impl, log } = createFetch([{ ok: true, status: 200 }]);
        const coalescer = new MissReportCoalescer({ fetchImpl: impl, delay: async () => {} });

        const outcomes = await Promise.all(
            Array.from({ length: 5 }, (_, index) => coalescer.report({
                translationProxyBaseUrl: BASE_URL,
                remoteApiBaseUrl: API_URL,
                targetLang: 'es',
                pageUrl: 'https://example.test/',
                pageTitle: 'Example page',
                groups: [makeGroup(`chunk-${index}`)],
            })),
        );

        expect(outcomes).toEqual(Array.from({ length: 5 }, () => ({ outcome: 'accepted', subscriptions: [] })));
        expect(log).toHaveLength(1);
        expect(log[0].body.items).toHaveLength(5);
        expect(log[0].body.pageTitle).toBe('Example page');
    });

    it('still chunks a merged batch larger than forty items into multiple calls', async () =>
    {
        const { impl, log } = createFetch([{ ok: true, status: 200 }]);
        const coalescer = new MissReportCoalescer({ fetchImpl: impl, delay: async () => {} });

        const groups = Array.from({ length: 45 }, (_, index) => makeGroup(`bulk-${index}`));
        await Promise.all(groups.map(group => coalescer.report({
            translationProxyBaseUrl: BASE_URL,
            remoteApiBaseUrl: API_URL,
            targetLang: 'es',
            pageUrl: 'https://example.test/',
            pageTitle: 'Example page',
            groups: [group],
        })));

        expect(log).toHaveLength(2);
        expect(log[0].body.items).toHaveLength(40);
        expect(log[1].body.items).toHaveLength(5);
    });

    it('keeps separate target languages in separate queues', async () =>
    {
        const { impl, log } = createFetch([{ ok: true, status: 200 }]);
        const coalescer = new MissReportCoalescer({ fetchImpl: impl, delay: async () => {} });

        await Promise.all([
            coalescer.report({ translationProxyBaseUrl: BASE_URL, remoteApiBaseUrl: API_URL, targetLang: 'es', pageUrl: 'https://example.test/', pageTitle: 'Example page', groups: [makeGroup('es-1')] }),
            coalescer.report({ translationProxyBaseUrl: BASE_URL, remoteApiBaseUrl: API_URL, targetLang: 'de', pageUrl: 'https://example.test/', pageTitle: 'Example page', groups: [makeGroup('de-1')] }),
        ]);

        expect(log).toHaveLength(2);
        const bodies = log.map(entry => entry.body as { language?: string; items: unknown[] });
        expect(bodies.every(body => body.items.length === 1)).toBe(true);
        expect(bodies.map(body => body.language).sort()).toEqual(['de', 'es']);
    });

    it('resolves a contributor as rejected when its groups land in a failed chunk', async () =>
    {
        const { impl } = createFetch([{ ok: false, status: 400 }]);
        const coalescer = new MissReportCoalescer({ fetchImpl: impl, delay: async () => {} });

        const outcome = await coalescer.report({
            translationProxyBaseUrl: BASE_URL,
            remoteApiBaseUrl: API_URL,
            targetLang: 'es',
            pageUrl: 'https://example.test/',
            pageTitle: 'Example page',
            groups: [makeGroup('bad-1')],
        });

        expect(outcome).toEqual({ outcome: 'rejected', subscriptions: [] });
        expect(console).toHaveWarned();
    });

    it('propagates a network failure to every waiter of that flush', async () =>
    {
        const impl = (async () => { throw new Error('network down'); }) as unknown as typeof fetch;
        const coalescer = new MissReportCoalescer({ fetchImpl: impl, delay: async () => {} });

        await expect(coalescer.report({
            translationProxyBaseUrl: BASE_URL,
            remoteApiBaseUrl: API_URL,
            targetLang: 'es',
            pageUrl: 'https://example.test/',
            pageTitle: 'Example page',
            groups: [makeGroup('boom-1')],
        })).rejects.toThrow('network down');
    });

    it('returns every accepted subscription covering each waiter across chunks', async () =>
    {
        const first = { url: `${API_URL}/v1/translation/cache/events?token=first`, expiresAt: new Date(Date.now() + 300_000).toISOString() };
        const second = { url: `${API_URL}/v1/translation/cache/events?token=second`, expiresAt: new Date(Date.now() + 300_000).toISOString() };
        const { impl } = createFetch([
            { ok: true, status: 200, body: { subscription: first } },
            { ok: true, status: 200, body: { subscription: second } },
        ]);
        const coalescer = new MissReportCoalescer({ fetchImpl: impl, delay: async () => {} });
        const groups = Array.from({ length: 41 }, (_, index) => makeGroup(`bulk-${index}`));

        const outcome = await coalescer.report({
            translationProxyBaseUrl: BASE_URL,
            remoteApiBaseUrl: API_URL,
            targetLang: 'es',
            pageUrl: 'https://example.test/',
            pageTitle: 'Example page',
            groups,
        });

        expect(outcome).toEqual({
            outcome: 'accepted',
            subscriptions: [
                { ...first, hashes: groups.slice(0, 40).map(group => group.hash) },
                { ...second, hashes: [groups[40].hash] },
            ],
        });
    });

    it('returns no subscription when any covering chunk is rejected', async () =>
    {
        const first = { url: `${API_URL}/v1/translation/cache/events?token=first`, expiresAt: new Date(Date.now() + 300_000).toISOString() };
        const { impl } = createFetch([
            { ok: true, status: 200, body: { subscription: first } },
            { ok: false, status: 400 },
        ]);
        const coalescer = new MissReportCoalescer({ fetchImpl: impl, delay: async () => {} });
        const groups = Array.from({ length: 41 }, (_, index) => makeGroup(`bulk-${index}`));

        const outcome = await coalescer.report({
            translationProxyBaseUrl: BASE_URL,
            remoteApiBaseUrl: API_URL,
            targetLang: 'es',
            pageUrl: 'https://example.test/',
            pageTitle: 'Example page',
            groups,
        });

        expect(outcome).toEqual({ outcome: 'rejected', subscriptions: [] });
        expect(console).toHaveWarned();
    });

    it('drops an unsafe subscription without rejecting accepted work', async () =>
    {
        const { impl } = createFetch([{
            ok: true,
            status: 200,
            body: {
                subscription: {
                    url: 'https://attacker.example/v1/translation/cache/events?token=stolen',
                    expiresAt: new Date(Date.now() + 300_000).toISOString(),
                },
            },
        }]);
        const coalescer = new MissReportCoalescer({ fetchImpl: impl, delay: async () => {} });

        const outcome = await coalescer.report({
            translationProxyBaseUrl: BASE_URL,
            remoteApiBaseUrl: API_URL,
            targetLang: 'es',
            pageUrl: 'https://example.test/',
            pageTitle: 'Example page',
            groups: [makeGroup('safe')],
        });

        expect(outcome).toEqual({ outcome: 'accepted', subscriptions: [] });
    });
});
