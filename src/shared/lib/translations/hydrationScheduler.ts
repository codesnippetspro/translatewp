import { DOMExtractor } from '@/shared/lib/translations/DOMExtractor';
import { aiSettingsForLanguage } from '@/shared/lib/translations/aiSettingsForLanguage';
import { CONFIRMED_SAME_PREFIX, HydrationStore } from '@/shared/lib/translations/hydrationStore';
import {
    cacheGenerationForLanguage,
    TranslationCacheClient,
} from '@/shared/lib/translations/TranslationCacheClient';
import type { ExtractionOptions } from '@/shared/lib/translations/constants';

const MAX_HYDRATED_LANGUAGES = 4;

export type HydrationSchedulerOptions = {
    root: Element;
    sourceLang: string;
    activeLang: string;
    configuredLangs: string[];
    extractionOptions?: ExtractionOptions;
    noTranslateIds?: string[];
    store: HydrationStore;
    createClient?: (targetLang: string) => TranslationCacheClient;
    acceptLanguages?: readonly string[];
};

/**
 * Hydration order: active language always, then Accept-Language,
 * then most-recently-used; remaining languages fetch on switch. Runs on idle
 * time; every lookup is marked `hydration: true` so demand counters skip it.
 */
export function hydrationLanguageOrder(options: {
    activeLang: string;
    configuredLangs: string[];
    acceptLanguages: readonly string[];
    recentLanguages: string[];
    sourceLang: string;
}): string[]
{
    const configured = new Set(options.configuredLangs);
    const ordered: string[] = [];
    const push = (locale: string): void =>
    {
        if (locale && locale !== options.sourceLang && configured.has(locale) && !ordered.includes(locale))
        {
            ordered.push(locale);
        }
    };

    push(options.activeLang);
    for (const accept of options.acceptLanguages)
    {
        push(accept);
        push(accept.split('-')[0]);
    }
    for (const recent of options.recentLanguages) push(recent);

    return ordered.slice(0, MAX_HYDRATED_LANGUAGES);
}

export async function runHydration(options: HydrationSchedulerOptions): Promise<void>
{
    const createClient = options.createClient ?? ((targetLang: string) => new TranslationCacheClient({
        sourceLang: options.sourceLang,
        targetLang,
        siteId: window.TranslateWP.context.site_id,
        apiBaseUrl: window.TranslateWP.context.remote_api_base.replace(/\/+$/, ''),
        generation: cacheGenerationForLanguage(window.TranslateWP.context, targetLang),
        aiSettings: aiSettingsForLanguage(window.TranslateWP.options!.ai_settings, targetLang),
        hydration: true,
    }));

    const entries = new DOMExtractor({ noTranslateIds: options.noTranslateIds ?? [] })
        .extract(options.root, options.extractionOptions);
    if (!entries.length) return;

    const languages = hydrationLanguageOrder({
        activeLang: options.activeLang,
        configuredLangs: options.configuredLangs,
        acceptLanguages: options.acceptLanguages ?? (typeof navigator !== 'undefined' ? navigator.languages ?? [] : []),
        recentLanguages: options.store.recentLanguages(),
        sourceLang: options.sourceLang,
    });

    for (const language of languages)
    {
        try
        {
            const client = createClient(language);
            const plan = await client.prepare(entries);
            const hashes = plan.groupsForCache.map(group => group.hash);
            const local = await options.store.getMany(hashes, language);
            const missing = plan.groupsForCache.filter(group => !local.has(group.hash));
            if (!missing.length) continue;

            const lookup = await client.lookup(missing);
            if (lookup.status !== 'available') continue;

            const stored = new Map<string, string>();
            for (const group of missing)
            {
                const payload = lookup.entries.get(group.hash);
                if (payload) stored.set(group.hash, group.markerPayload === payload ? `${CONFIRMED_SAME_PREFIX}${payload}` : payload);
            }
            await options.store.putMany(stored, language);
        }
        catch
        {
            // Hydration is opportunistic — failures cost nothing.
        }
    }
}

export function scheduleHydration(options: HydrationSchedulerOptions): () => void
{
    let cancelled = false;
    const start = (): void =>
    {
        if (!cancelled) void runHydration(options);
    };

    if (typeof requestIdleCallback === 'function' && typeof cancelIdleCallback === 'function')
    {
        const idleCallback = requestIdleCallback(start, { timeout: 10_000 });
        return () =>
        {
            cancelled = true;
            cancelIdleCallback(idleCallback);
        };
    }

    const timeout = setTimeout(start, 3000);
    return () =>
    {
        cancelled = true;
        clearTimeout(timeout);
    };
}
