import apiFetch from '@wordpress/api-fetch';
import { aiSettingsForLanguage } from '@/shared/lib/translations/aiSettingsForLanguage';
import { truncateText } from '@/shared/lib/translations/truncateText';

import { useAdminSettingsStore, type TranslationsStyle } from '@/apps/admin/stores/settingsStore';
import type { Language } from '@/shared/components/languageTable';
import type { ExtractionOptions } from '@/shared/lib/translations/constants';
import { DOMExtractor } from '@/shared/lib/translations/DOMExtractor';
import { PAGE_AREAS } from '@/shared/lib/translations/pageAreas';
import {
    estimatePushBatchItemBytes,
    TRANSLATION_PUSH_BATCH_MAX_MARKER_PAYLOAD_BYTES,
} from '@/shared/lib/translations/pushBatchLimits';
import { TranslationCacheClient } from '@/shared/lib/translations/TranslationCacheClient';
import { ContextGrouping } from '@/shared/lib/translations/ContextGrouping';
import { EntryType } from '@/shared/lib/translations/constants';
import { computeFingerprintHash } from '@/shared/lib/translations/fingerprintPayload';
import { SEO_TITLE_CONTEXT_KEY } from '@/shared/lib/translations/headTitle';
import { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';
import type { TranslateWPAiSettings } from '@/shared/types';
import { getProState, isProActive } from '@/shared/lib/pro/proState';

// Mirrors MAX_ITEMS_PER_SUBMISSION on the submit-extract endpoint: the server
// rejects a single request above this, so a page with more extracted items is
// sent as several batches instead of failing.
const SUBMIT_EXTRACT_MAX_ITEMS = 200;
const PAGE_FETCH_TIMEOUT_MS = 30_000;
const EXTRACT_REASON_MAX_LENGTH = 200;

type RestErrorShape = {
    code?: unknown;
    message?: unknown;
    data?: { status?: unknown };
};

/**
 * Turn whatever an extraction attempt threw into a short, concrete failure
 * reason for the Failed pages list. A thrown Error keeps its message (the typed
 * page_fetch_* / page_not_html / page_redirected / page_fetch_timeout codes from
 * the fetch step); a rejected apiFetch call is a plain server-body object, not
 * an Error, so its message, code or HTTP status is mapped rather than collapsing
 * to the generic reason every such failure showed before.
 */
export function extractionFailureReason(error: unknown): string
{
    if (error instanceof Error) {
        return truncateText(error.message, EXTRACT_REASON_MAX_LENGTH) || 'extract_failed';
    }

    if (error && typeof error === 'object') {
        const body = error as RestErrorShape;
        const message = typeof body.message === 'string' ? body.message.trim() : '';
        const code = typeof body.code === 'string' ? body.code.trim() : '';
        const status = typeof body.data?.status === 'number' ? body.data.status : null;

        if (message) {
            if (/too many items/i.test(message)) {
                return 'too_many_segments';
            }
            return truncateText(message, EXTRACT_REASON_MAX_LENGTH);
        }
        if (code) {
            return truncateText(code, EXTRACT_REASON_MAX_LENGTH);
        }
        if (null !== status) {
            return `submit_failed_${status}`;
        }
    }

    return 'extract_failed';
}

// The site's "page not found" page, read as one of the spine pages.
export const NOT_FOUND_OBJECT_TYPE = 'not_found';

export type ExtractRow = {
    id: number;
    url: string;
    language: string;
    objectType: string;
    objectId: number;
};

export type IndexPushItem = {
    groupId: string;
    markerPayload: string;
    contextKey: string;
    contextKind: string;
    neighbours?: { before: string; after: string };
    cache: { siteId: string; hash: string };
};

export type LanguageIndexStatus = {
    /** Page translations whose job finished. */
    ready?: number;
    /** Rows of the pages indexing covers, failed ones included; visitor-only pages are left out. */
    readyTotal?: number;
    /** Nothing in flight: translated plus failed covers the total. */
    readyComplete?: boolean;
    total: number;
    done: number;
    dead: number;
    pending_extract: number;
    pending_push: number;
    translated?: number;
    confirming?: number;
    repairing?: number;
    retranslating?: number;
    complete: boolean;
    reported: boolean;
};

export type PinnedObject = {
    id: number;
    title: string;
    type: string;
};

export type FailedItem = {
    id: number;
    language: string;
    url: string;
    reason: string;
    objectType: string;
    objectId: number;
    attempts: number;
};

export type ReadCounts = { pages: number; pending: number; failed: number; segments: number };

export type ScanProgress = {
    pagesTotal: number;
    pagesDone: number;
    currentPage: { title: string; path: string } | null;
};

export type IndexStatus = {
    firstPassDone?: boolean;
    languages: Record<string, LanguageIndexStatus>;
    /** How the reading stands, separate from translation. */
    read?: ReadCounts;
    /** Pages saved since they were read; a re-read is offered only for these. */
    changedPages?: number;
    /** Page-level progress of a running read, in the camelCase the payload uses. */
    scan?: {
        pages_total: number;
        pages_done: number;
        current_page: { title: string; path: string } | null;
    };
    drainScheduled: boolean;
    scheduledTasksLate: boolean;
    lastRun: number | null;
    failed: FailedItem[];
    pins: PinnedObject[];
    /** Set by the live readiness hook when its last refresh failed; the counts are the last confirmed ones. */
    unconfirmed?: boolean;
};

export const setPins = async (objectIds: number[]): Promise<{ pins: PinnedObject[] }> =>
    await apiFetch({ path: '/translatewp/v1/indexing/pins', method: 'POST', data: { objectIds } }) as { pins: PinnedObject[] };

export type SearchResult = {
    id: number;
    title: string;
    subtype: string;
};

export const searchContent = async (term: string): Promise<SearchResult[]> =>
{
    const results = await apiFetch({
        path: `/wp/v2/search?search=${encodeURIComponent(term)}&per_page=10&_fields=id,title,subtype`,
        method: 'GET',
    }) as SearchResult[];

    return Array.isArray(results) ? results : [];
};

export const fetchIndexStatus = async (): Promise<IndexStatus> =>
    await apiFetch({ path: '/translatewp/v1/indexing/status', method: 'GET' }) as IndexStatus;

/** Queue the spine for reading; no language, no service call. */
export const readSite = async (): Promise<{ queued: number; status: IndexStatus }> =>
    await apiFetch({ path: '/translatewp/v1/indexing/read-site', method: 'POST', data: {} }) as { queued: number; status: IndexStatus };

/** Fan the read pages out into the target languages and start translating. */
export const startTranslation = async (input: { languages?: string[]; force?: boolean } = {}): Promise<{ queued: number; status: IndexStatus }> =>
    await apiFetch({ path: '/translatewp/v1/indexing/start-translation', method: 'POST', data: input }) as { queued: number; status: IndexStatus };

/** Queue only the pages changed since they were read for a fresh reading. */
export const rereadChanged = async (): Promise<{ requeued: number; status: IndexStatus }> =>
    await apiFetch({ path: '/translatewp/v1/indexing/reread-changed', method: 'POST', data: {} }) as { requeued: number; status: IndexStatus };

export const enqueueIndex = async (): Promise<{ enqueued: number; status: IndexStatus }> =>
    await apiFetch({ path: '/translatewp/v1/indexing/enqueue', method: 'POST', data: {} }) as { enqueued: number; status: IndexStatus };

export const syncNow = async (): Promise<{ status: IndexStatus }> =>
    await apiFetch({ path: '/translatewp/v1/indexing/sync-now', method: 'POST', data: {} }) as { status: IndexStatus };

export const retryFailed = async (language?: string): Promise<{ retried: number; status: IndexStatus }> =>
    await apiFetch({
        path: '/translatewp/v1/indexing/retry-failed',
        method: 'POST',
        data: language ? { language } : {},
    }) as { retried: number; status: IndexStatus };

export const clearFailed = async (language?: string): Promise<{ cleared: number; status: IndexStatus }> =>
    await apiFetch({
        path: '/translatewp/v1/indexing/clear-failed',
        method: 'POST',
        data: language ? { language } : {},
    }) as { cleared: number; status: IndexStatus };

const claimExtractBatch = async (): Promise<ExtractRow[]> =>
{
    const response = await apiFetch({
        path: '/translatewp/v1/indexing/claim-extract',
        method: 'POST',
        data: {},
    }) as { rows: ExtractRow[] };

    return response.rows;
};

const chunkPushItems = (items: IndexPushItem[]): IndexPushItem[][] =>
{
    const chunks: IndexPushItem[][] = [];
    for (let start = 0; start < items.length; start += SUBMIT_EXTRACT_MAX_ITEMS) {
        chunks.push(items.slice(start, start + SUBMIT_EXTRACT_MAX_ITEMS));
    }

    return chunks;
};

const submitExtraction = async (id: number, items: IndexPushItem[]): Promise<void> =>
{
    const chunks = chunkPushItems(items);

    // An empty extraction is one finishing submission: the server completes the
    // row without a payload.
    if (!chunks.length) {
        await apiFetch({ path: '/translatewp/v1/indexing/submit-extract', method: 'POST', data: { id, items: [] } });
        return;
    }

    // Every batch but the last carries `more` so the server buffers it and only
    // the final batch moves the row on to the push stage.
    for (let index = 0; index < chunks.length; index++) {
        const isLast = index === chunks.length - 1;
        await apiFetch({
            path: '/translatewp/v1/indexing/submit-extract',
            method: 'POST',
            data: isLast ? { id, items: chunks[index] } : { id, items: chunks[index], more: true },
        });
    }
};

const reportExtractionFailure = async (id: number, reason: string): Promise<void> =>
{
    await apiFetch({ path: '/translatewp/v1/indexing/extract-failed', method: 'POST', data: { id, reason } });
};

export const reextractPage = async (id: number): Promise<ExtractRow | null> =>
{
    const response = await apiFetch({
        path: '/translatewp/v1/indexing/reextract-page',
        method: 'POST',
        data: { id },
    }) as { row: ExtractRow | null };

    return response.row ?? null;
};

export type AdminExtractionConfig = {
    languages: Language[];
    extractionOptions: ExtractionOptions;
    aiSettings: TranslateWPAiSettings;
    siteId: string;
    remoteApiBase: string;
};

/**
 * The extraction inputs the admin screen needs, assembled from the data it
 * already holds: the settings store (loaded on boot) for languages, extraction
 * options and AI settings, and the admin-localized window for the connection
 * context and the lexicon terms the store does not carry. Persisted settings,
 * not in-progress edits, so a warm-up fingerprints exactly as the visitor
 * pipeline will once those settings are live.
 */
// Audience, length and cultural adaptation are Pro controls; at their
// defaults they are left out so an untouched site fingerprints as before.
function advancedStyleControls(style: TranslationsStyle, proActive: boolean): Partial<Pick<TranslateWPAiSettings, 'audience' | 'length' | 'cultural_adaptation'>>
{
    if (!proActive) return {};

    const controls: Partial<Pick<TranslateWPAiSettings, 'audience' | 'length' | 'cultural_adaptation'>> = {};
    const audience = (style.audience ?? '').trim().slice(0, 200);
    if (audience !== '') controls.audience = audience;
    if (style.length && style.length !== 'match') controls.length = style.length;
    if (style.cultural_adaptation && style.cultural_adaptation !== 'keep') controls.cultural_adaptation = style.cultural_adaptation;

    return controls;
}

export function readAdminExtractionConfig(): AdminExtractionConfig
{
    const { persistedSettings } = useAdminSettingsStore.getState();
    const translation = persistedSettings['translations.settings'];
    const style = persistedSettings['translations.style'];

    return {
        languages: persistedSettings['languages.list'],
        extractionOptions: {
            // Translating image alt text is a Pro feature.
            includeAlt: translation.translate_image_alt_tags && isProActive(getProState()),
            includeAttributes: !translation.preserve_html_attributes,
            excludedAreas: PAGE_AREAS.filter(area => translation[`translate_${area}`] === false),
        },
        aiSettings: {
            preserve_branded_terms: translation.preserve_branded_terms,
            localized_date_time_formats: translation.localized_date_time_formats,
            enable_units_conversion: translation.enable_units_conversion,
            context: persistedSettings['translations.context'].text_context,
            writing_style: style.type,
            tone: style.tone,
            formality: style.formality,
            creativity: style.creativity,
            lexicon_terms: window.TranslateWP?.lexicon_terms ?? [],
            ...advancedStyleControls(style, isProActive(getProState())),
        },
        siteId: window.TranslateWP?.context?.site_id ?? '',
        remoteApiBase: window.TranslateWP?.context?.remote_api_base ?? '',
    };
}

export function defaultLocale(languages: Language[] = readAdminExtractionConfig().languages): string
{
    const active = languages.filter(language => language?.active);

    return active.find(language => language.default)?.locale ?? active[0]?.locale ?? 'auto';
}

/**
 * Build the push items for one queue row exactly the way the visitor pipeline
 * would: same extractor configuration, same grouping/fingerprinting, same
 * size filter and hash dedupe. Fingerprint parity is the whole point, a
 * warm-up that hashes differently warms nothing.
 */
export async function extractRowItems(
    row: ExtractRow,
    parseHtml: (html: string) => Document = html => new DOMParser().parseFromString(html, 'text/html'),
    fetchPage: (url: string, expectNotFound?: boolean) => Promise<string> = async (url, expectNotFound = false) =>
    {
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), PAGE_FETCH_TIMEOUT_MS);

        let response: Response;
        let authenticated = false;
        try {
            try {
                response = await fetch(url, { credentials: 'omit', signal: controller.signal });
            } catch (error) {
                if (!(error instanceof TypeError)) throw error;
                authenticated = true;
                response = await fetch(url, { credentials: 'same-origin', signal: controller.signal });
            }
            const destination = response.redirected && response.url ? new URL(response.url) : null;
            const accessRedirect = destination && (
                destination.origin !== new URL(url, window.location.href).origin
                || destination.pathname.endsWith('/wp-login.php')
            );
            if (!authenticated && (response.status === 401 || response.status === 403 || accessRedirect)) {
                response = await fetch(url, { credentials: 'same-origin', signal: controller.signal });
            }
        }
        catch (error) {
            if (error instanceof DOMException && 'AbortError' === error.name) {
                throw new Error('page_fetch_timeout');
            }
            throw error;
        }
        finally {
            window.clearTimeout(timeout);
        }

        // The site's 404 page is read from the 404 response itself.
        if (!response.ok && !(expectNotFound && response.status === 404)) throw new Error(`page_fetch_${response.status}`);

        // A redirect that leaves the site cannot be the page's own content (a
        // login or consent wall); a same-origin redirect, such as a trailing
        // slash, is followed and extracted normally.
        if (response.redirected && response.url) {
            const requestedOrigin = new URL(url, window.location.href).origin;
            if (new URL(response.url).origin !== requestedOrigin || new URL(response.url).pathname.endsWith('/wp-login.php')) {
                throw new Error('page_redirected');
            }
        }

        const contentType = response.headers?.get?.('content-type') ?? '';
        if (contentType && !/text\/html|application\/xhtml\+xml/i.test(contentType)) {
            throw new Error('page_not_html');
        }

        return await response.text();
    },
    config: AdminExtractionConfig = readAdminExtractionConfig(),
): Promise<IndexPushItem[]>
{
    const parsed = parseHtml(await fetchPage(row.url, row.objectType === NOT_FOUND_OBJECT_TYPE));
    const extractor = new DOMExtractor({ noTranslateIds: ['wpadminbar'] });
    const entries = extractor.extract(parsed.body, config.extractionOptions);

    const cacheClient = new TranslationCacheClient({
        sourceLang: defaultLocale(config.languages),
        targetLang: row.language,
        siteId: config.siteId,
        apiBaseUrl: config.remoteApiBase.replace(/\/+$/, ''),
        aiSettings: aiSettingsForLanguage(config.aiSettings, row.language),
    });
    const plan = await cacheClient.prepare(entries);

    const items: IndexPushItem[] = [];
    const seenHashes = new Set<string>();
    for (const groupCache of plan.groupsForCache)
    {
        const item: IndexPushItem = {
            groupId: groupCache.group.id,
            markerPayload: groupCache.markerPayload,
            contextKey: groupCache.group.contextKey,
            contextKind: groupCache.group.kind,
            ...(groupCache.group.neighbours ? { neighbours: groupCache.group.neighbours } : {}),
            cache: { siteId: groupCache.siteId, hash: groupCache.hash },
        };

        if (estimatePushBatchItemBytes(item) > TRANSLATION_PUSH_BATCH_MAX_MARKER_PAYLOAD_BYTES) continue;
        if (seenHashes.has(groupCache.hash)) continue;
        seenHashes.add(groupCache.hash);
        items.push(item);
    }

    // Warm the page title in the exact shape the visitor runtime
    // uses for it, so a language switch finds the tab title in the cache.
    const titleItem = await buildTitleWarmItem(parsed, cacheClient, seenHashes);
    if (titleItem) items.push(titleItem);

    return items;
}

export async function buildTitleWarmItem(
    parsed: Document,
    cacheClient: TranslationCacheClient,
    seenHashes: Set<string>,
): Promise<IndexPushItem | null>
{
    const value = (parsed.title ?? '').trim();
    if ('' === value) return null;

    const node = parsed.createTextNode(value);
    const canonicalEntries = [
        new TranslatableEntry({ id: 'item_0001', type: EntryType.TEXT, node, attributeName: null, value }),
    ];
    const markerPayload = ContextGrouping.serializeWithMarkers(canonicalEntries);
    const hash = await computeFingerprintHash({
        markerPayload,
        sourceLang: cacheClient.sourceLang,
        targetLang: cacheClient.targetLang,
        contextKind: 'attribute',
        settings: cacheClient.aiSettings,
    });
    if (seenHashes.has(hash)) return null;
    seenHashes.add(hash);

    return {
        groupId: `seo:${SEO_TITLE_CONTEXT_KEY}`,
        markerPayload,
        contextKey: SEO_TITLE_CONTEXT_KEY,
        contextKind: 'attribute',
        cache: { siteId: cacheClient.siteId, hash },
    };
}

export type ExtractionProgress = { processed: number; failed: number; segments?: number };

/**
 * Drain the extract stage from this browser tab: claim, extract, submit,
 * repeat until the queue has no extract work or the caller stops the loop.
 * A row is one page for one target language, so extraction claims the same
 * page more than once when it needs several languages; processed/failed
 * collapse those rows to distinct page URLs, and a page already processed
 * for one language is not moved back to failed by another language's row.
 */
export async function runExtractionLoop(
    shouldContinue: () => boolean,
    onProgress: (progress: ExtractionProgress) => void,
    extract: typeof extractRowItems = extractRowItems,
): Promise<ExtractionProgress>
{
    const progress: ExtractionProgress = { processed: 0, failed: 0, segments: 0 };
    const processedUrls = new Set<string>();
    const failedUrls = new Set<string>();

    while (shouldContinue())
    {
        const rows = await claimExtractBatch();
        if (!rows.length) break;

        for (const row of rows)
        {
            if (!shouldContinue()) return progress;

            try
            {
                // An empty extraction is a finished page, not a failure: the
                // server just marks the row done and moves on.
                const items = await extract(row);
                await submitExtraction(row.id, items);
                processedUrls.add(row.url);
                failedUrls.delete(row.url);
                progress.segments = (progress.segments ?? 0) + items.length;
            }
            catch (error)
            {
                await reportExtractionFailure(row.id, extractionFailureReason(error));
                if (!processedUrls.has(row.url)) failedUrls.add(row.url);
            }

            progress.processed = processedUrls.size;
            progress.failed = failedUrls.size;
            onProgress({ ...progress });
        }
    }

    return progress;
}

export type ReextractResult = { ok: true } | { ok: false; reason: string };

/**
 * Retry one dead page's extraction from this tab: revive and claim just that
 * row on the server, extract and submit it here, and return the concrete
 * failure reason if it fails again so the Failed pages list can show it inline.
 */
export async function extractFailedPageAgain(
    id: number,
    extract: typeof extractRowItems = extractRowItems,
): Promise<ReextractResult>
{
    const row = await reextractPage(id);
    if (!row) {
        return { ok: false, reason: 'not_failed' };
    }

    try
    {
        await submitExtraction(row.id, await extract(row));
        return { ok: true };
    }
    catch (error)
    {
        const reason = extractionFailureReason(error);
        await reportExtractionFailure(row.id, reason);
        return { ok: false, reason };
    }
}

export type IndexPhase = 'fetching' | 'translating' | 'done';

export type PageCountUnit = 'pages' | 'rows';

export type IndexSiteHandlers = {
    shouldContinue: () => boolean;
    onStatus: (status: IndexStatus) => void;
    onPhase: (phase: IndexPhase) => void;
    onProgress: (progress: ExtractionProgress) => void;
    onTotal: (total: number, unit: PageCountUnit) => void;
    extract?: typeof extractRowItems;
};

type PendingExtractEstimate = {
    total: number;
    unit: PageCountUnit;
};

/**
 * Rows are claimed one per page per target language, so summing pending_extract
 * across languages counts rows, not pages. Languages enqueued together still
 * share the same page set, so an equal pending count across all of them is
 * that shared distinct-page total; once languages drift apart there is no way
 * to recover distinct pages from the aggregate, so the row sum is reported as
 * rows instead of pages.
 */
function totalPendingExtract(status: IndexStatus): PendingExtractEstimate
{
    const pending = Object.values(status.languages).map(language => language.pending_extract);

    if (!pending.length)
    {
        return { total: 0, unit: 'pages' };
    }

    const sharedAcrossLanguages = pending.every(count => count === pending[0]);
    if (sharedAcrossLanguages)
    {
        return { total: pending[0], unit: 'pages' };
    }

    return { total: pending.reduce((sum, count) => sum + count, 0), unit: 'rows' };
}

/**
 * Active languages in list order, source included, so progress copy can name
 * every language a site owner sees.
 */
export function activeLanguageLocales(): string[]
{
    return readAdminExtractionConfig().languages
        .filter(language => language?.active)
        .map(language => language.locale);
}

/**
 * The whole Index-my-site run as one pass: queue the warm-up index, drain the
 * extract stage in this tab (reusing runExtractionLoop), then sync what was
 * extracted. Stopping the caller's shouldContinue halts extraction cleanly and
 * the run still syncs the pages already extracted.
 */
/**
 * The Read your site pass: queue the spine for reading and extract
 * it in this tab. Nothing is translated; the fan-out happens when languages
 * are saved.
 */
export async function runReadSite(handlers: IndexSiteHandlers): Promise<ExtractionProgress>
{
    handlers.onPhase('fetching');

    const response = await readSite();
    handlers.onStatus(response.status);
    handlers.onTotal(response.status.read?.pending ?? response.queued, 'pages');

    const progress = await runExtractionLoop(handlers.shouldContinue, handlers.onProgress, handlers.extract);

    handlers.onPhase('done');

    return progress;
}

/**
 * Read the site again and translate every read page again for the
 * configured languages. Unchanged text hits the service cache and costs
 * nothing.
 */
export async function runReadSiteAgain(handlers: IndexSiteHandlers): Promise<ExtractionProgress>
{
    const progress = await runReadSite(handlers);

    handlers.onPhase('translating');
    const started = await startTranslation({ force: true });
    handlers.onStatus(started.status);

    handlers.onPhase('done');

    return progress;
}

export async function runIndexSite(handlers: IndexSiteHandlers): Promise<ExtractionProgress>
{
    handlers.onPhase('fetching');

    const enqueueResponse = await enqueueIndex();
    handlers.onStatus(enqueueResponse.status);
    const pendingExtract = totalPendingExtract(enqueueResponse.status);
    handlers.onTotal(pendingExtract.total, pendingExtract.unit);

    const progress = await runExtractionLoop(handlers.shouldContinue, handlers.onProgress, handlers.extract);

    handlers.onPhase('translating');

    const syncResponse = await syncNow();
    handlers.onStatus(syncResponse.status);

    handlers.onPhase('done');

    return progress;
}
