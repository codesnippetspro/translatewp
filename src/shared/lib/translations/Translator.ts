import type { ServedTimingEvent } from '@/shared/lib/translations/ServedTranslationTiming';
import { EntryType } from '@/shared/lib/translations/constants';
import { aiSettingsForLanguage } from '@/shared/lib/translations/aiSettingsForLanguage';
import { resolveCatalogLocale } from '@/shared/lib/languages/catalogLocale';
import { getLanguageNameFromLocale } from '@/shared/lib/languages/languageName';
import { ContextGroup, ContextGrouping } from '@/shared/lib/translations/ContextGrouping';
import { canonicalizePageUrl } from '@/shared/lib/translations/pageUrl';
import { computeFingerprintHash } from '@/shared/lib/translations/fingerprintPayload';
import {
    cacheGenerationForLanguage,
    TranslationCacheClient,
    type TranslationCachePlan,
} from '@/shared/lib/translations/TranslationCacheClient';
import {
    chunkGroupsForPushBatch,
    estimatePushBatchItemBytes,
    TRANSLATION_PUSH_BATCH_MAX_MARKER_PAYLOAD_BYTES,
} from '@/shared/lib/translations/pushBatchLimits';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';
import segmentRegistry from '@/shared/lib/translations/segmentRegistry';

const JOB_STATUS_POLL_INTERVAL_MS = 1000;
const JOB_STATUS_POLL_MAX_INTERVAL_MS = 2000;
const JOB_STATUS_TIMEOUT_MS = 120_000;

type CreateJobResponse = {
    jobId: string;
    sourceLang: string;
    sourceLanguageName?: string;
    targetLang: string;
    targetLanguageName?: string;
};

type TranslationSseEvent = {
    seq?: number;
    type: string;
    jobId: string;
    batchId?: string;
    groupId?: string;
    markerPayload?: string;
    error?: string;
    status?: number;
    totalTokens?: number;
    promptTokens?: number;
    completionTokens?: number;
    itemsTranslated?: number;
    itemsFailed?: number;
    cacheWriteFailures?: number;
    modelUsage?: { provider: string; modelId: string; tokens: number }[];
};

export type TranslateBatchOptions = {
    onServePath?: (path: 'ready' | 'cold') => void;
    onEntryTranslated?: (entry: TranslatableEntry, served?: boolean) => void;
    onGroupServeStarted?: (entries: TranslatableEntry[]) => void;
    onGroupServeFailed?: (entries: TranslatableEntry[]) => void;
    signal?: AbortSignal;
    /** Skip the local hydration store and ask the service directly (single, cheap lookups such as the page title). */
    preferRemote?: boolean;
};

export type TranslationGroupFailure = {
    groupId: string;
    error: string;
    status?: number;
};

export type TranslationBatchResult = {
    translated: TranslatableEntry[];
    failures: TranslationGroupFailure[];
    totalGroups: number;
};

/**
 * Translator, responsible for converting extracted values into their target-language equivalents
 */
type PushBatchResult =
    | { ok: true }
    | { ok: false; status: number; error: string };

// A translated marker payload arrives from a serialized/HTML context, so an
// ampersand or angle bracket that was plain text at the source can return as an
// entity (`&amp;`, `&lt;`). A text node and an attribute each hold the DECODED
// character, so writing the entity straight in shows the literal `&amp;` to the
// visitor. Decode once, on the write-back boundary, before it reaches the DOM.
function decodeHtmlEntities(value: string): string
{
    if (!value.includes('&')) return value;

    const textarea = document.createElement('textarea');
    textarea.innerHTML = value;
    return textarea.value;
}

function toPushBatchItem(groupCache: TranslationCachePlan['groupsForCache'][number])
{
    return {
        groupId: groupCache.group.id,
        markerPayload: groupCache.markerPayload,
        contextKey: groupCache.group.contextKey,
        contextKind: groupCache.group.kind,
        ...(groupCache.group.neighbours ? { neighbours: groupCache.group.neighbours } : {}),
        cache: {
            siteId: groupCache.siteId,
            hash: groupCache.hash,
        },
    };
}

export class Translator
{
    sourceLang: string;
    targetLang: string;

    /**
     * @param {object} [options]
     * @param {string} [options.sourceLang='auto']  Source Language tag
     * @param {string} [options.targetLang='en']    Target Language tag
     */
    constructor({
        sourceLang = 'auto',
        targetLang = 'en',
    }: {
        sourceLang?: string,
        targetLang?: string,
    } = {})
    {
        this.sourceLang = sourceLang;
        this.targetLang = targetLang;
    }

    /**
     * Translates a batch of entries, returning translated copies
     * Batching is the right primitive here; real APIs charge per character and have rate limits, so we want to group calls
     *
     * @param {TranslatableEntry[]}             entries
     * @returns {Promise<TranslatableEntry[]>}          Translated copies of all entries
     */
    async translateBatch(entries: TranslatableEntry[], options: TranslateBatchOptions = {}): Promise<TranslationBatchResult>
    {
        const cacheClient = this._createCacheClient();
        const plan = await cacheClient.prepare(entries);
        const { translatedById, failures } = await this._translateContextGroups(plan, cacheClient, options);

        return {
            translated: entries.map(entry =>
            {
                const translatedValue = translatedById.get(entry.id);
                return translatedValue === undefined ? entry.withTranslation(entry.value) : entry.withTranslation(translatedValue);
            }),
            failures,
            totalGroups: plan.groups.length,
        };
    }


    /**
     * Translate one server-only SEO string (the page title, an og:title) as a
     * single fixed-context group, reusing the same cache lookup, miss report and
     * poll path the body uses. The group is keyed by a stable SEO context key
     * instead of a DOM selector, so its fingerprint matches the server's for the
     * same string and the warm-up stores it under the same hash the crawler path
     * reads. The translated string is returned for the caller to apply to the
     * document head rather than written to the DOM here; null means nothing
     * translated yet, so the caller keeps the source title.
     */
    async translateSeoString(
        source: string,
        contextKey: string,
        options: TranslateBatchOptions = {}
    ): Promise<string | null>
    {
        const value = source.trim();
        if ('' === value)
        {
            return null;
        }

        const node = globalThis.document?.createTextNode?.(value) ?? ({ nodeType: 3 } as unknown as Node);
        const entry = new TranslatableEntry({ id: 'seo', type: EntryType.TEXT, node, attributeName: null, value });
        const canonicalEntries = [
            new TranslatableEntry({ id: 'item_0001', type: EntryType.TEXT, node, attributeName: null, value }),
        ];
        const markerPayload = ContextGrouping.serializeWithMarkers(canonicalEntries);
        const group: ContextGroup = {
            id: `seo:${contextKey}`,
            kind: 'attribute',
            contextKey,
            entries: [entry],
            markerPayload,
        };

        const cacheClient = this._createCacheClient();
        const hash = await computeFingerprintHash({
            markerPayload,
            sourceLang: this.sourceLang,
            targetLang: this.targetLang,
            contextKind: 'attribute',
            settings: cacheClient.aiSettings,
        });

        const plan: TranslationCachePlan = {
            groups: [group],
            groupsForCache: [{ group, canonicalEntries, markerPayload, hash, siteId: cacheClient.siteId }],
        };

        // The title follows the same local-first rule as every page entry:
        // a store hit under the current cache generation is authoritative.
        const { translatedById } = await this._translateContextGroups(plan, cacheClient, { ...options });
        const translated = translatedById.get('seo');

        return (typeof translated === 'string' && '' !== translated.trim() && translated !== value)
            ? translated
            : null;
    }

    /**
     * Creates a translation job directly on the external API.
     */
    async _createTranslationJob(signal?: AbortSignal): Promise<CreateJobResponse>
    {
        const response = await fetch(`${this._translationProxyBaseUrl()}/create-job`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            signal,
            body: JSON.stringify({
                sourceLang: this.sourceLang,
                sourceLanguageName: this._languageName(this.sourceLang),
                targetLang: this.targetLang,
                targetLanguageName: this._languageName(this.targetLang),
            }),
        });

        if (!response.ok)
        {
            throw new Error(`[Translator] Failed to create translation job (${response.status}).`);
        }

        return await response.json() as CreateJobResponse;
    }

    _cancelTranslationJob(jobId: string): void
    {
        void fetch(`${this._translationProxyBaseUrl()}/cancel`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            keepalive: true,
            body: JSON.stringify({ jobId }),
        }).catch(() => {});
    }

    async _pushTranslationBatch(
        jobId: string,
        chunk: TranslationCachePlan['groupsForCache'],
        batchIndex: number,
        closeInput: boolean,
        signal?: AbortSignal
    ): Promise<PushBatchResult>
    {
        try
        {
            const response = await fetch(`${this._translationProxyBaseUrl()}/push-batch`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                signal,
                body: JSON.stringify({
                    jobId,
                    batchId: `batch_${batchIndex + 1}`,
                    closeInput,
                    items: chunk.map(toPushBatchItem),
                    url: canonicalizePageUrl(window.location.href),
                }),
            });

            if (!response.ok)
            {
                let apiError = '';
                try { const body = await response.json(); apiError = (body as Record<string, unknown>)?.error ? String((body as Record<string, unknown>).error) : ''; } catch { /* body not JSON */ }
                const detail = apiError ? `: ${apiError}` : '';
                return {
                    ok: false,
                    status: response.status,
                    error: `[Translator] Failed to push translation batch (${response.status})${detail}.`,
                };
            }

            return { ok: true };
        }
        catch (error)
        {
            if ((error as Error)?.name === 'AbortError')
            {
                throw error;
            }

            return {
                ok: false,
                status: 0,
                error: `[Translator] Failed to push translation batch: ${(error as Error)?.message ?? 'unknown error'}.`,
            };
        }
    }

    async _waitForJobCompletion(jobId: string, signal?: AbortSignal): Promise<'done' | 'cancelled' | 'timeout'>
    {
        const deadline = Date.now() + JOB_STATUS_TIMEOUT_MS;
        let pollInterval = JOB_STATUS_POLL_INTERVAL_MS;

        while (Date.now() < deadline)
        {
            if (signal?.aborted)
            {
                throw new DOMException('[Translator] Translation aborted.', 'AbortError');
            }

            const status = await this._fetchJobStatus(jobId, signal);
            if ('done' === status || 'cancelled' === status)
            {
                return status;
            }

            await this._sleep(pollInterval, signal);
            pollInterval = Math.min(pollInterval * 2, JOB_STATUS_POLL_MAX_INTERVAL_MS);
        }

        return 'timeout';
    }

    async _fetchJobStatus(jobId: string, signal?: AbortSignal): Promise<string>
    {
        const response = await fetch(
            `${this._translationProxyBaseUrl()}/status?jobId=${encodeURIComponent(jobId)}`,
            {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                },
                signal,
            }
        );

        if (!response.ok)
        {
            throw new Error(`[Translator] Failed to read translation job status (${response.status}).`);
        }

        const payload = await response.json() as { state?: string };
        return typeof payload.state === 'string' ? payload.state : '';
    }

    _sleep(ms: number, signal?: AbortSignal): Promise<void>
    {
        return new Promise((resolve, reject) =>
        {
            if (signal?.aborted)
            {
                reject(new DOMException('[Translator] Translation aborted.', 'AbortError'));
                return;
            }

            const timer = window.setTimeout(() =>
            {
                signal?.removeEventListener('abort', onAbort);
                resolve();
            }, ms);

            const onAbort = (): void =>
            {
                window.clearTimeout(timer);
                reject(new DOMException('[Translator] Translation aborted.', 'AbortError'));
            };

            signal?.addEventListener('abort', onAbort, { once: true });
        });
    }

    async _translateContextGroups(
        plan: TranslationCachePlan,
        cacheClient: TranslationCacheClient,
        options: TranslateBatchOptions = {}
    ): Promise<{ translatedById: Map<string, string>; failures: TranslationGroupFailure[] }>
    {
        const { groups, groupsForCache } = plan;
        if (!groups.length)
        {
            return { translatedById: new Map<string, string>(), failures: [] };
        }

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
        };

        const translatedById = new Map<string, string>();
        const failedGroups: TranslationGroupFailure[] = [];

        let cacheEntries = new Map<string, string | null>();
        try
        {
            const lookup = await cacheClient.lookup(groupsForCache, signal);
            if (lookup.status === 'available')
            {
                cacheEntries = lookup.entries;
            }
            else
            {
                console.warn('[Translator] Cache is unavailable, falling back to direct translation.');
            }
        }
        catch (error)
        {
            if ((error as Error)?.name === 'AbortError') throw error;
            console.warn('[Translator] Cache lookup failed, falling back to direct translation.', error);
        }

        const translatedGroupIds = new Set<string>();
        for (const groupCache of groupsForCache)
        {
            const cachedPayload = cacheEntries.get(groupCache.hash);
            if (!cachedPayload) continue;

            translatedGroupIds.add(groupCache.group.id);
            this._applyGroupTranslation(groupCache, cachedPayload, translatedById, emitOptions);
        }

        const groupsForTranslation = groupsForCache.filter(group => !translatedGroupIds.has(group.group.id));
        if (groupsForTranslation.length)
        {
            throwIfAborted();

            const pushableGroups: typeof groupsForTranslation = [];
            const dedupedHashes = new Set<string>();
            for (const groupCache of groupsForTranslation)
            {
                const markerBytes = estimatePushBatchItemBytes(toPushBatchItem(groupCache));

                if (markerBytes > TRANSLATION_PUSH_BATCH_MAX_MARKER_PAYLOAD_BYTES)
                {
                    failedGroups.push({
                        groupId: groupCache.group.id,
                        error: 'Marker payload exceeds maximum size',
                    });
                    continue;
                }

                if (dedupedHashes.has(groupCache.hash)) continue;
                dedupedHashes.add(groupCache.hash);
                pushableGroups.push(groupCache);
            }

            const pushedHashes = new Set<string>();

            if (!pushableGroups.length)
            {
                if (failedGroups.length)
                {
                    console.warn(
                        `[Translator] ${failedGroups.length} translation group(s) failed; original text kept for their entries.`
                    );
                }

                return { translatedById, failures: failedGroups };
            }

            emitOptions.onGroupServeStarted?.(pushableGroups.flatMap(group => group.group.entries));

            const { jobId } = await this._createTranslationJob(signal);
            const pushedGroupIds = new Set<string>();
            let jobInputClosed = false;
            let jobWarningLogged = false;
            const logJobWarning = (message: string): void =>
            {
                if (jobWarningLogged)
                {
                    return;
                }

                jobWarningLogged = true;
                console.warn(message);
            };

            try
            {
                const batchChunks = chunkGroupsForPushBatch(
                    pushableGroups,
                    toPushBatchItem,
                    jobId,
                    batchIndex => `batch_${batchIndex + 1}`
                );

                const pushResults = await Promise.all(
                    batchChunks.map((chunk, batchIndex) =>
                        this._pushTranslationBatch(jobId, chunk, batchIndex, false, signal)
                            .then(pushResult => ({ pushResult, chunk })),
                    ),
                );

                for (const { pushResult, chunk } of pushResults)
                {
                    if (!pushResult.ok)
                    {
                        logJobWarning('[Translator] Translation batch failed; original text kept for affected entries.');
                        for (const groupCache of chunk)
                        {
                            failedGroups.push({
                                groupId: groupCache.group.id,
                                error: pushResult.error,
                                status: pushResult.status,
                            });
                        }
                        continue;
                    }

                    for (const groupCache of chunk)
                    {
                        pushedHashes.add(groupCache.hash);
                    }
                }

                for (const groupCache of groupsForTranslation)
                {
                    if (pushedHashes.has(groupCache.hash))
                    {
                        pushedGroupIds.add(groupCache.group.id);
                    }
                }

                if (pushedGroupIds.size)
                {
                    if (!jobInputClosed)
                    {
                        const closeResult = await this._pushTranslationBatch(
                            jobId,
                            [],
                            batchChunks.length,
                            true,
                            signal
                        );
                        if (closeResult.ok)
                        {
                            jobInputClosed = true;
                        }
                        else
                        {
                            this._cancelTranslationJob(jobId);
                            logJobWarning('[Translator] Translation job could not be closed; original text kept for affected entries.');
                            for (const groupCache of pushableGroups)
                            {
                                if (pushedGroupIds.has(groupCache.group.id) && !this._groupHasTranslation(groupCache, translatedById))
                                {
                                    failedGroups.push({
                                        groupId: groupCache.group.id,
                                        error: closeResult.error,
                                        status: closeResult.status,
                                    });
                                }
                            }
                        }
                    }

                    if (jobInputClosed)
                    {
                        const completionState = await this._waitForJobCompletion(jobId, signal);
                        if ('cancelled' === completionState)
                        {
                            logJobWarning('[Translator] Translation job was cancelled; original text kept for affected entries.');
                            for (const groupCache of groupsForTranslation)
                            {
                                if (pushedGroupIds.has(groupCache.group.id) && !this._groupHasTranslation(groupCache, translatedById))
                                {
                                    failedGroups.push({
                                        groupId: groupCache.group.id,
                                        error: 'Translation job was cancelled',
                                    });
                                }
                            }
                        }
                        else if ('timeout' === completionState)
                        {
                            logJobWarning('[Translator] Translation job timed out; original text kept for affected entries.');
                            for (const groupCache of groupsForTranslation)
                            {
                                if (pushedGroupIds.has(groupCache.group.id) && !this._groupHasTranslation(groupCache, translatedById))
                                {
                                    failedGroups.push({
                                        groupId: groupCache.group.id,
                                        error: 'Translation job timed out',
                                    });
                                }
                            }
                        }
                        else
                        {
                            const refreshedLookup = await cacheClient.lookup(groupsForTranslation, signal);
                            let itemsTranslated = 0;
                            if ('available' === refreshedLookup.status)
                            {
                                for (const groupCache of groupsForTranslation)
                                {
                                    if (!pushedGroupIds.has(groupCache.group.id))
                                    {
                                        continue;
                                    }

                                    const cachedPayload = refreshedLookup.entries.get(groupCache.hash);
                                    if (!cachedPayload)
                                    {
                                        failedGroups.push({
                                            groupId: groupCache.group.id,
                                            error: 'Translation result was not available after job completion',
                                        });
                                        continue;
                                    }

                                    this._applyGroupTranslation(groupCache, cachedPayload, translatedById, emitOptions);
                                    itemsTranslated++;
                                }
                            }

                            if (itemsTranslated > 0)
                            {
                            }
                        }
                    }
                }
            }
            catch (error)
            {
                if ((error as Error)?.name === 'AbortError')
                {
                    this._cancelTranslationJob(jobId);
                    emitOptions.onGroupServeFailed?.(pushableGroups.flatMap(groupCache => groupCache.group.entries));
                    throw error;
                }

                logJobWarning('[Translator] Translation job failed; original text kept for affected entries.');
                for (const groupCache of groupsForTranslation)
                {
                    if (!this._groupHasTranslation(groupCache, translatedById))
                    {
                        failedGroups.push({
                            groupId: groupCache.group.id,
                            error: (error as Error)?.message ?? 'Translation job failed',
                        });
                    }
                }
            }

            if (failedGroups.length)
            {
                const reasons = new Map<string, number>();
                for (const failure of failedGroups)
                {
                    const key = failure.error;
                    reasons.set(key, (reasons.get(key) ?? 0) + 1);
                }
                const breakdown = [...reasons.entries()].map(([error, count]) => `${count}× ${error}`).join('; ');
                console.warn(
                    `[Translator] ${failedGroups.length} translation group(s) failed; original text kept for their entries. ${breakdown}`
                );
            }

        }

        throwIfAborted();

        const appliedBeforeFallback = translatedById.size;
        let fallbackEntryCount = 0;

        for (const group of groups)
        {
            for (const entry of group.entries)
            {
                if (!translatedById.has(entry.id))
                {
                    fallbackEntryCount++;
                    const translatedValue = entry.value;
                    translatedById.set(entry.id, translatedValue);
                    emitOptions.onEntryTranslated?.(entry.withTranslation(translatedValue), false);
                }
            }
        }

        if (fallbackEntryCount > 0)
        {
            console.warn(
                `[Translator] Translation apply kept source text for ${fallbackEntryCount} entries `
                + `(found ${appliedBeforeFallback}, applied ${appliedBeforeFallback}, unmatched ${fallbackEntryCount}, failedGroups ${failedGroups.length}).`
            );
        }

        return { translatedById, failures: failedGroups };
    }

    _groupHasTranslation(
        groupCache: TranslationCachePlan['groupsForCache'][number],
        translatedById: Map<string, string>
    ): boolean
    {
        return groupCache.group.entries.some(entry => translatedById.has(entry.id));
    }

    _applyGroupTranslation(
        groupCache: TranslationCachePlan['groupsForCache'][number],
        markerPayload: string,
        translatedById: Map<string, string>,
        options: TranslateBatchOptions
    ): void
    {
        const { canonicalEntries, group } = groupCache;
        const parsed = ContextGrouping.parseTranslatedMarkers(markerPayload);
        const markerEntries = ContextGrouping.parseTranslatedMarkerEntries(markerPayload);
        const allLegacyIds = markerEntries.every(entry => /^(txt|text|attr|alt|page|session)_\d{8}$/.test(entry.id));
        const noDuplicates = markerEntries.length === new Set(markerEntries.map(e => e.id)).size;
        const countMatches = markerEntries.length === canonicalEntries.length;
        const allowPositional = countMatches && allLegacyIds && noDuplicates;
        const translatedBySourceValue = new Map<string, string>();
        let matchedById = 0;
        let matchedByPosition = 0;
        let unmatchedCanonical = 0;

        for (let index = 0; index < canonicalEntries.length; index++)
        {
            const canonicalEntry = canonicalEntries[index];
            const translatedValue = parsed.get(canonicalEntry.id);

            if (undefined !== translatedValue)
            {
                matchedById++;
                translatedBySourceValue.set(canonicalEntry.value, decodeHtmlEntities(translatedValue));
            }
            else if (allowPositional && index < markerEntries.length)
            {
                translatedBySourceValue.set(canonicalEntry.value, decodeHtmlEntities(markerEntries[index].value));
                matchedByPosition++;
            }
            else
            {
                unmatchedCanonical++;
            }
        }

        let appliedEntries = 0;
        let unmatchedEntries = 0;

        for (const entry of group.entries)
        {
            const translatedValue = translatedBySourceValue.get(entry.value);
            if (undefined === translatedValue)
            {
                unmatchedEntries++;
                translatedById.set(entry.id, entry.value);
                options.onEntryTranslated?.(entry.withTranslation(entry.value), false);
                continue;
            }

            appliedEntries++;
            translatedById.set(entry.id, translatedValue);
            options.onEntryTranslated?.(entry.withTranslation(translatedValue));
        }

        // A page for someone who edits translations keeps what it applied so
        // the front-end editor can select, correct and re-serve it. Visitors
        // skip this entirely.
        if (window.TranslateWP?.can ? window.TranslateWP.can.edit : window.TranslateWP?.canManage === true)
        {
            segmentRegistry.record({
                hash: groupCache.hash,
                language: this.targetLang,
                group,
                sourcePayload: groupCache.markerPayload,
                translatedPayload: markerPayload,
            });
        }

        if (unmatchedCanonical > 0 || unmatchedEntries > 0)
        {
            console.warn(
                `[Translator] Translation apply mismatch for group ${group.id} `
                + `(markers ${markerEntries.length}, canonical ${canonicalEntries.length}, `
                + `matchedById ${matchedById}, matchedByPosition ${matchedByPosition}, `
                + `unmatchedCanonical ${unmatchedCanonical}, applied ${appliedEntries}, unmatched ${unmatchedEntries}).`
            );
        }
    }

    reportServedTiming(event: ServedTimingEvent): void
    {
        try
        {
            void fetch(`${this._translationProxyBaseUrl()}/telemetry/served`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'omit',
                referrerPolicy: 'no-referrer',
                keepalive: true,
                body: JSON.stringify(event),
            }).catch(() => undefined);
        }
        catch {}
    }

    _languageName(locale: string): string
    {
        const catalogLocale = resolveCatalogLocale(locale) ?? locale;
        return getLanguageNameFromLocale(catalogLocale) ?? locale;
    }

    _translationProxyBaseUrl(): string
    {
        return window.TranslateWP.context.translation_base_url.replace(/\/+$/, '');
    }

    _remoteApiBaseUrl(): string
    {
        return window.TranslateWP.context.remote_api_base.replace(/\/+$/, '');
    }

    _createCacheClient(): TranslationCacheClient
    {
        return new TranslationCacheClient({
            sourceLang: this.sourceLang,
            targetLang: this.targetLang,
            siteId: window.TranslateWP.context.site_id,
            apiBaseUrl: this._remoteApiBaseUrl(),
            generation: cacheGenerationForLanguage(window.TranslateWP.context, this.targetLang),
            aiSettings: aiSettingsForLanguage(window.TranslateWP.options!.ai_settings, this.targetLang),
            sampleOrigin: () => Math.random() < (1 / 32),
        });
    }

}
