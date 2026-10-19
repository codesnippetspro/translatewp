import { HydrationStore, type HydrationBackend } from '@/shared/lib/translations/hydrationStore';

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

    async deleteMany(hashes: string[]): Promise<void>
    {
        for (const hash of hashes) this.rows.delete(hash);
    }

    async keys(): Promise<string[]>
    {
        return [...this.rows.keys()];
    }

    async touch(): Promise<void> {}
    async evictLeastRecentlyUsed(): Promise<void> {}
    async estimateBytes(): Promise<number> { return 0; }
}

function assert(condition: unknown, message = 'assertion failed'): asserts condition
{
    if (!condition) throw new Error(message);
}

describe('HydrationStore cache generation', () =>
{
    it('returns raw-hash-keyed results regardless of the namespaced storage key', async () =>
    {
        const backend = new InMemoryBackend();
        const store = new HydrationStore(backend, 3);

        await store.putMany(new Map([['hash-a', 'payload-a']]));

        // The stored key is namespaced by the generation, never the bare hash.
        assert(!backend.rows.has('hash-a'), 'the bare hash must not be a storage key');
        assert([...backend.rows.keys()].some(key => key.includes('hash-a')), 'the stored key must carry the hash');

        const found = await store.getMany(['hash-a']);
        assert(found.get('hash-a') === 'payload-a', `getMany must key results by the raw hash, got ${JSON.stringify([...found])}`);
    });

    it('misses entries written under an earlier generation after a retranslation bump', async () =>
    {
        const backend = new InMemoryBackend();

        // The page was hydrated under generation 1 (before the retranslation).
        const before = new HydrationStore(backend, 1);
        await before.putMany(new Map([['hash-room', 'stale-de-room']]));
        assert((await before.getMany(['hash-room'])).get('hash-room') === 'stale-de-room', 'precondition: generation 1 reads its own entry');

        // A forced retranslation bumped the generation; the same backend, read
        // under the new generation, must miss the superseded local copy so the
        // visitor re-reads the overwritten API cache.
        const after = new HydrationStore(backend, 2);
        const found = await after.getMany(['hash-room']);
        assert(found.size === 0, `a bumped generation must not serve the stale local copy, got ${JSON.stringify([...found])}`);

        // The fresh value is stored under the new generation and is readable.
        await after.putMany(new Map([['hash-room', 'fresh-de-room']]));
        assert((await after.getMany(['hash-room'])).get('hash-room') === 'fresh-de-room', 'generation 2 reads its own fresh entry');
        // The superseding write also drops the row the old generation left behind,
        // so it can no longer occupy the budget it can never serve.
        assert((await before.getMany(['hash-room'])).size === 0, 'the superseded generation entry is pruned by the newer write');
    });

    it('an empty (default) generation keeps the bare hash as the storage key', async () =>
    {
        const backend = new InMemoryBackend();
        const store = new HydrationStore(backend);

        await store.putMany(new Map([['hash-x', 'payload-x']]));
        assert(backend.rows.has('hash-x'), 'an unversioned store must key by the bare hash for backward compatibility');
        assert((await store.getMany(['hash-x'])).get('hash-x') === 'payload-x', 'an unversioned store reads its entry');
    });

    it('F37: a bump of one language leaves the other language warm', async () =>
    {
        const backend = new InMemoryBackend();
        // fallback generation 1; German and French both start at 1.
        const before = new HydrationStore(backend, 1, { de: 1, fr: 1 });
        await before.putMany(new Map([['hash-de', 'payload-de']]), 'de');
        await before.putMany(new Map([['hash-fr', 'payload-fr']]), 'fr');

        // A German-only retranslation bumps de to 2; fr stays 1.
        const after = new HydrationStore(backend, 1, { de: 2, fr: 1 });
        assert((await after.getMany(['hash-de'], 'de')).size === 0, 'the bumped German entry is superseded');
        assert((await after.getMany(['hash-fr'], 'fr')).get('hash-fr') === 'payload-fr', 'French keeps its warm local entry after a German bump');
    });

    it('F37: a write under a language whose generation differs from the fallback round-trips only under that language', async () =>
    {
        const backend = new InMemoryBackend();
        // Fallback generation 1; German bumped to 2 by a retranslation.
        const store = new HydrationStore(backend, 1, { de: 2 });

        await store.putMany(new Map([['hash-de', 'payload-de']]), 'de');

        // The write is namespaced by German's own generation, never the fallback;
        // dropping the language argument would land it under the fallback, where
        // the visitor read under generation 2 would never find it.
        assert(backend.rows.has('2:hash-de'), 'the write is keyed by the language generation, not the fallback');
        assert(!backend.rows.has('1:hash-de'), 'the write does not land under the fallback generation');
        assert((await store.getMany(['hash-de'], 'de')).get('hash-de') === 'payload-de', 'the entry reads back under the same language');
    });

    it('prunes rows written under a superseded generation so they cannot crowd out live ones', async () =>
    {
        const backend = new InMemoryBackend();
        backend.rows.set('1:hash-old', { payload: 'old', lastUsed: 1 });
        backend.rows.set('hash-bare', { payload: 'legacy', lastUsed: 1 });
        backend.rows.set('2:hash-live', { payload: 'live', lastUsed: 1 });
        backend.rows.set('3:hash-de', { payload: 'de-live', lastUsed: 1 });
        const store = new HydrationStore(backend, 2, { de: 3 });

        await store.pruneStaleGenerations();

        assert([...backend.rows.keys()].sort().join() === '2:hash-live,3:hash-de', `stale generations and bare rows must go, got ${[...backend.rows.keys()]}`);
    });

    it('a write prunes superseded generations before the budget is enforced', async () =>
    {
        const backend = new InMemoryBackend();
        backend.rows.set('1:hash-old', { payload: 'old', lastUsed: 1 });
        const store = new HydrationStore(backend, 2);

        await store.putMany(new Map([['hash-new', 'fresh']]));

        assert(!backend.rows.has('1:hash-old'), 'the superseded row is dropped on write');
        assert(backend.rows.has('2:hash-new'), 'the new row lands under the live generation');
    });

    it('an unversioned store keeps its bare rows when pruning', async () =>
    {
        const backend = new InMemoryBackend();
        backend.rows.set('hash-bare', { payload: 'legacy', lastUsed: 1 });
        const store = new HydrationStore(backend);
        await store.pruneStaleGenerations();
        assert(backend.rows.has('hash-bare'), 'bare rows are the only rows an unversioned store has');
    });

    it('F37: a language absent from the map falls back to the site-wide version', async () =>
    {
        const backend = new InMemoryBackend();
        const store = new HydrationStore(backend, 4, { de: 9 });
        // Italian is not in the map, so it writes and reads under the fallback 4.
        await store.putMany(new Map([['hash-it', 'payload-it']]), 'it');
        assert(backend.rows.has('4:hash-it'), 'an unmapped language keys by the site-wide fallback');
        assert((await store.getMany(['hash-it'], 'it')).get('hash-it') === 'payload-it', 'an unmapped language reads its own entry');
    });
});

describe('HydrationStore with an IndexedDB that never answers', () =>
{
    const original = (globalThis as { indexedDB?: IDBFactory }).indexedDB;

    afterEach(() =>
    {
        (globalThis as { indexedDB?: IDBFactory }).indexedDB = original;
        jest.useRealTimers();
    });

    it('gives up on the open after a bound and serves without a local store', async () =>
    {
        jest.useFakeTimers();
        (globalThis as { indexedDB?: unknown }).indexedDB = {
            open: () => ({ onupgradeneeded: null, onsuccess: null, onerror: null, onblocked: null }),
        };
        const store = new HydrationStore();
        const pending = store.getMany(['abc'], 'de');
        await jest.advanceTimersByTimeAsync(1600);
        expect(await pending).toEqual(new Map());
    });
});
