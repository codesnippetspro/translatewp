import { ContextGroup, ContextGrouping } from '@/shared/lib/translations/ContextGrouping';
import {
    computeFingerprintHash,
    TRANSLATION_FINGERPRINT_SCHEMA_VERSION,
    type TranslationFingerprintSettings,
} from '@/shared/lib/translations/fingerprintPayload';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';

export const TRANSLATION_CACHE_SCHEMA_VERSION = TRANSLATION_FINGERPRINT_SCHEMA_VERSION;

export type LanguageCacheCoverage = 'empty' | 'full' | 'partial';

export type GroupCacheMeta = {
    group: ContextGroup;
    canonicalEntries: TranslatableEntry[];
    markerPayload: string;
    hash: string;
    siteId: string;
};

export type TranslationCachePlan = {
    groups: ContextGroup[];
    groupsForCache: GroupCacheMeta[];
};

export type TranslationCacheLookupResult =
    | { status: 'available'; entries: Map<string, string | null> }
    | { status: 'unavailable' };

export type TranslationCacheClientOptions = {
    sourceLang: string;
    targetLang: string;
    siteId: string;
    hydration?: boolean;
    generation?: number;
    apiBaseUrl: string;
    aiSettings: TranslationFingerprintSettings;
    sampleOrigin?: () => boolean;
    fetchImpl?: typeof fetch;
};

export const LOOKUP_BATCH_MAX_HASHES = 100;
export const EDGE_LOOKUP_MAX_URL_LENGTH = 7500;

export function cacheGenerationForLanguage(
    context: Pick<Window['TranslateWP']['context'], 'cache_version' | 'cache_versions'>,
    language: string
): number
{
    const generation = context.cache_versions?.[language] ?? context.cache_version;
    return Number.isInteger(generation) && generation > 0 ? generation : 1;
}

export class TranslationCacheClient
{
    sourceLang: string;
    targetLang: string;
    siteId: string;
    hydration: boolean;
    generation: number;
    apiBaseUrl: string;
    aiSettings: TranslationFingerprintSettings;
    sampleOrigin: () => boolean;
    fetchImpl: typeof fetch;

    constructor(options: TranslationCacheClientOptions)
    {
        this.sourceLang = options.sourceLang;
        this.targetLang = options.targetLang;
        this.siteId = options.siteId;
        this.hydration = options.hydration ?? false;
        this.generation = Number.isInteger(options.generation) && Number(options.generation) > 0
            ? Number(options.generation)
            : 1;
        this.apiBaseUrl = options.apiBaseUrl.replace(/\/+$/, '');
        this.aiSettings = options.aiSettings;
        this.sampleOrigin = options.sampleOrigin ?? (() => Math.random() < (1 / 32));
        this.fetchImpl = options.fetchImpl ?? ((input, init) => globalThis.fetch(input, init));
    }

    async prepare(entries: TranslatableEntry[]): Promise<TranslationCachePlan>
    {
        const groups = ContextGrouping.build(entries);
        const groupsForCache: GroupCacheMeta[] = [];
        const metaByHash = new Map<string, GroupCacheMeta>();

        for (const group of groups)
        {
            const canonicalEntries = this._canonicalizeEntries(
                ContextGrouping.entriesForMarkers(this._dedupeGroupEntries(group.entries))
            );

            if (!canonicalEntries.length)
            {
                continue;
            }

            const markerPayload = ContextGrouping.serializeWithMarkers(canonicalEntries);
            const hash = await this._buildContextHash(markerPayload, group);

            // With contextKey out of the fingerprint, identical source text of
            // the same kind collapses to one hash. Fold repeats into one cache
            // entry (one lookup, one miss) and fan the single translation back
            // out to every occurrence on write-back.
            const existing = metaByHash.get(hash);
            if (existing)
            {
                existing.group = {
                    ...existing.group,
                    entries: [...existing.group.entries, ...group.entries],
                };
                continue;
            }

            const meta: GroupCacheMeta = {
                group,
                canonicalEntries,
                markerPayload,
                hash,
                siteId: this.siteId,
            };
            metaByHash.set(hash, meta);
            groupsForCache.push(meta);
        }

        return {
            groups: groups.filter(group => group.entries.length > 0),
            groupsForCache,
        };
    }

    async lookup(
        groups: GroupCacheMeta[],
        signal?: AbortSignal
    ): Promise<TranslationCacheLookupResult>
    {
        if (!groups.length)
        {
            return { status: 'available', entries: new Map<string, string>() };
        }

        if (!this.hydration && this.sampleOrigin())
        {
            return this.lookupOrigin(groups, signal);
        }

        const responses = [];
        for (const url of this._edgeLookupUrls(groups))
        {
            responses.push(await this.fetchImpl(url, { method: 'GET', signal }));
        }

        return this._readLookupResponses(groups, responses);
    }

    async lookupOrigin(
        groups: GroupCacheMeta[],
        signal?: AbortSignal
    ): Promise<TranslationCacheLookupResult>
    {
        if (!groups.length)
        {
            return { status: 'available', entries: new Map<string, string>() };
        }

        const uniqueHashes = [...new Set(groups.map(group => group.hash))];
        const responses = [];

        for (let index = 0; index < uniqueHashes.length; index += LOOKUP_BATCH_MAX_HASHES)
        {
            const chunk = uniqueHashes.slice(index, index + LOOKUP_BATCH_MAX_HASHES);
            responses.push(await this.fetchImpl(`${this.apiBaseUrl}/v1/translation/cache/lookup-batch`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                signal,
                body: JSON.stringify(this._lookupRequestBody(chunk)),
            }));
        }

        return this._readLookupResponses(groups, responses);
    }

    async _readLookupResponses(
        groups: GroupCacheMeta[],
        responses: Response[]
    ): Promise<TranslationCacheLookupResult>
    {
        const mergedItems = new Map<string, string | null>();

        for (const response of responses)
        {
            if (response.status === 503)
            {
                return { status: 'unavailable' };
            }

            if (!response.ok)
            {
                throw new Error(`[Translator] Failed to lookup cache batch (${response.status}).`);
            }

            const payload = await response.json() as { items?: Record<string, string | null> };
            for (const [hash, value] of Object.entries(payload.items ?? {}))
            {
                mergedItems.set(hash, value);
            }
        }

        return {
            status: 'available',
            entries: new Map(groups.map(group =>
            {
                const cachedPayload = mergedItems.get(group.hash) ?? null;
                return [
                    group.hash,
                    cachedPayload ? this.usablePayload(group, cachedPayload) : null,
                ];
            })),
        };
    }

    usablePayload(group: GroupCacheMeta, markerPayload: string): string | null
    {
        return this._isCompletePayload(group, markerPayload) ? markerPayload : null;
    }

    _edgeLookupUrls(groups: GroupCacheMeta[]): string[]
    {
        const hashes = [...new Set(groups.map(group => group.hash))].sort();
        const urls: string[] = [];
        let chunk: string[] = [];

        for (const hash of hashes)
        {
            const candidate = [...chunk, hash];
            const candidateUrl = this._edgeLookupUrl(candidate);

            if (chunk.length && (
                candidate.length > LOOKUP_BATCH_MAX_HASHES
                || candidateUrl.length > EDGE_LOOKUP_MAX_URL_LENGTH
            ))
            {
                urls.push(this._edgeLookupUrl(chunk));
                chunk = [hash];
                continue;
            }

            if (candidateUrl.length > EDGE_LOOKUP_MAX_URL_LENGTH)
            {
                throw new Error('[Translator] Cache lookup exceeds the URL budget.');
            }

            chunk = candidate;
        }

        if (chunk.length) urls.push(this._edgeLookupUrl(chunk));
        return urls;
    }

    _edgeLookupUrl(hashes: string[]): string
    {
        const url = new URL(`${this.apiBaseUrl}/v1/translation/cache/lookup`);
        url.searchParams.set('siteId', this.siteId);
        url.searchParams.set('language', this.targetLang);
        url.searchParams.set('generation', String(this.generation));
        url.searchParams.set('hydration', this.hydration ? '1' : '0');
        for (const hash of hashes) url.searchParams.append('hash', hash);
        return url.toString();
    }

    coverage(
        groups: GroupCacheMeta[],
        cachedByHash: Map<string, string | null>
    ): LanguageCacheCoverage
    {
        if (!groups.length) return 'empty';

        for (const group of groups)
        {
            if (!cachedByHash.get(group.hash)) return 'partial';
        }

        return 'full';
    }

    _lookupRequestBody(hashes: string[]): {
        siteId: string;
        language: string;
        hashes: string[];
        hydration: boolean;
    }
    {
        return {
            siteId: this.siteId,
            language: this.targetLang,
            hashes,
            hydration: this.hydration,
        };
    }

    _dedupeGroupEntries(entries: TranslatableEntry[]): TranslatableEntry[]
    {
        const seenValues = new Set<string>();

        return entries.filter((entry) =>
        {
            if (!entry.hasContent)
            {
                return false;
            }

            if (seenValues.has(entry.value)) return false;
            seenValues.add(entry.value);
            return true;
        });
    }

    _canonicalizeEntries(entries: TranslatableEntry[]): TranslatableEntry[]
    {
        return entries.map((entry, index) => new TranslatableEntry({
            id: `item_${String(index + 1).padStart(4, '0')}`,
            type: entry.type,
            node: entry.node,
            attributeName: entry.attributeName,
            value: entry.value,
        }));
    }

    _isCompletePayload(group: GroupCacheMeta, markerPayload: string): boolean
    {
        const parsed = ContextGrouping.parseTranslatedMarkers(markerPayload);
        const markerEntries = ContextGrouping.parseTranslatedMarkerEntries(markerPayload);

        if (!markerEntries.length)
        {
            return false;
        }

        if (!markerEntries.every(entry => entry.value.trim().length > 0))
        {
            return false;
        }

        const markerIds = markerEntries.map(entry => entry.id);
        const hasDuplicates = new Set(markerIds).size !== markerIds.length;
        if (hasDuplicates)
        {
            return false;
        }

        const hasAnyIdMatch = group.canonicalEntries.some(entry =>
        {
            const translated = parsed.get(entry.id);
            return typeof translated === 'string' && translated.trim().length > 0;
        });
        if (hasAnyIdMatch)
        {
            return true;
        }

        return markerEntries.every(
            entry => /^(txt|text|attr|alt|page|session)_\d{8}$/.test(entry.id)
        );
    }

    async _buildContextHash(markerPayload: string, group: ContextGroup): Promise<string>
    {
        return computeFingerprintHash({
            markerPayload,
            sourceLang: this.sourceLang,
            targetLang: this.targetLang,
            contextKind: group.kind,
            settings: this.aiSettings,
        });
    }
}
