import { useEffect, useRef, useState } from '@wordpress/element';
import { useQueryClient } from '@tanstack/react-query';
import { refreshTranslationReadiness } from '@/shared/api/translationReadiness';
import type { DashboardMetrics, IndexPagesResponse } from '@/shared/api/admin';
import type { SetupTranslationProgress } from '@/shared/api/setupTranslationProgress';
import type { IndexStatus } from '@/apps/admin/components/Translations/Indexing/extractionDriver';
import { TranslationEventClient } from '@/shared/lib/translations/TranslationEventClient';

/**
 * The live readiness status, plus whether the last refresh failed. `unconfirmed`
 * is set even before any counts exist, so a first failed refresh never reads as
 * "nothing ready".
 */
export const useTranslationReadinessState = (): { status: IndexStatus | undefined; unconfirmed: boolean } =>
{
    const client = useQueryClient();
    const [status, setStatus] = useState<IndexStatus>();
    const [unconfirmed, setUnconfirmed] = useState(false);
    const cursor = useRef(0);

    useEffect(() =>
    {
        const abort = new AbortController();
        const streams = new Map<string, AbortController>();
        const delivery = new TranslationEventClient();
        let timer: ReturnType<typeof setTimeout> | undefined;
        let running = false;
        let requestedCursor: number | undefined;

        const reconcile = async (nextCursor?: number): Promise<void> =>
        {
            if (abort.signal.aborted) return;
            if (running) {
                requestedCursor = nextCursor ?? cursor.current;
                return;
            }
            running = true;
            if (timer) clearTimeout(timer);
            const batchCursor = nextCursor ?? cursor.current;
            let progressed = false;
            try {
                const result = await refreshTranslationReadiness(batchCursor, abort.signal);
                if (abort.signal.aborted) return;
                cursor.current = result.cursor;
                progressed = true;
                setStatus(result.status);
                setUnconfirmed(false);
                client.setQueryData(['indexPages'], result.pages);
                client.setQueriesData({ queryKey: ['setupTranslationProgress'] }, { status: result.status, pages: result.pages });
                client.setQueryData<DashboardMetrics>(['dashboardMetrics'], previous => previous ? { ...previous, ...result.translations } : previous);

                const key = `${result.language}:${result.hashes.join(',')}`;
                if (result.subscription && result.hashes.length && !streams.has(key)) {
                    if (streams.size >= 6) {
                        const oldest = streams.keys().next().value;
                        if (oldest !== undefined) { streams.get(oldest)?.abort(); streams.delete(oldest); }
                    }
                    const streamAbort = new AbortController();
                    streams.set(key, streamAbort);
                    void delivery.deliver({
                        subscription: result.subscription,
                        hashes: result.hashes,
                        signal: streamAbort.signal,
                        onReady: () => { void reconcile(batchCursor); },
                        onFailed: () => { void reconcile(batchCursor); },
                    }).catch(() => undefined).finally(() => {
                        if (streams.get(key) === streamAbort) streams.delete(key);
                    });
                }
            } catch {
                if (abort.signal.aborted) return;
                const unconfirmedPages = (previous: IndexPagesResponse | undefined): IndexPagesResponse | undefined => previous && ({
                    ...previous,
                    pages: previous.pages.map(page => ({
                        ...page,
                        languages: Object.fromEntries(Object.entries(page.languages).map(([locale, language]) => [locale, {
                            ...language, status: language.status === 'indexed' ? 'pending' : language.status,
                        }])),
                    })),
                });
                // The counts stay at their last confirmed value; a failed refresh
                // only marks them unconfirmed, it never proves a page unready.
                // The setup figure is counted from its page rows until the first
                // status arrives, so those rows are left as they were shown.
                client.setQueryData<IndexPagesResponse>(['indexPages'], unconfirmedPages);
                client.setQueriesData<SetupTranslationProgress>({ queryKey: ['setupTranslationProgress'] }, previous => previous && ({
                    ...previous, status: { ...previous.status, unconfirmed: true },
                }));
                client.setQueryData<DashboardMetrics>(['dashboardMetrics'], previous => previous && ({ ...previous, translationsToday: 0, languageUsage: [], recentContent: [] }));
                setStatus(previous => previous && { ...previous, unconfirmed: true });
                setUnconfirmed(true);
            } finally {
                running = false;
                if (!abort.signal.aborted) {
                    const pending = requestedCursor;
                    requestedCursor = undefined;
                    timer = setTimeout(() => { void reconcile(pending); }, pending !== undefined || (progressed && cursor.current !== 0) ? 2000 : 6000);
                }
            }
        };

        void reconcile();
        return () => {
            abort.abort();
            if (timer) clearTimeout(timer);
            streams.forEach(stream => stream.abort());
            streams.clear();
        };
    }, [client]);

    return { status, unconfirmed };
};

export const useTranslationReadiness = (): IndexStatus | undefined => useTranslationReadinessState().status;
