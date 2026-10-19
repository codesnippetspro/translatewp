import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { applyLexiconNow, applyLocalizerNow, getStaleTranslations } from '@/shared/api/admin';
import { getQuota } from '@/shared/api/quota';
import { quotaCostSentence } from '@/shared/lib/retranslationScope';
import { buildAdminUrl } from '@/apps/admin/hooks/useNavigate';
import { PENDING_PAGES_SECTION } from '@/apps/admin/components/Translations/Indexing/RetranslatePages';
import { ConfirmDialog } from '@/shared/components/confirmDialog';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';
import { getCountryFlagFromLocale, getLanguageNameFromLocale } from '@/shared/lib/languages';
import { getJobEventsQueryOptions } from '@/shared/query-options/admin';
import { languageReady, readinessFigure, readinessSummary } from '@/shared/lib/setupTranslationProgress';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { useConfirmDialog } from '@/shared/components/confirmDialog/useConfirmDialog';
import { useTranslationReadiness } from '@/shared/hooks/useTranslationReadiness';
import {
    fetchIndexStatus,
    rereadChanged,
    retryFailed,
    runExtractionLoop,
    runReadSite,
    startTranslation,
    syncNow,
    type ExtractionProgress,
    type IndexPhase,
    type IndexStatus,
    type PageCountUnit,
} from '@/apps/admin/components/Translations/Indexing/extractionDriver';
import { indexProgressMessage } from '@/apps/admin/components/Translations/Indexing/indexProgressMessage';
import { resolveOverviewAction, type OverviewLanguageStatus } from '@/apps/admin/components/Dashboard/overviewAction';

const languageName = (locale: string): string => getLanguageNameFromLocale(locale) || locale;

const upgradeUrl = (): string =>
{
    const pro = (window as { translatewpPro?: { upgradeUrl?: unknown } }).translatewpPro;
    return typeof pro?.upgradeUrl === 'string' ? pro.upgradeUrl : '';
};

const previewUrl = (locale: string): string =>
{
    const home = window.TranslateWP?.context?.home_url ?? '';
    try {
        const url = new URL(home || window.location.origin);
        url.searchParams.set('lang', locale);
        return url.toString();
    }
    catch {
        return '';
    }
};

const IndexingDetailLink = () => (
    <a href={buildAdminUrl('', 'indexing')} className="text-sm text-translatewp-primary underline">
        {__('View indexing detail', 'translate')}
    </a>
);

const PendingPagesLink = () => (
    <a href={buildAdminUrl('', `indexing/${PENDING_PAGES_SECTION}`)} className="text-sm text-translatewp-primary underline">
        {__('See the pages still pending', 'translate')}
    </a>
);

const PREVIEW_NAMES_LIMIT = 5;

export const OverviewNextAction = () =>
{
    const queryClient = useQueryClient();
    const { requestConfirmation, confirmDialogProps } = useConfirmDialog();

    const { data: connectStatus, refetch: refetchStatus } = useQuery(getConnectStatusQueryOptions());
    const { data: jobEvents } = useQuery(getJobEventsQueryOptions(1));
    const { data: stale } = useQuery({ queryKey: ['getStaleTranslations'], queryFn: getStaleTranslations, refetchInterval: 30000 });

    const languageList = useAdminSettingsStore(state => state.settings['languages.list']);
    const targetLanguages = languageList.filter(language => !language.default && language.active);

    const liveStatus = useTranslationReadiness();
    const [status, setStatus] = useState<IndexStatus | null>(null);
    useEffect(() => { if (liveStatus) setStatus(liveStatus); }, [liveStatus]);
    const [statusFailed, setStatusFailed] = useState(false);

    useEffect(() =>
    {
        let cancelled = false;
        fetchIndexStatus()
            .then(next => { if (!cancelled) { setStatus(next); setStatusFailed(false); } })
            .catch(() => { if (!cancelled) setStatusFailed(true); });
        return () => { cancelled = true; };
    }, []);

    const [running, setRunning] = useState(false);
    const [phase, setPhase] = useState<IndexPhase | 'idle'>('idle');
    const [progress, setProgress] = useState<ExtractionProgress | null>(null);
    const [totalPages, setTotalPages] = useState(0);
    const [totalUnit, setTotalUnit] = useState<PageCountUnit>('pages');
    const [actionFailed, setActionFailed] = useState(false);
    const runningRef = useRef(false);

    const refreshStatus = async () =>
    {
        try { setStatus(await fetchIndexStatus()); }
        catch { setStatusFailed(true); }
    };

    const runInTab = async (run: () => Promise<unknown>) =>
    {
        runningRef.current = true;
        setRunning(true);
        setActionFailed(false);
        setPhase('fetching');
        setProgress({ processed: 0, failed: 0 });
        setTotalPages(0);
        setTotalUnit('pages');
        try { await run(); }
        catch { setActionFailed(true); setPhase('idle'); }
        finally
        {
            runningRef.current = false;
            setRunning(false);
            await refreshStatus();
        }
    };

    const readHandlers = {
        shouldContinue: () => runningRef.current,
        onStatus: setStatus,
        onPhase: setPhase,
        onProgress: setProgress,
        onTotal: (total: number, unit: PageCountUnit) => { setTotalPages(total); setTotalUnit(unit); },
    };

    const handleReadOnly = () => runInTab(() => runReadSite(readHandlers));

    const handleReadAndTranslate = () => runInTab(async () =>
    {
        await runReadSite(readHandlers);
        setPhase('translating');
        await startTranslation({});
        setPhase('done');
    });

    const handleRereadChanged = () => runInTab(async () =>
    {
        await rereadChanged();
        setPhase('fetching');
        await runExtractionLoop(() => runningRef.current, setProgress);
        setPhase('translating');
        await syncNow();
        setPhase('done');
    });

    const translateMutation = useMutation({
        mutationFn: (locale: string) => startTranslation({ languages: [locale] }),
        onSuccess: async () => { await refreshStatus(); },
        onError: () => setActionFailed(true),
    });

    const syncMutation = useMutation({
        mutationFn: () => syncNow(),
        onSuccess: (result) => setStatus(result.status),
        onError: () => setActionFailed(true),
    });

    const staleMutation = useMutation({
        mutationFn: async () => { await applyLexiconNow(); await applyLocalizerNow(); },
        onSuccess: async () => { await queryClient.invalidateQueries({ queryKey: ['getStaleTranslations'] }); await refreshStatus(); },
        onError: () => setActionFailed(true),
    });

    const languages: OverviewLanguageStatus[] = targetLanguages.map(language =>
    {
        const row = status?.languages?.[language.locale];
        return {
            locale: language.locale,
            ready: row?.ready ?? 0,
            readyTotal: row?.readyTotal ?? row?.total ?? 0,
            readyComplete: row?.readyComplete ?? false,
            total: row?.total ?? 0,
            dead: row?.dead ?? 0,
            repairing: row?.repairing ?? 0,
            retranslating: row?.retranslating ?? 0,
        };
    });

    const lexiconStale = stale ? Object.values(stale.lexicon).reduce((sum, count) => sum + count, 0) : 0;
    const staleCount = stale
        ? lexiconStale + Object.values(stale.localizer).reduce((sum, count) => sum + count, 0)
        : 0;

    const action = resolveOverviewAction({
        connected: connectStatus?.connected === true,
        connectionStatus: connectStatus?.connectionStatus,
        addressChanged: (connectStatus?.addressChange ?? null) !== null,
        statusFailed,
        quotaPaused: jobEvents?.events[0]?.kind === 'quota_block',
        scheduledTasksLate: status?.scheduledTasksLate ?? false,
        hasTargetLanguage: targetLanguages.length > 0,
        readPages: status?.read?.pages ?? 0,
        changedPages: status?.changedPages ?? 0,
        staleCount,
        firstPassDone: status?.firstPassDone ?? false,
        languages,
    });

    // A retried page is read again in this tab, as a re-read is, so the
    // retry does not wait for the Indexing page to be opened.
    const handleRetryFailed = () => runInTab(async () =>
    {
        await retryFailed();
        setPhase('fetching');
        await runExtractionLoop(() => runningRef.current, setProgress);
        setPhase('translating');
        await syncNow();
        setPhase('done');
    });

    const handleRetranslateStale = async () =>
    {
        const quota = await getQuota().catch(() => null);
        const message = sprintf(
            /* translators: 1: number of pages, 2: the quota sentence. */
            __('%1$s pages were translated before your latest lexicon or format change and still show the old version. %2$s', 'translate'),
            staleCount.toLocaleString(),
            quotaCostSentence({ pages: staleCount, languages: 1 }, quota)
        );
        const confirmed = await requestConfirmation({
            title: __('Translate those pages again with your changes?', 'translate'),
            message,
            confirmLabel: __('Retranslate', 'translate'),
            cancelLabel: __('Maybe later', 'translate'),
            tone: 'proceed',
        });
        if (confirmed) { staleMutation.mutate(); }
    };

    const busy = running || translateMutation.isPending || syncMutation.isPending || staleMutation.isPending;

    const progressLine = running && progress && phase !== 'idle'
        ? indexProgressMessage(phase, progress, totalPages, targetLanguages.map(language => language.locale), totalUnit)
        : null;

    const control = (() =>
    {
        switch (action.kind)
        {
            case 'connection':
                return null;

            case 'retry':
                return (
                    <Button variant="primary" className="rounded-sm!" onClick={() => { setStatusFailed(false); void refreshStatus(); void refetchStatus(); }}>
                        {__('Try again', 'translate')}
                    </Button>
                );

            case 'quota':
                return upgradeUrl() !== '' ? (
                    <a href={upgradeUrl()} target="_blank" rel="noopener noreferrer" className="text-sm font-semibold text-translatewp-primary">
                        {__('Raise the allowance', 'translate')}
                    </a>
                ) : null;

            case 'addLanguage':
                return (
                    <div className="flex flex-wrap items-center gap-3">
                        <Button variant="primary" className="rounded-sm!" href={buildAdminUrl('settings', 'languages')}>
                            {__('Add a language', 'translate')}
                        </Button>
                        <Button variant="tertiary" disabled={busy} isBusy={running} onClick={handleReadOnly}>
                            {__('Read my site now', 'translate')}
                        </Button>
                    </div>
                );

            case 'read':
                return (
                    <div className="flex flex-wrap items-center gap-3">
                        <Button variant="primary" className="rounded-sm!" disabled={busy} isBusy={running} onClick={handleReadAndTranslate}>
                            {__('Read my site', 'translate')}
                        </Button>
                        <IndexingDetailLink />
                    </div>
                );

            case 'readiness':
            {
                // The same figure the setup wizard shows for the same status.
                const summary = readinessSummary(status ?? undefined, [], languages.map(language => language.locale));
                return (
                    <div className="flex flex-col gap-1" role="status">
                        <span className="text-sm font-semibold text-translatewp-neutral">
                            {sprintf(
                                /* translators: %d: percentage of page translations ready. */
                                __('Translating your first pass: %d%% ready', 'translate'),
                                summary.percent
                            )}
                        </span>
                        <span className="text-sm text-translatewp-muted" data-readiness-figure>{readinessFigure(summary)}</span>
                        <span className="flex flex-wrap items-center gap-2 text-sm text-translatewp-muted">
                            {languages.map(language => (
                                <span key={language.locale} className="flex items-center gap-1">
                                    {getCountryFlagFromLocale(language.locale)}
                                    <span>{languageReady(language.ready) ? languageName(language.locale) : `${languageName(language.locale)}…`}</span>
                                </span>
                            ))}
                            <PendingPagesLink />
                        </span>
                        {summary.unconfirmed && (
                            <span className="text-xs text-translatewp-muted">
                                {__('Live progress is reconnecting. Translation continues in the background.', 'translate')}
                            </span>
                        )}
                    </div>
                );
            }

            case 'repairing':
                return (
                    <div className="flex flex-col gap-1" role="status">
                        <span className="text-sm font-semibold text-translatewp-neutral">
                            {sprintf(
                                /* translators: %d: number of page translations. */
                                _n('Repairing %d page translation', 'Repairing %d page translations', action.count, 'translate'),
                                action.count
                            )}
                        </span>
                        <span className="text-sm text-translatewp-muted">
                            {__('Their text was no longer in the translation cache; it is being sent again. Nothing to do.', 'translate')}
                        </span>
                        <PendingPagesLink />
                    </div>
                );

            case 'retranslating':
                return (
                    <div className="flex flex-col gap-1" role="status">
                        <span className="text-sm font-semibold text-translatewp-neutral">
                            {sprintf(
                                /* translators: 1: language names or a language count, 2: page translations done, 3: page translations in total. */
                                __('Applying your change to %1$s: %2$d of %3$d page translations', 'translate'),
                                action.locales.length > 3
                                    /* translators: %d: number of languages. */
                                    ? sprintf(_n('%d language', '%d languages', action.locales.length, 'translate'), action.locales.length)
                                    : action.locales.map(languageName).join(', '),
                                action.done,
                                action.total
                            )}
                        </span>
                        <PendingPagesLink />
                    </div>
                );

            case 'translating':
                return (
                    <div className="flex flex-col gap-1" role="status">
                        <span className="text-sm font-semibold text-translatewp-neutral">
                            {sprintf(
                                /* translators: %d: number of page translations. */
                                _n('Translating %d page translation', 'Translating %d page translations', action.count, 'translate'),
                                action.count
                            )}
                        </span>
                        <PendingPagesLink />
                    </div>
                );

            case 'failed':
                return (
                    <div className="flex flex-col gap-1">
                        <span className="text-sm font-semibold text-translatewp-neutral">
                            {sprintf(
                                /* translators: %d: number of page translations. */
                                _n('%d page translation could not be delivered', '%d page translations could not be delivered', action.count, 'translate'),
                                action.count
                            )}
                        </span>
                        <div className="flex flex-wrap items-center gap-3">
                            <Button variant="primary" className="rounded-sm!" disabled={busy} isBusy={running} onClick={handleRetryFailed}>
                                {__('Retry', 'translate')}
                            </Button>
                            <IndexingDetailLink />
                        </div>
                    </div>
                );

            case 'translateLanguage':
                return (
                    <div className="flex flex-wrap items-center gap-3">
                        <Button variant="primary" className="rounded-sm!" disabled={busy} isBusy={translateMutation.isPending} onClick={() => translateMutation.mutate(action.locale)}>
                            {sprintf(
                                /* translators: %s: target language name. */
                                __('Translate %s now', 'translate'),
                                languageName(action.locale)
                            )}
                        </Button>
                        <IndexingDetailLink />
                    </div>
                );

            case 'rereadChanged':
                return (
                    <div className="flex flex-wrap items-center gap-3">
                        <Button variant="primary" className="rounded-sm!" disabled={busy} isBusy={running} onClick={handleRereadChanged}>
                            {sprintf(
                                /* translators: %d: number of changed pages. */
                                _n('Re-read %d changed page', 'Re-read %d changed pages', action.count, 'translate'),
                                action.count
                            )}
                        </Button>
                        <a href={buildAdminUrl('', 'indexing')} className="text-sm text-translatewp-primary underline">
                            {__('Re-read everything', 'translate')}
                        </a>
                    </div>
                );

            case 'retranslateStale':
                return (
                    <div className="flex flex-wrap items-center gap-3">
                        <Button variant="primary" className="rounded-sm!" disabled={busy} isBusy={staleMutation.isPending} onClick={() => void handleRetranslateStale()}>
                            {sprintf(
                                /* translators: %d: number of pages translated before the latest change. */
                                _n('Apply your changes to %d page', 'Apply your changes to %d pages', action.count, 'translate'),
                                action.count
                            )}
                        </Button>
                        <a href={lexiconStale > 0 ? buildAdminUrl('lexicon') : buildAdminUrl('settings', 'translation')} className="text-sm text-translatewp-primary underline">
                            {__('Review what changed', 'translate')}
                        </a>
                    </div>
                );

            case 'sync':
                return (
                    <div className="flex flex-col gap-1">
                        <Button variant="primary" className="rounded-sm!" disabled={busy} isBusy={syncMutation.isPending} onClick={() => syncMutation.mutate()}>
                            {__('Run sync now', 'translate')}
                        </Button>
                        <span className="text-sm text-translatewp-muted">
                            {__('Scheduled tasks are late on this site. TranslateWP keeps indexing on page views, more slowly.', 'translate')}
                        </span>
                    </div>
                );

            case 'preview':
            {
                // "Preview in" then one flag-and-name button per
                // translation language; no re-read on a fully translated site.
                if (action.locales.length === 0) { return null; }
                // Past a handful of languages the names no longer fit on one row: flags only, the name on hover.
                const flagsOnly = action.locales.length > PREVIEW_NAMES_LIMIT;
                return (
                    <div className="flex flex-wrap items-center gap-3" data-preview-style={flagsOnly ? 'flags' : 'names'}>
                        <span className="text-sm font-semibold text-translatewp-neutral">{__('Preview in', 'translate')}</span>
                        {action.locales.map(locale => (
                            <Button
                                key={locale}
                                variant='secondary'
                                className={flagsOnly ? 'rounded-sm! px-2!' : 'rounded-sm! gap-2'}
                                href={previewUrl(locale)}
                                target="_blank"
                                rel="noopener noreferrer"
                                /* translators: %s: language name. */
                                label={flagsOnly ? sprintf(__('Preview in %s', 'translate'), languageName(locale)) : undefined}
                                showTooltip={flagsOnly}
                            >
                                {getCountryFlagFromLocale(locale)}
                                {!flagsOnly && <span>{languageName(locale)}</span>}
                            </Button>
                        ))}
                    </div>
                );
            }

            default:
                return null;
        }
    })();

    if (control === null) {
        return null;
    }

    return (
        <div className="flex flex-col items-end gap-1">
            {control}
            {progressLine && (
                <span className="flex items-center gap-2 text-sm text-translatewp-muted" role="status">
                    {running && <Spinner />}
                    {progressLine}
                </span>
            )}
            {actionFailed && (
                <span className="text-sm text-translatewp-error" role="alert">{__('That action could not be completed. Try again.', 'translate')}</span>
            )}
            <ConfirmDialog {...confirmDialogProps} />
        </div>
    );
};
