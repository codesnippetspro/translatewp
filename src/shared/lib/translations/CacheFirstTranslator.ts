import { orderByViewport } from '@/shared/lib/translations/viewportOrder';
import { runTranslationTask, TRANSLATION_IO_TIMEOUT_MS } from '@/shared/lib/translations/runTranslationTask';
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

type CacheLookupTask = {
    cacheClient: TranslationCacheClient;
    groups: GroupCache[];
    apply: (groupCache: GroupCache, payload: string) => void;
    transport?: 'edge' | 'origin';
    signal?: AbortSignal;
};

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
            const visibleLookup = visible.length
                ? this.#lookupAndApply({ cacheClient, groups: visible, apply, signal }) : Promise.resolve([] as GroupCache[]);
            const restLookup = rest.length
                ? this.#lookupAndApply({ cacheClient, groups: rest, apply, signal }) : Promise.resolve([] as GroupCache[]);
            const [visibleMissing, restMissing] = await Promise.all([visibleLookup, restLookup]);
            throwIfAborted();
            unresolved.push(...visibleMissing, ...restMissing);
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
            const accepted = report.outcome === 'accepted'
                ? remaining.filter(group => !report.acceptedHashes || report.acceptedHashes.includes(group.hash)) : [];
            const rejected = remaining.filter(group => !accepted.includes(group));
            if (accepted.length)
            {
                remaining = await this.#deliverSubscriptions({
                    cacheClient,
                    groups: accepted,
                    subscriptions: report.subscriptions,
                    apply,
                    terminalFailures,
                    signal,
                    onGroupServeFailed: emitOptions.onGroupServeFailed,
                });
                if (remaining.length)
                {
                    pending = await this.#pollUntilServed({ cacheClient, groups: remaining, apply, throwIfAborted, signal });
                }
            }
            remaining = rejected;
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

    async #lookupAndApply({ cacheClient, groups, apply, transport = 'edge', signal }: CacheLookupTask): Promise<GroupCache[]>
    {
        try
        {
            const lookup = await runTranslationTask({
                signal,
                timeoutMs: TRANSLATION_IO_TIMEOUT_MS,
                run: requestSignal => transport === 'origin'
                    ? cacheClient.lookupOrigin(groups, requestSignal) : cacheClient.lookup(groups, requestSignal),
            });
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

        const post = (items: GroupCache[], requestSignal?: AbortSignal): Promise<Response> => this.fetchImpl(`${this._translationProxyBaseUrl()}/miss`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            signal: requestSignal,
            body: JSON.stringify({ language: this.targetLang, url: pageUrl, pageTitle, items: toMissReportItems(items) }),
        });

        const subscriptions: CoveredTranslationSubscription[] = [];
        const acceptedHashes: string[] = [];
        try
        {
            for (let offset = 0; offset < groups.length; offset += MISS_REPORT_MAX_ITEMS)
            {
                const items = groups.slice(offset, offset + MISS_REPORT_MAX_ITEMS);
                const result = await postMissChunkWithBackoff({
                    hashes: items.map(item => item.hash),
                    post: (requestSignal, hashes) => post(hashes ? items.filter(item => hashes.includes(item.hash)) : items, requestSignal),
                    signal,
                    throwIfAborted,
                    delay: this.delay,
                    random: this.random,
                    subscriptionOrigin: this._remoteApiBaseUrl(),
                });
                if (result.outcome === 'rejected') continue;
                const admitted = result.acceptedHashes ?? items.map(item => item.hash);
                acceptedHashes.push(...admitted);
                subscriptions.push(...(result.subscriptions ?? (result.subscription
                    ? [{ ...result.subscription, hashes: admitted }] : [])));
            }
            return { outcome: acceptedHashes.length ? 'accepted' : 'rejected', acceptedHashes, subscriptions };
        }
        catch (error)
        {
            if ((error as Error)?.name === 'AbortError') throw error;
            console.warn('[TranslateWP] Miss report failed; translations arrive on a later visit.', error);
            return { outcome: acceptedHashes.length ? 'accepted' : 'rejected', acceptedHashes, subscriptions };
        }
    }

    async #deliverSubscriptions({ cacheClient, groups, subscriptions, apply, terminalFailures, signal, onGroupServeFailed }: {
        cacheClient: TranslationCacheClient;
        groups: GroupCache[];
        subscriptions: CoveredTranslationSubscription[];
        apply: (groupCache: GroupCache, payload: string) => void;
        terminalFailures: GroupCache[];
        signal?: AbortSignal;
        onGroupServeFailed?: TranslateBatchOptions['onGroupServeFailed'];
    }): Promise<GroupCache[]>
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
                    onGroupServeFailed?.(group.group.entries);
                },
            });
            await this.store.putMany(stored, this.targetLang);
        }));

        return [...remaining.values()];
    }

    async #pollUntilServed({ cacheClient, groups, apply, throwIfAborted, signal }: CacheLookupTask & {
        throwIfAborted: () => void;
    }): Promise<GroupCache[]>
    {
        let remaining = groups;
        let delayMs = MISS_POLL_FIRST_DELAY_MS;
        let waited = 0;

        while (remaining.length && waited < MISS_POLL_WINDOW_MS)
        {
            await runTranslationTask({ run: () => this.delay(delayMs), signal, timeoutMs: TRANSLATION_IO_TIMEOUT_MS });
            waited += delayMs;
            throwIfAborted();
            remaining = await this.#lookupAndApply({ cacheClient, groups: remaining, apply, transport: 'origin', signal });
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
