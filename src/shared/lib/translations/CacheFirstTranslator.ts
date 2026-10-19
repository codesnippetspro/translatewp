import { orderByViewport } from '@/shared/lib/translations/viewportOrder';
import { CONFIRMED_SAME_PREFIX, HydrationStore } from '@/shared/lib/translations/hydrationStore';
import type {
    CoveredTranslationSubscription,
    MissReportCoalescer,
    MissReportOutcome,
} from '@/shared/lib/translations/MissReportCoalescer';
import {
    MISS_REPORT_MAX_ITEMS,
    postMissChunkWithBackoff,
    toMissReportItems,
} from '@/shared/lib/translations/missReportBackoff';
import { canonicalizePageUrl } from '@/shared/lib/translations/pageUrl';
import type { TranslationCacheClient, TranslationCachePlan } from '@/shared/lib/translations/TranslationCacheClient';
import { TranslationEventClient } from '@/shared/lib/translations/TranslationEventClient';
import {
    Translator,
    type TranslateBatchOptions,
    type TranslationGroupFailure,
} from '@/shared/lib/translations/Translator';

const MISS_POLL_FIRST_DELAY_MS = 1000;
const MISS_POLL_MAX_INTERVAL_MS = 2000;
// A group the service has not served by then is left pending for a later
// visit; the visitor keeps the source text and the page stops asking.
const MISS_POLL_WINDOW_MS = 60_000;

type GroupCache = TranslationCachePlan['groupsForCache'][number];

export type CacheFirstTranslatorOptions = {
    sourceLang?: string;
    targetLang?: string;
    store?: HydrationStore;
    fetchImpl?: typeof fetch;
    delay?: (ms: number) => Promise<void>;
    random?: () => number;
    missCoalescer?: MissReportCoalescer;
    eventClient?: TranslationEventClient;
};

export class CacheFirstTranslator extends Translator
{
    readonly store: HydrationStore;
    readonly fetchImpl: typeof fetch;
    readonly delay: (ms: number) => Promise<void>;
    readonly random: () => number;
    readonly missCoalescer?: MissReportCoalescer;
    readonly eventClient: TranslationEventClient;

    constructor({ sourceLang, targetLang, store, fetchImpl, delay, random, missCoalescer, eventClient }: CacheFirstTranslatorOptions = {})
    {
        super({ sourceLang, targetLang });
        this.store = store ?? new HydrationStore();
        this.fetchImpl = fetchImpl ?? ((input, init) => globalThis.fetch(input, init));
        this.delay = delay ?? (ms => new Promise(resolve => setTimeout(resolve, ms)));
        this.random = random ?? Math.random;
        this.missCoalescer = missCoalescer;
        this.eventClient = eventClient ?? new TranslationEventClient();
    }

    async _translateContextGroups(
        plan: TranslationCachePlan,
        cacheClient: TranslationCacheClient,
        options: TranslateBatchOptions = {}
    ): Promise<{ translatedById: Map<string, string>; failures: TranslationGroupFailure[] }>
    {
        const { groups } = plan;
        if (!groups.length)
        {
            return { translatedById: new Map<string, string>(), failures: [] };
        }
        // Visible groups first, top to bottom, then by distance from
        // the viewport; every later stage keeps this order.
        const { ordered: groupsForCache, visibleCount } = orderByViewport(plan.groupsForCache);

        const signal = options.signal;
        const throwIfAborted = (): void =>
        {
            if (signal?.aborted) throw new DOMException('[Translator] Translation aborted.', 'AbortError');
        };
        const emitOptions: TranslateBatchOptions = {
            ...options,
            onEntryTranslated: (entry, served) =>
            {
                if (signal?.aborted) return;
                options.onEntryTranslated?.(entry, served);
            },
            onGroupServeStarted: (entries) =>
            {
                if (signal?.aborted) return;
                options.onGroupServeStarted?.(entries);
            },
            onGroupServeFailed: (entries) =>
            {
                if (signal?.aborted) return;
                options.onGroupServeFailed?.(entries);
            },
        };

        const translatedById = new Map<string, string>();
        const applied = new Set<string>();
        const apply = (groupCache: GroupCache, payload: string): void =>
        {
            applied.add(groupCache.hash);
            this._applyGroupTranslation(groupCache, payload, translatedById, emitOptions);
        };

        this.store.recordLanguageUse(this.targetLang);

        // 1. Local hydration store ; zero network.
        const localHits = emitOptions.preferRemote
            ? new Map<string, string>()
            : await this.store.getMany(groupsForCache.map(group => group.hash), this.targetLang);
        for (const groupCache of groupsForCache)
        {
            const payload = localHits.get(groupCache.hash);
            if (payload === undefined) continue;
            // A confirmed same-as-source answer is a served translation: the
            // source text is the answer, so it is applied and never re-asked.
            // A bare entry identical to the source predates the marker; it is
            // looked up again and the answer is stored with the marker.
            const confirmedSame = payload === `${CONFIRMED_SAME_PREFIX}${groupCache.markerPayload}`;
            if (confirmedSame) apply(groupCache, groupCache.markerPayload);
            else if (payload !== groupCache.markerPayload) apply(groupCache, payload);
        }

        // 2. Remote cache lookup for the rest. The hit-local beacon fires
        //    ONLY on a fully local serve ; a partial batch runs a lookup,
        //    which already counts demand once (no double-count).
        let remaining = groupsForCache.filter(group => !applied.has(group.hash));
        if (applied.size > 0 && remaining.length === 0) this.#sendHitLocalBeacon(cacheClient);
        if (remaining.length)
        {
            throwIfAborted();
            emitOptions.onGroupServeStarted?.(remaining.flatMap(group => group.group.entries));
            // The visible groups go in their own first lookup so their
            // skeletons clear before the rest of the page is applied.
            const visibleSet = new Set(groupsForCache.slice(0, visibleCount).map(group => group.hash));
            const visible = remaining.filter(group => visibleSet.has(group.hash));
            const rest = remaining.filter(group => !visibleSet.has(group.hash));
            const unresolved: GroupCache[] = [];
            // The visible and rest lookups are issued together; the visible
            // batch is applied the moment it answers, before the rest does.
            const visibleLookup = visible.length ? this.#lookupAndApply(cacheClient, visible, apply) : Promise.resolve([] as GroupCache[]);
            const restLookup = rest.length ? this.#lookupAndApply(cacheClient, rest, apply) : Promise.resolve([] as GroupCache[]);
            unresolved.push(...await visibleLookup);
            if (rest.length)
            {
                throwIfAborted();
                unresolved.push(...await restLookup);
            }
            remaining = unresolved;
        }

        // The miss proxy recomputes fingerprints, so client hashes never enter
        // the mutable request. Accepted work is delivered by SSE when possible.
        const failures: TranslationGroupFailure[] = [];
        emitOptions.onServePath?.(remaining.length ? 'cold' : 'ready');
        if (remaining.length)
        {
            throwIfAborted();
            const report = await this.#reportMisses(remaining, signal, throwIfAborted);
            const terminalFailures: GroupCache[] = [];
            let pending: GroupCache[] = [];
            if (report.outcome === 'accepted')
            {
                remaining = await this.#deliverSubscriptions(
                    cacheClient,
                    remaining,
                    report.subscriptions,
                    apply,
                    terminalFailures,
                    signal,
                );
                if (remaining.length)
                {
                    pending = await this.#pollUntilServed(cacheClient, remaining, apply, throwIfAborted);
                    remaining = [];
                }
            }
            for (const groupCache of terminalFailures)
            {
                failures.push({ groupId: groupCache.group.id, error: 'translation_failed' });
            }
            for (const groupCache of pending)
            {
                failures.push({ groupId: groupCache.group.id, error: 'translation_pending' });
            }
            const failureError = 'miss_report_rejected';
            for (const groupCache of remaining)
            {
                failures.push({ groupId: groupCache.group.id, error: failureError });
            }
            remaining = [...remaining, ...pending];
            emitOptions.onGroupServeFailed?.([...terminalFailures, ...remaining].flatMap(group => group.group.entries));
        }

        return { translatedById, failures };
    }

    async #lookupAndApply(
        cacheClient: TranslationCacheClient,
        groups: GroupCache[],
        apply: (groupCache: GroupCache, payload: string) => void,
        transport: 'edge' | 'origin' = 'edge',
    ): Promise<GroupCache[]>
    {
        try
        {
            const lookup = transport === 'origin'
                ? await cacheClient.lookupOrigin(groups)
                : await cacheClient.lookup(groups);
            if (lookup.status !== 'available') return groups;

            const stored = new Map<string, string>();
            const missing: GroupCache[] = [];
            for (const groupCache of groups)
            {
                const payload = lookup.entries.get(groupCache.hash);
                if (payload)
                {
                    apply(groupCache, payload);
                    stored.set(groupCache.hash, groupCache.markerPayload === payload ? `${CONFIRMED_SAME_PREFIX}${payload}` : payload);
                }
                else
                {
                    missing.push(groupCache);
                }
            }
            void this.store.putMany(stored, this.targetLang);
            return missing;
        }
        catch (error)
        {
            if ((error as Error)?.name === 'AbortError') throw error;
            console.warn('[TranslateWP] Cache lookup failed; will retry via miss path.', error);
            return groups;
        }
    }

    async #reportMisses(groups: GroupCache[], signal: AbortSignal | undefined, throwIfAborted: () => void): Promise<MissReportOutcome>
    {
        const pageUrl = canonicalizePageUrl(globalThis.location?.href ?? '');
        const pageTitle = globalThis.document?.title ?? '';
        if (this.missCoalescer)
        {
            const outcome = await this.missCoalescer.report({
                translationProxyBaseUrl: this._translationProxyBaseUrl(),
                remoteApiBaseUrl: this._remoteApiBaseUrl(),
                targetLang: this.targetLang,
                pageUrl,
                pageTitle,
                groups,
            });
            throwIfAborted();
            return outcome;
        }

        const post = (items: GroupCache[]): Promise<Response> => this.fetchImpl(`${this._translationProxyBaseUrl()}/miss`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            signal,
            body: JSON.stringify({ language: this.targetLang, url: pageUrl, pageTitle, items: toMissReportItems(items) }),
        });

        try
        {
            const subscriptions: CoveredTranslationSubscription[] = [];
            for (let offset = 0; offset < groups.length; offset += MISS_REPORT_MAX_ITEMS)
            {
                const items = groups.slice(offset, offset + MISS_REPORT_MAX_ITEMS);
                const result = await postMissChunkWithBackoff({
                    post: () => post(items),
                    signal,
                    throwIfAborted,
                    delay: this.delay,
                    random: this.random,
                    subscriptionOrigin: this._remoteApiBaseUrl(),
                });
                if (result.outcome === 'rejected') return { outcome: 'rejected', subscriptions: [] };
                if (result.subscription)
                {
                    subscriptions.push({ ...result.subscription, hashes: items.map(item => item.hash) });
                }
            }
            return { outcome: 'accepted', subscriptions };
        }
        catch (error)
        {
            if ((error as Error)?.name === 'AbortError') throw error;
            console.warn('[TranslateWP] Miss report failed; translations arrive on a later visit.', error);
            return { outcome: 'rejected', subscriptions: [] };
        }
    }

    async #deliverSubscriptions(
        cacheClient: TranslationCacheClient,
        groups: GroupCache[],
        subscriptions: CoveredTranslationSubscription[],
        apply: (groupCache: GroupCache, payload: string) => void,
        terminalFailures: GroupCache[],
        signal?: AbortSignal,
    ): Promise<GroupCache[]>
    {
        const remaining = new Map(groups.map(group => [group.hash, group]));

        await Promise.all(subscriptions.map(async (subscription) =>
        {
            const hashes = subscription.hashes.filter(hash => remaining.has(hash));
            if (!hashes.length) return;
            const stored = new Map<string, string>();
            await this.eventClient.deliver({
                subscription,
                hashes,
                covered: subscription.hashes,
                signal,
                onReady: (hash, markerPayload) =>
                {
                    const group = remaining.get(hash);
                    if (!group) return;
                    const payload = cacheClient.usablePayload(group, markerPayload);
                    if (!payload) return;
                    apply(group, payload);
                    stored.set(hash, group.markerPayload === payload ? `${CONFIRMED_SAME_PREFIX}${payload}` : payload);
                    remaining.delete(hash);
                },
                onFailed: (hash) =>
                {
                    const group = remaining.get(hash);
                    if (!group) return;
                    terminalFailures.push(group);
                    remaining.delete(hash);
                },
            });
            await this.store.putMany(stored, this.targetLang);
        }));

        return [...remaining.values()];
    }

    async #pollUntilServed(
        cacheClient: TranslationCacheClient,
        groups: GroupCache[],
        apply: (groupCache: GroupCache, payload: string) => void,
        throwIfAborted: () => void,
    ): Promise<GroupCache[]>
    {
        let remaining = groups;
        let delayMs = MISS_POLL_FIRST_DELAY_MS;
        let waited = 0;

        while (remaining.length && waited < MISS_POLL_WINDOW_MS)
        {
            await this.delay(delayMs);
            waited += delayMs;
            throwIfAborted();
            remaining = await this.#lookupAndApply(cacheClient, remaining, apply, 'origin');
            delayMs = MISS_POLL_MAX_INTERVAL_MS;
        }

        return remaining;
    }

    // Locally served pages still count; fire-and-forget beacon
    // keeps language demand truthful without a lookup round-trip.
    #sendHitLocalBeacon(cacheClient: TranslationCacheClient): void
    {
        try
        {
            void this.fetchImpl(`${cacheClient.apiBaseUrl}/v1/translation/cache/hit-local`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                keepalive: true,
                body: JSON.stringify({ siteId: cacheClient.siteId, language: this.targetLang }),
            }).catch(() => undefined);
        }
        catch
        {
            // Beacon is best-effort only.
        }
    }
}

export default CacheFirstTranslator;
