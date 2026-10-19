import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Spinner, TextareaControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { BookOpen, CheckCircle2, ListPlus } from 'lucide-react';

import { useSetupStore } from '@/apps/setup/stores/setupStore';
import { mergeContext } from '@/apps/setup/components/readSiteStep/mergeContext';
import { isRestartedSetup } from '@/apps/setup/hooks/retranslationGate';
import { resolveCatalogLocale } from '@/shared/lib/languages';
import {
    extractRowItems,
    runReadSite,
    type AdminExtractionConfig,
    type ExtractionProgress,
    type IndexPhase,
    type IndexStatus,
} from '@/apps/admin/components/Translations/Indexing/extractionDriver';
import { ScanProgressBar } from '@/shared/components/ScanProgressBar';
import { useContextScan } from '@/shared/hooks/useContextScan';
import { getIndexPages, type IndexPageEntry } from '@/shared/api/admin';
import { getProState, isProActive } from '@/shared/lib/pro/proState';

export function setupExtractionConfig(): AdminExtractionConfig
{
    const defaultLanguage = resolveCatalogLocale(window.setupData?.defaultLanguage ?? 'en_US') ?? 'en-us';

    return {
        languages: [{ locale: defaultLanguage, slug: defaultLanguage.split('-')[0], active: true, default: true }],
        // Translating image alt text is a Pro feature.
        extractionOptions: { includeAlt: isProActive(getProState()), includeAttributes: true },
        aiSettings: {
            preserve_branded_terms: true,
            localized_date_time_formats: true,
            enable_units_conversion: true,
            context: '',
            writing_style: 'neutral',
            tone: 'friendly',
            formality: 5,
            creativity: 5,
            lexicon_terms: [],
        },
        siteId: window.TranslateWP?.context?.site_id ?? '',
        remoteApiBase: window.TranslateWP?.context?.remote_api_base ?? '',
    };
}

export function readProgressLine(phase: IndexPhase | 'idle', progress: ExtractionProgress | null, total: number, elapsedSeconds: number): string
{
    if (phase === 'idle' || !progress) return '';
    if (phase === 'done')
    {
        return sprintf(
            /* translators: 1: pages read, 2: segments found. */
            __('Done. %1$d pages read, %2$d text segments found.', 'translate'),
            progress.processed,
            progress.segments ?? 0,
        );
    }

    return sprintf(
        /* translators: 1: pages read so far, 2: pages to read, 3: segments found, 4: seconds elapsed, with one decimal. */
        __('Reading page %1$d of %2$d, found %3$d text segments in %4$s sec', 'translate'),
        Math.min(progress.processed + progress.failed, Math.max(total, progress.processed + progress.failed)),
        Math.max(total, progress.processed + progress.failed),
        progress.segments ?? 0,
        elapsedSeconds.toFixed(1),
    );
}

const ELAPSED_TICK_MS = 100;

// The proposal lands under whatever the owner already wrote, never over it.
export const ReadSiteStep = () =>
{
    const { context, setContext, setProposedContext, setDefaultLanguage, setStyle, setTone, siteRead, setSiteRead, setSpinePages } = useSetupStore();
    const [phase, setPhase] = useState<IndexPhase | 'idle'>('idle');
    const [progress, setProgress] = useState<ExtractionProgress | null>(null);
    const [total, setTotal] = useState(0);
    const [elapsedSeconds, setElapsedSeconds] = useState(0);
    const [failed, setFailed] = useState(false);
    const [scan, setScan] = useState<IndexStatus['scan'] | null>(null);
    const { state: contextScan, startScan: startContextScan } = useContextScan();
    const runningRef = useRef(false);

    useEffect(() =>
    {
        if (!runningRef.current) return undefined;
        const startedAt = Date.now();
        const timer = window.setInterval(() => setElapsedSeconds((Date.now() - startedAt) / 1000), ELAPSED_TICK_MS);
        return () => window.clearInterval(timer);
    }, [phase]);

    useEffect(() =>
    {
        if (contextScan.phase === 'done')
        {
            const { context: current, proposedContext } = useSetupStore.getState();
            setContext(mergeContext(current, proposedContext, contextScan.context));
            setProposedContext(contextScan.context);
            // The read knows the language the pages are written in better than
            // the WordPress locale; a wizard run again keeps the saved choice.
            if (contextScan.result.language && !isRestartedSetup()) setDefaultLanguage(contextScan.result.language);
            if (contextScan.result.style) setStyle(contextScan.result.style);
            if (contextScan.result.tone) setTone(contextScan.result.tone);
        }
    }, [contextScan, setContext, setProposedContext, setDefaultLanguage, setStyle, setTone]);

    const readSite = async () =>
    {
        runningRef.current = true;
        setFailed(false);
        setElapsedSeconds(0);
        setProgress({ processed: 0, failed: 0, segments: 0 });
        const config = setupExtractionConfig();
        const inventory = new Map<string, IndexPageEntry>();
        setSpinePages([]);
        void startContextScan();
        try
        {
            const result = await runReadSite({
                shouldContinue: () => runningRef.current,
                onStatus: (status) => { setScan(status.scan ?? null); },
                onPhase: setPhase,
                onProgress: setProgress,
                onTotal: setTotal,
                extract: row => {
                    inventory.set(row.url, { objectType: row.objectType, objectId: row.objectId, url: row.url, title: '', languages: {} });
                    setSpinePages([...inventory.values()]);
                    return extractRowItems(row, undefined, undefined, config);
                },
            });
            if (result.processed > 0) {
                setSiteRead(true);
                void getIndexPages().then(response => setSpinePages(response.pages)).catch(() => undefined);
            }
        }
        catch
        {
            setFailed(true);
            setPhase('idle');
        }
        finally
        {
            runningRef.current = false;
        }
    };

    const isRunning = phase === 'fetching' || contextScan.phase === 'scanning';
    const preparingContext = phase === 'done' && contextScan.phase === 'scanning';
    const allDone = phase === 'done' && contextScan.phase === 'done';
    const scanFailed = failed || contextScan.phase === 'failed';
    const rescanOnly = contextScan.phase === 'failed' && siteRead && !failed;

    const onButtonClick = () =>
    {
        if (rescanOnly)
        {
            void startContextScan();
            return;
        }
        void readSite();
    };

    return (
        <div className="setup-read flex flex-col gap-3 flex-1 min-h-0">
            <div className="flex items-center gap-2">
                <BookOpen className="w-5.5 h-5.5 text-translatewp-primary!" />
                <h2 className="font-semibold m-0">{__('Read your site', 'translate')}</h2>
            </div>

            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-start">
                <Button variant={allDone || siteRead ? 'secondary' : 'primary'} className="rounded-sm! w-fit" isBusy={isRunning} disabled={isRunning} onClick={onButtonClick}>
                    {scanFailed ? __('Scan again', 'translate') : __('Read my site', 'translate')}
                </Button>
                <p className="text-translatewp-muted! text-sm! m-0 md:max-w-[380px]">
                    {__('Read your site and propose its context, style and tone.', 'translate')}
                </p>
            </div>

            {phase !== 'idle' && progress && (
                <div data-read-bar={allDone ? 'done' : 'running'}>
                    <ScanProgressBar
                        scan={{
                            pagesTotal: total || scan?.pages_total || progress.processed + progress.failed,
                            pagesDone: progress.processed + progress.failed,
                            currentPage: scan?.current_page ?? null,
                        }}
                        finished={phase === 'done'}
                        failed={progress.failed}
                        showMessage={false}
                        succeeded={allDone}
                    />
                </div>
            )}

            {phase !== 'idle' && progress && (
                <p className="text-sm text-translatewp-muted! m-0 flex items-center gap-2" role="status">
                    {(phase === 'fetching' || preparingContext) && <Spinner />}
                    {phase === 'done' && !preparingContext && <CheckCircle2 size={16} className="text-translatewp-success shrink-0" aria-hidden="true" />}
                    <span data-read-status>
                        {preparingContext
                            ? __('Preparing site context', 'translate')
                            : readProgressLine(phase, progress, total, elapsedSeconds)}
                    </span>
                </p>
            )}

            {contextScan.phase === 'failed' && (
                <p className="text-sm text-translatewp-error m-0" role="alert">
                    {contextScan.message}
                </p>
            )}

            {phase === 'done' && progress && progress.failed > 0 && (
                <p className="text-sm text-translatewp-error m-0">
                    {sprintf(
                        /* translators: %d: pages that could not be read. */
                        __('%d pages could not be read from this browser. They are read again later; you can continue.', 'translate'),
                        progress.failed,
                    )}
                </p>
            )}

            {failed && (
                <p className="text-sm text-translatewp-error m-0" role="alert">
                    {__('The site could not be read. Reload this page and try again.', 'translate')}
                </p>
            )}

            <div className="flex flex-col gap-2 flex-1 min-h-0">
                <div className="flex items-center gap-2">
                    <ListPlus className="w-5.5 h-5.5 text-translatewp-primary!" />
                    <h2 className="font-semibold m-0">{__('Write context', 'translate')}</h2>
                </div>

                <TextareaControl
                    __nextHasNoMarginBottom
                    placeholder={__('Share anything about your website that would help the AI understand your content, audience, and goals.', 'translate')}
                    value={context}
                    onChange={setContext}
                    className="setup-context"
                />
            </div>
        </div>
    );
};
