// Pre-hydrated translations live in IndexedDB, capped via
// navigator.storage.estimate() with LRU eviction. The store is an
// optimization, never a source of truth — losing it (Safari ITP, eviction)
// only costs a lookup.

// A stored payload the service answered identical to its source (a proper
// name) carries this prefix, marking it as a confirmed answer. A bare entry
// equal to the source predates the marker and is re-asked once, then stored
// with the marker.
export const CONFIRMED_SAME_PREFIX = '⟪twp-confirmed-same⟫';

export type HydrationBackend = {
    getMany(hashes: string[]): Promise<Map<string, string>>;
    putMany(entries: Map<string, string>): Promise<void>;
    deleteMany(hashes: string[]): Promise<void>;
    keys?(): Promise<string[]>;
    touch(hashes: string[]): Promise<void>;
    evictLeastRecentlyUsed(targetBytes: number): Promise<void>;
    estimateBytes(): Promise<number>;
};

const OPEN_TIMEOUT_MS = 1500;
const DB_NAME = 'translatewp-hydration';
const STORE = 'translations';
const MRU_KEY = 'translatewp-mru-languages';
const ACTIVE_KEY = 'translatewp-active-language';
const MAX_MRU = 4;
const STORAGE_BUDGET_FRACTION = 0.05;
const STORAGE_BUDGET_MAX_BYTES = 50 * 1024 * 1024;

type StoredRow = {
    hash: string;
    payload: string;
    lastUsed: number;
};

class IndexedDbBackend implements HydrationBackend
{
    #db: Promise<IDBDatabase | null> | null = null;

    #open(): Promise<IDBDatabase | null>
    {
        if (this.#db) return this.#db;

        this.#db = new Promise(resolve =>
        {
            if (typeof indexedDB === 'undefined')
            {
                resolve(null);
                return;
            }

            // An open request can queue forever behind a pending delete or a
            // version change another tab holds; the page then translates
            // without a local store rather than not at all.
            const timer = setTimeout(() => resolve(null), OPEN_TIMEOUT_MS);
            const settle = (db: IDBDatabase | null): void =>
            {
                clearTimeout(timer);
                resolve(db);
            };
            const request = indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = () =>
            {
                const db = request.result;
                if (!db.objectStoreNames.contains(STORE))
                {
                    const store = db.createObjectStore(STORE, { keyPath: 'hash' });
                    store.createIndex('lastUsed', 'lastUsed');
                }
            };
            request.onsuccess = () => settle(request.result);
            request.onerror = () => settle(null);
            request.onblocked = () => settle(null);
        });

        return this.#db;
    }

    async #transaction<T>(mode: IDBTransactionMode, run: (store: IDBObjectStore) => Promise<T>, fallback: T): Promise<T>
    {
        const db = await this.#open();
        if (!db) return fallback;

        try
        {
            return await run(db.transaction(STORE, mode).objectStore(STORE));
        }
        catch
        {
            return fallback;
        }
    }

    #requestValue<T>(request: IDBRequest<T>): Promise<T | undefined>
    {
        return new Promise(resolve =>
        {
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => resolve(undefined);
        });
    }

    async getMany(hashes: string[]): Promise<Map<string, string>>
    {
        return this.#transaction('readonly', async store =>
        {
            const found = new Map<string, string>();
            for (const hash of hashes)
            {
                const row = await this.#requestValue(store.get(hash)) as StoredRow | undefined;
                if (row?.payload) found.set(hash, row.payload);
            }
            return found;
        }, new Map<string, string>());
    }

    async putMany(entries: Map<string, string>): Promise<void>
    {
        await this.#transaction('readwrite', async store =>
        {
            const lastUsed = Date.now();
            for (const [hash, payload] of entries)
            {
                store.put({ hash, payload, lastUsed } satisfies StoredRow);
            }
            return undefined;
        }, undefined);
    }

    async deleteMany(hashes: string[]): Promise<void>
    {
        await this.#transaction('readwrite', async store =>
        {
            for (const hash of hashes) store.delete(hash);
            return undefined;
        }, undefined);
    }

    async keys(): Promise<string[]>
    {
        return this.#transaction('readonly', async store =>
            ((await this.#requestValue(store.getAllKeys())) as string[] | undefined) ?? [],
        []);
    }

    async touch(hashes: string[]): Promise<void>
    {
        await this.#transaction('readwrite', async store =>
        {
            const lastUsed = Date.now();
            for (const hash of hashes)
            {
                const row = await this.#requestValue(store.get(hash)) as StoredRow | undefined;
                if (row) store.put({ ...row, lastUsed });
            }
            return undefined;
        }, undefined);
    }

    async estimateBytes(): Promise<number>
    {
        return this.#transaction('readonly', async store =>
        {
            let bytes = 0;
            const rows = await this.#requestValue(store.getAll()) as StoredRow[] | undefined;
            for (const row of rows ?? [])
            {
                bytes += row.hash.length + row.payload.length * 2;
            }
            return bytes;
        }, 0);
    }

    async evictLeastRecentlyUsed(targetBytes: number): Promise<void>
    {
        await this.#transaction('readwrite', async store =>
        {
            const rows = ((await this.#requestValue(store.getAll()) as StoredRow[] | undefined) ?? [])
                .sort((left, right) => left.lastUsed - right.lastUsed);
            let bytes = rows.reduce((total, row) => total + row.hash.length + row.payload.length * 2, 0);
            for (const row of rows)
            {
                if (bytes <= targetBytes) break;
                store.delete(row.hash);
                bytes -= row.hash.length + row.payload.length * 2;
            }
            return undefined;
        }, undefined);
    }
}

export class HydrationStore
{
    // Every stored key is prefixed with the cache generation, so a forced
    // retranslation (which bumps the generation) makes the superseded local
    // entries unreachable and the next load re-reads the overwritten API cache.
    // The fingerprint hash is content-derived and unchanged by a retranslation,
    // so without this prefix a no-cache reload would still serve the stale copy.
    readonly #version: string;
    readonly #versionsByLanguage: Record<string, string>;

    constructor(
        private readonly backend: HydrationBackend = new IndexedDbBackend(),
        version: string | number = '',
        versionsByLanguage: Record<string, number> = {},
    )
    {
        this.#version = String(version);
        this.#versionsByLanguage = Object.fromEntries(
            Object.entries(versionsByLanguage).map(([language, value]) => [language, String(value)]),
        );
    }

    // The prefix is the language's own generation when it has one, else the
    // site-wide fallback. A language bumped by a retranslation supersedes only
    // its own local entries; every other language keeps its warm cache.
    #key(hash: string, language?: string): string
    {
        const version = language !== undefined && this.#versionsByLanguage[language] !== undefined
            ? this.#versionsByLanguage[language]
            : this.#version;
        return version === '' ? hash : `${version}:${hash}`;
    }

    async getMany(hashes: string[], language?: string): Promise<Map<string, string>>
    {
        const keyByStored = new Map(hashes.map(hash => [this.#key(hash, language), hash]));
        const storedHits = await this.backend.getMany([...keyByStored.keys()]);
        if (storedHits.size) void this.backend.touch([...storedHits.keys()]);

        const found = new Map<string, string>();
        for (const [storedKey, payload] of storedHits)
        {
            const hash = keyByStored.get(storedKey);
            if (hash !== undefined) found.set(hash, payload);
        }
        return found;
    }

    async putMany(entries: Map<string, string>, language?: string): Promise<void>
    {
        if (!entries.size) return;
        const namespaced = new Map<string, string>();
        for (const [hash, payload] of entries) namespaced.set(this.#key(hash, language), payload);
        await this.backend.putMany(namespaced);
        await this.enforceBudget();
    }

    // A corrected or regenerated segment drops its local copy so the next
    // read follows the served value rather than the stale one.
    async deleteMany(hashes: string[], language?: string): Promise<void>
    {
        if (!hashes.length) return;
        await this.backend.deleteMany(hashes.map(hash => this.#key(hash, language)));
    }

    // Rows written under a superseded generation can never be read again, yet
    // they still count toward the storage budget and can evict live entries.
    async pruneStaleGenerations(): Promise<void>
    {
        if (this.#version === '' || !this.backend.keys) return;
        const live = new Set([this.#version, ...Object.values(this.#versionsByLanguage)]);
        const stale = (await this.backend.keys()).filter(key =>
        {
            const separator = key.indexOf(':');
            return separator === -1 || !live.has(key.slice(0, separator));
        });
        if (stale.length) await this.backend.deleteMany(stale);
    }

    async enforceBudget(): Promise<void>
    {
        await this.pruneStaleGenerations();
        const budget = await this.storageBudgetBytes();
        const used = await this.backend.estimateBytes();
        if (used > budget)
        {
            await this.backend.evictLeastRecentlyUsed(budget);
        }
    }

    async storageBudgetBytes(): Promise<number>
    {
        try
        {
            const estimate = await navigator.storage?.estimate?.();
            const quota = estimate?.quota ?? 0;
            if (quota > 0)
            {
                return Math.min(STORAGE_BUDGET_MAX_BYTES, Math.floor(quota * STORAGE_BUDGET_FRACTION));
            }
        }
        catch
        {
            // Fall through to the fixed cap.
        }

        return STORAGE_BUDGET_MAX_BYTES;
    }

    recordLanguageUse(locale: string): void
    {
        try
        {
            const current = this.recentLanguages().filter(entry => entry !== locale);
            current.unshift(locale);
            localStorage.setItem(MRU_KEY, JSON.stringify(current.slice(0, MAX_MRU)));
            localStorage.setItem(ACTIVE_KEY, locale);
        }
        catch
        {
            // Storage unavailable (private mode) — hydration priorities degrade.
        }
    }

    recentLanguages(): string[]
    {
        try
        {
            const raw = localStorage.getItem(MRU_KEY);
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed.filter((entry): entry is string => typeof entry === 'string') : [];
        }
        catch
        {
            return [];
        }
    }

    // The stored-preference fast path reads a synchronous flag;
    // never an async IndexedDB open — so the skeleton decision cannot miss
    // first paint.
    activeLanguage(): string | null
    {
        try
        {
            return localStorage.getItem(ACTIVE_KEY);
        }
        catch
        {
            return null;
        }
    }
}

export default HydrationStore;
