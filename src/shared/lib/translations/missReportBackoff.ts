import { runTranslationTask, TRANSLATION_IO_TIMEOUT_MS } from '@/shared/lib/translations/runTranslationTask';
import type { TranslationCachePlan } from '@/shared/lib/translations/TranslationCacheClient';
import type { CoveredTranslationSubscription } from '@/shared/lib/translations/MissReportCoalescer';
import type { TranslationSubscription } from '@/shared/lib/translations/TranslationEventClient';

type MissReportGroup = TranslationCachePlan['groupsForCache'][number];

export const MISS_REPORT_MAX_ITEMS = 40;
export const MISS_REPORT_RETRY_WINDOW_MS = 90_000;

const MISS_REPORT_RETRY_STATUS = 429;
const MISS_REPORT_MIN_RETRY_DELAY_MS = 1000;
const MISS_REPORT_FATAL_STATUSES = new Set([400, 409]);
const MISS_REPORT_DEFAULT_RETRY_DELAY_MS = 3000;
const MISS_REPORT_RETRY_JITTER_MS = 250;
const MISS_REPORT_SERVER_ERROR_MAX_RETRIES = 2;
const MISS_REPORT_SERVER_ERROR_BASE_DELAY_MS = 1000;

export type MissReportResult = {
    outcome: 'accepted' | 'rejected';
    subscription?: TranslationSubscription;
    acceptedHashes?: string[];
    subscriptions?: CoveredTranslationSubscription[];
    retryHashes?: string[];
    retryAfter?: number;
};

export type MissReportBackoffDeps = {
    post: (signal?: AbortSignal, hashes?: string[]) => Promise<Response>;
    hashes?: string[];
    signal?: AbortSignal;
    throwIfAborted: () => void;
    delay: (ms: number) => Promise<void>;
    random?: () => number;
    subscriptionOrigin: string;
};

/**
 * Posts one miss-report chunk (at most {@link MISS_REPORT_MAX_ITEMS} items),
 * retrying on a rate limit for as long as the page's poll window
 * (MISS_REPORT_RETRY_WINDOW_MS) has budget left, and retrying a server error
 * twice with backoff. A structurally invalid request (400/409) never retries.
 */
export async function postMissChunkWithBackoff({
    post,
    hashes,
    signal,
    throwIfAborted,
    delay,
    random = Math.random,
    subscriptionOrigin,
}: MissReportBackoffDeps): Promise<MissReportResult>
{
    const deadline = Date.now() + MISS_REPORT_RETRY_WINDOW_MS;
    let remainingBudgetMs = MISS_REPORT_RETRY_WINDOW_MS;
    let serverRetries = 0;
    let pendingHashes = hashes;
    const acceptedHashes = new Set<string>();
    const subscriptions: CoveredTranslationSubscription[] = [];
    const finish = (): MissReportResult => acceptedHashes.size
        ? { outcome: 'accepted', acceptedHashes: [...acceptedHashes], subscriptions }
        : { outcome: 'rejected' };
    while (remainingBudgetMs > 0)
    {
        throwIfAborted();
        remainingBudgetMs = Math.min(remainingBudgetMs, deadline - Date.now());
        if (remainingBudgetMs <= 0) break;
        let response: Response;
        let result: MissReportResult | undefined;
        try
        {
            ({ response, result } = await runTranslationTask({
                signal,
                timeoutMs: Math.min(TRANSLATION_IO_TIMEOUT_MS, remainingBudgetMs),
                run: async requestSignal =>
                {
                    const response = await post(requestSignal, pendingHashes);
                    const result = response.ok ? await acceptedResult(response, subscriptionOrigin) : undefined;
                    return { response, result };
                },
            }));
        }
        catch (error)
        {
            if ((error as Error)?.name === 'AbortError' || !acceptedHashes.size) throw error;
            return finish();
        }
        if (result && !result.retryHashes)
        {
            if (!acceptedHashes.size) return result;
            if (result.outcome === 'accepted')
            {
                for (const hash of pendingHashes ?? []) acceptedHashes.add(hash);
                if (result.subscription) subscriptions.push({ ...result.subscription, hashes: pendingHashes ?? [] });
            }
            return finish();
        }
        if (result?.retryHashes)
        {
            const accepted = result.acceptedHashes ?? [];
            const returned = [...accepted, ...result.retryHashes];
            if (!pendingHashes || new Set(returned).size !== returned.length
                || returned.some(hash => !pendingHashes!.includes(hash))) return finish();
            for (const hash of accepted) acceptedHashes.add(hash);
            if (result.subscription) subscriptions.push({ ...result.subscription, hashes: accepted });
            pendingHashes = result.retryHashes;
            if (!pendingHashes.length) return finish();
        }
        if (MISS_REPORT_FATAL_STATUSES.has(response.status))
        {
            console.warn(`[TranslateWP] Miss report rejected (${response.status}); original text kept.`);
            return finish();
        }

        let baseDelayMs: number;
        if (result?.retryHashes || response.status === MISS_REPORT_RETRY_STATUS)
        {
            const retryAfter = result?.retryAfter ? String(result.retryAfter) : response.headers.get('Retry-After');
            const seconds = Number(retryAfter);
            const date = retryAfter ? Date.parse(retryAfter) : NaN;
            baseDelayMs = Number.isFinite(seconds) && seconds > 0
                ? seconds * 1000
                : Number.isFinite(date) ? Math.max(0, date - Date.now()) : MISS_REPORT_DEFAULT_RETRY_DELAY_MS;
            baseDelayMs = Math.max(MISS_REPORT_MIN_RETRY_DELAY_MS, baseDelayMs);
        }
        else if (response.status >= 500 && serverRetries < MISS_REPORT_SERVER_ERROR_MAX_RETRIES)
        {
            serverRetries += 1;
            baseDelayMs = MISS_REPORT_SERVER_ERROR_BASE_DELAY_MS * serverRetries;
        }
        else
        {
            console.warn(`[TranslateWP] Miss report failed (${response.status}); original text kept.`);
            return finish();
        }

        remainingBudgetMs = Math.min(remainingBudgetMs, deadline - Date.now());
        const waitMs = baseDelayMs + random() * MISS_REPORT_RETRY_JITTER_MS;
        if (waitMs >= remainingBudgetMs) break;
        try
        {
            await runTranslationTask({
                signal,
                timeoutMs: Math.min(waitMs + TRANSLATION_IO_TIMEOUT_MS, remainingBudgetMs),
                run: () => delay(waitMs),
            });
        }
        catch (error)
        {
            if ((error as Error)?.name === 'AbortError' || !acceptedHashes.size) throw error;
            return finish();
        }
        remainingBudgetMs -= waitMs;
    }
    console.warn('[TranslateWP] Miss report could not retry within the available window; original text kept.');
    return finish();
}

async function acceptedResult(response: Response, subscriptionOrigin: string): Promise<MissReportResult>
{
    try
    {
        const body = await response.json() as { skipped?: number; subscription?: unknown; acceptedHashes?: unknown; retryHashes?: unknown; retryAfter?: unknown };
        if (typeof body?.skipped === 'number' && body.skipped > 0)
        {
            console.warn(`[TranslateWP] Miss report skipped ${body.skipped} invalid item(s); the rest were accepted.`);
        }
        const subscription = sanitizeTranslationSubscription(body?.subscription, subscriptionOrigin);
        const result: MissReportResult = subscription ? { outcome: 'accepted', subscription } : { outcome: 'accepted' };
        if (body?.retryHashes !== undefined)
        {
            const isHashes = (value: unknown): value is string[] => Array.isArray(value)
                && value.length <= MISS_REPORT_MAX_ITEMS
                && value.every(hash => typeof hash === 'string' && hash.length > 0 && hash.length <= 128);
            if (!isHashes(body.retryHashes) || !isHashes(body.acceptedHashes)
                || typeof body.retryAfter !== 'number' || !Number.isFinite(body.retryAfter)
                || body.retryAfter <= 0) return { outcome: 'rejected' };
            return { ...result, acceptedHashes: body.acceptedHashes, retryHashes: body.retryHashes, retryAfter: body.retryAfter };
        }
        return result;
    }
    catch
    {
        return { outcome: 'accepted' };
    }
}

export function sanitizeTranslationSubscription(
    value: unknown,
    apiBaseUrl: string,
    now = Date.now()
): TranslationSubscription | null
{
    if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
    const candidate = value as Record<string, unknown>;
    if (typeof candidate.url !== 'string' || typeof candidate.expiresAt !== 'string') return null;

    try
    {
        const url = new URL(candidate.url);
        const base = new URL(apiBaseUrl);
        const expiry = Date.parse(candidate.expiresAt);
        const queryKeys = [...url.searchParams.keys()];
        if (
            url.protocol !== 'https:'
            || url.origin !== base.origin
            || url.pathname !== '/v1/translation/cache/events'
            || url.username !== ''
            || url.password !== ''
            || url.hash !== ''
            || queryKeys.length !== 1
            || queryKeys[0] !== 'token'
            || url.searchParams.getAll('token').length !== 1
            || !url.searchParams.get('token')
            || candidate.url.length > 20_000
            || !Number.isFinite(expiry)
            || expiry <= now
            || expiry > now + (10 * 60 * 1000)
        ) return null;

        return { url: url.toString(), expiresAt: candidate.expiresAt };
    }
    catch
    {
        return null;
    }
}

export function toMissReportItems(groups: MissReportGroup[])
{
    return groups.map(groupCache => ({
        groupId: groupCache.group.id,
        markerPayload: groupCache.markerPayload,
        contextKey: groupCache.group.contextKey,
        contextKind: groupCache.group.kind,
        ...(groupCache.group.neighbours ? { neighbours: groupCache.group.neighbours } : {}),
    }));
}
