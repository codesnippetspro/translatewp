import { MISS_REPORT_MAX_ITEMS, postMissChunkWithBackoff, toMissReportItems } from '@/shared/lib/translations/missReportBackoff';
import type { TranslationCachePlan } from '@/shared/lib/translations/TranslationCacheClient';
import type { TranslationSubscription } from '@/shared/lib/translations/TranslationEventClient';

type GroupCache = TranslationCachePlan['groupsForCache'][number];
export type CoveredTranslationSubscription = TranslationSubscription & { hashes: string[] };

export type MissReportOutcome = {
    outcome: 'accepted' | 'rejected';
    subscriptions: CoveredTranslationSubscription[];
};

const MISS_COALESCE_DEBOUNCE_MS = 200;

export type MissReportCoalescerOptions = {
    fetchImpl?: typeof fetch;
    delay?: (ms: number) => Promise<void>;
    random?: () => number;
    debounceMs?: number;
};

export type MissReportRequest = {
    translationProxyBaseUrl: string;
    targetLang: string;
    remoteApiBaseUrl: string;
    pageUrl: string;
    pageTitle: string;
    groups: GroupCache[];
};

type Waiter = { hashes: string[]; resolve: (outcome: MissReportOutcome) => void; reject: (error: unknown) => void };

type PendingQueue = {
    items: Map<string, GroupCache>;
    waiters: Waiter[];
    scheduled: boolean;
    pageTitle: string;
};

/**
 * A page-level collector every chunk translator feeds: content added at
 * different points of a render each report their own misses, and this
 * merges everything reported within a short debounce window into as few
 * `/miss` calls as possible (still capped at MISS_REPORT_MAX_ITEMS each).
 * The shared network call is never tied to a single contributor's abort
 * signal; a caller that aborts while a merged call is in flight simply
 * discards the outcome once it settles.
 */
export class MissReportCoalescer
{
    readonly #fetchImpl: typeof fetch;
    readonly #delay: (ms: number) => Promise<void>;
    readonly #random: () => number;
    readonly #debounceMs: number;
    readonly #queues = new Map<string, PendingQueue>();

    constructor({ fetchImpl, delay, random, debounceMs = MISS_COALESCE_DEBOUNCE_MS }: MissReportCoalescerOptions = {})
    {
        this.#fetchImpl = fetchImpl ?? ((input, init) => globalThis.fetch(input, init));
        this.#delay = delay ?? (ms => new Promise(resolve => setTimeout(resolve, ms)));
        this.#random = random ?? Math.random;
        this.#debounceMs = debounceMs;
    }

    report({ translationProxyBaseUrl, targetLang, remoteApiBaseUrl, pageUrl, pageTitle, groups }: MissReportRequest): Promise<MissReportOutcome>
    {
        const key = `${translationProxyBaseUrl}|${targetLang}|${pageUrl}`;
        let queue = this.#queues.get(key);
        if (!queue)
        {
            queue = { items: new Map(), waiters: [], scheduled: false, pageTitle };
            this.#queues.set(key, queue);
        }
        queue.pageTitle = pageTitle;
        for (const group of groups) queue.items.set(group.hash, group);

        const activeQueue = queue;
        return new Promise<MissReportOutcome>((resolve, reject) =>
        {
            activeQueue.waiters.push({ hashes: groups.map(group => group.hash), resolve, reject });
            this.#scheduleFlush(key, translationProxyBaseUrl, remoteApiBaseUrl, targetLang, pageUrl);
        });
    }

    #scheduleFlush(key: string, translationProxyBaseUrl: string, remoteApiBaseUrl: string, targetLang: string, pageUrl: string): void
    {
        const queue = this.#queues.get(key);
        if (!queue || queue.scheduled) return;
        queue.scheduled = true;

        void this.#delay(this.#debounceMs).then(() =>
        {
            queue.scheduled = false;
            void this.#flush(key, translationProxyBaseUrl, remoteApiBaseUrl, targetLang, pageUrl);
        });
    }

    async #flush(key: string, translationProxyBaseUrl: string, remoteApiBaseUrl: string, targetLang: string, pageUrl: string): Promise<void>
    {
        const queue = this.#queues.get(key);
        if (!queue) return;

        const items = Array.from(queue.items.values());
        const waiters = queue.waiters;
        const pageTitle = queue.pageTitle;
        queue.items = new Map();
        queue.waiters = [];
        if (!items.length) return;

        const outcomeByHash = new Map<string, 'accepted' | 'rejected'>();
        const subscriptionByHash = new Map<string, CoveredTranslationSubscription>();

        try
        {
            for (let offset = 0; offset < items.length; offset += MISS_REPORT_MAX_ITEMS)
            {
                const chunk = items.slice(offset, offset + MISS_REPORT_MAX_ITEMS);
                const outcome = await postMissChunkWithBackoff({
                    post: () => this.#fetchImpl(`${translationProxyBaseUrl}/miss`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ language: targetLang, url: pageUrl, pageTitle, items: toMissReportItems(chunk) }),
                    }),
                    throwIfAborted: () => {},
                    delay: this.#delay,
                    random: this.#random,
                    subscriptionOrigin: remoteApiBaseUrl,
                });
                const subscription = outcome.subscription
                    ? { ...outcome.subscription, hashes: chunk.map(group => group.hash) }
                    : undefined;
                for (const groupCache of chunk)
                {
                    outcomeByHash.set(groupCache.hash, outcome.outcome);
                    if (subscription) subscriptionByHash.set(groupCache.hash, subscription);
                }
            }
        }
        catch (error)
        {
            for (const waiter of waiters) waiter.reject(error);
            return;
        }

        for (const waiter of waiters)
        {
            const allAccepted = waiter.hashes.every(hash => outcomeByHash.get(hash) === 'accepted');
            const subscriptions = allAccepted
                ? [...new Set(waiter.hashes.map(hash => subscriptionByHash.get(hash)).filter((value): value is CoveredTranslationSubscription => Boolean(value)))]
                : [];
            waiter.resolve({ outcome: allAccepted ? 'accepted' : 'rejected', subscriptions });
        }
    }
}

export default MissReportCoalescer;
