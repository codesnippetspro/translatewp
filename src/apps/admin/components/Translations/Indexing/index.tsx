import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { getCountryFlagFromLocale, getLanguageNameFromLocale } from '@/shared/lib/languages';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { CircleCheck, Search, X } from 'lucide-react';

import {
    defaultLocale,
    fetchIndexStatus,
    runExtractionLoop,
    runReadSiteAgain,
    searchContent,
    setPins,
    syncNow,
    type ExtractionProgress,
    type IndexPhase,
    type IndexStatus,
    type PageCountUnit,
    type PinnedObject,
    type SearchResult,
} from '@/apps/admin/components/Translations/Indexing/extractionDriver';
import { elapsedLabel, indexFailureMessage, indexProgressMessage } from '@/apps/admin/components/Translations/Indexing/indexProgressMessage';
import { ScheduledTasksLateNotice } from '@/apps/admin/components/Translations/Indexing/ScheduledTasksLateNotice';
import { ScanProgressBar } from '@/shared/components/ScanProgressBar';
import { RetranslatePages } from '@/apps/admin/components/Translations/Indexing/RetranslatePages';
import { useTranslationReadiness } from '@/shared/hooks/useTranslationReadiness';
import { ConfirmDialog } from '@/shared/components/confirmDialog';
import { useConfirmDialog } from '@/shared/components/confirmDialog/useConfirmDialog';

const STATUS_POLL_MS = 5000;
const WAITING_READ_RETRY_MS = 30000;

export const LanguagesIndexing = () =>
{
    const liveStatus = useTranslationReadiness();
    const { requestConfirmation, confirmDialogProps } = useConfirmDialog();
    const [status, setStatus] = useState<IndexStatus | null>(null);
    useEffect(() => { if (liveStatus) setStatus(liveStatus); }, [liveStatus]);
    const [statusFailed, setStatusFailed] = useState(false);
    const [isSyncing, setIsSyncing] = useState(false);
    const [isRunning, setIsRunning] = useState(false);
    const [phase, setPhase] = useState<IndexPhase | 'idle'>('idle');
    const [totalPages, setTotalPages] = useState(0);
    const [totalUnit, setTotalUnit] = useState<PageCountUnit>('pages');
    const [progress, setProgress] = useState<ExtractionProgress | null>(null);
    // Seconds since the read run started, shown next to the progress
    // line while the run is live so a slow host never looks stuck.
    const [elapsedSeconds, setElapsedSeconds] = useState(0);
    const [pins, setPinsState] = useState<PinnedObject[]>([]);
    const [searchResults, setSearchResults] = useState<SearchResult[]>([]);
    const [filterValue, setFilterValue] = useState('');
    const [isPinSelectOpen, setIsPinSelectOpen] = useState(false);
    const extractingRef = useRef(false);
    const waitingReadStartedAtRef = useRef(0);
    const waitingReadStoppedRef = useRef(false);
    const pinSelectRef = useRef<HTMLDivElement | null>(null);

    useEffect(() =>
    {
        if (!isRunning)
        {
            return undefined;
        }
        const startedAt = Date.now();
        setElapsedSeconds(0);
        const timer = window.setInterval(() =>
        {
            setElapsedSeconds(Math.floor((Date.now() - startedAt) / 1000));
        }, 1000);
        return () => window.clearInterval(timer);
    }, [isRunning]);

    const refreshStatus = async () =>
    {
        try
        {
            const nextStatus = await fetchIndexStatus();
            setStatus(nextStatus);
            setPinsState(nextStatus.pins ?? []);
            setStatusFailed(false);
        }
        catch
        {
            setStatusFailed(true);
        }
    };

    const handlePinSearch = async (term: string) =>
    {
        if (term.trim().length < 2)
        {
            setSearchResults([]);
            return;
        }

        try
        {
            setSearchResults(await searchContent(term));
        }
        catch
        {
            setSearchResults([]);
        }
    };

    useEffect(() =>
    {
        const timeout = window.setTimeout(() =>
        {
            void handlePinSearch(filterValue);
        }, 200);

        return () => window.clearTimeout(timeout);
    }, [filterValue]);

    useEffect(() =>
    {
        const handlePointerDown = (event: MouseEvent | TouchEvent) =>
        {
            if (!isPinSelectOpen || !pinSelectRef.current)
            {
                return;
            }

            if (!pinSelectRef.current.contains(event.target as Node))
            {
                setIsPinSelectOpen(false);
            }
        };

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('touchstart', handlePointerDown);

        return () =>
        {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('touchstart', handlePointerDown);
        };
    }, [isPinSelectOpen]);

    const handleAddPin = async (value: string | null | undefined) =>
    {
        const objectId = Number(value);
        if (!objectId || pins.some(pin => pin.id === objectId)) return;

        try
        {
            const response = await setPins([...pins.map(pin => pin.id), objectId]);
            setPinsState(response.pins);
            setSearchResults([]);
            setFilterValue('');
            setIsPinSelectOpen(false);
        }
        catch
        {
            setStatusFailed(true);
        }
    };

    const handleRemovePin = async (objectId: number) =>
    {
        try
        {
            const response = await setPins(pins.filter(pin => pin.id !== objectId).map(pin => pin.id));
            setPinsState(response.pins);
        }
        catch
        {
            setStatusFailed(true);
        }
    };

    useEffect(() =>
    {
        void refreshStatus();
        const interval = window.setInterval(() => void refreshStatus(), STATUS_POLL_MS);

        return () =>
        {
            window.clearInterval(interval);
            extractingRef.current = false;
        };
    }, []);

    const handleIndexSite = async () =>
    {
        // Until the status has loaded, whether the site was read is unknown,
        // so the read cannot start and skip the confirmation below.
        if (status === null || statusFailed) { return; }

        // On a site that has been read, a full re-read asks first and
        // says what it does and what it may cost.
        if ((status?.read?.pages ?? 0) > 0)
        {
            const confirmed = await requestConfirmation({
                title: __('Read your whole site again?', 'translate'),
                message: __('Are you sure? Reading again goes through every page of your site again and sends it for translation into your translation languages. Text that has not changed costs nothing; new or changed text uses your translation allowance.', 'translate'),
                confirmLabel: __('Read my site again', 'translate'),
            });
            if (!confirmed) { return; }
        }

        extractingRef.current = true;
        setIsRunning(true);
        setStatusFailed(false);
        setTotalPages(0);
        setTotalUnit('pages');
        setProgress({ processed: 0, failed: 0 });

        try
        {
            await runReadSiteAgain({
                shouldContinue: () => extractingRef.current,
                onStatus: setStatus,
                onPhase: setPhase,
                onProgress: setProgress,
                onTotal: (total, unit) => { setTotalPages(total); setTotalUnit(unit); },
            });
        }
        catch
        {
            setStatusFailed(true);
            setPhase('idle');
        }
        finally
        {
            extractingRef.current = false;
            setIsRunning(false);
            void refreshStatus();
        }
    };

    const handleReadWaitingPages = async () =>
    {
        waitingReadStartedAtRef.current = Date.now();
        extractingRef.current = true;
        setIsRunning(true);
        setStatusFailed(false);
        setPhase('fetching');
        setTotalPages(waitingReads);
        setTotalUnit('pages');
        setProgress({ processed: 0, failed: 0 });

        try
        {
            await runExtractionLoop(() => extractingRef.current, setProgress);
            setPhase('done');
        }
        catch
        {
            setStatusFailed(true);
            setPhase('idle');
        }
        finally
        {
            extractingRef.current = false;
            setIsRunning(false);
            void refreshStatus();
        }
    };

    // Pages queued to be read again, and language rows sent back to
    // extraction, are extracted as soon as this tab is open; a stopped or
    // unclaimable run is not retried in a loop.
    const waitingReads = (status?.read?.pending ?? 0)
        + Object.values(status?.languages ?? {}).reduce((sum, count) => sum + (count.pending_extract ?? 0), 0);
    useEffect(() =>
    {
        if (isRunning || extractingRef.current || waitingReads < 1 || waitingReadStoppedRef.current
            || Date.now() - waitingReadStartedAtRef.current < WAITING_READ_RETRY_MS)
        {
            return;
        }

        void handleReadWaitingPages();
    }, [waitingReads, isRunning]);

    const handleStopIndexing = () =>
    {
        extractingRef.current = false;
        waitingReadStoppedRef.current = true;
    };

    const handleSyncNow = async () =>
    {
        setIsSyncing(true);
        try
        {
            const response = await syncNow();
            setStatus(response.status);
        }
        catch
        {
            setStatusFailed(true);
        }
        finally
        {
            setIsSyncing(false);
        }
    };

    // Read the languages from the live store, not once at mount: after the
    // wizard (or a reconnect) adds languages, the persisted list arrives after
    // the first render and the tab must unlock without a reload.
    const persistedLanguages = useAdminSettingsStore(state => state.persistedSettings['languages.list']);
    const activeLocales = useMemo(
        () => (Array.isArray(persistedLanguages) ? persistedLanguages : [])
            .filter(language => language?.active)
            .map(language => language.locale),
        [persistedLanguages]
    );
    const targetLocales = useMemo(() => activeLocales.filter(locale => locale !== defaultLocale()), [activeLocales]);
    const languagesReady = activeLocales.length > 0;
    const languages = Object.entries(status?.languages ?? {});
    const statusLocales = useMemo(() => Object.keys(status?.languages ?? {}), [status]);
    const availableSearchResults = searchResults.filter(
        result => !pins.some(pin => pin.id === result.id)
    );

    return (
        <div className="flex w-full min-w-0 flex-col gap-6">
            <div className="flex flex-col gap-2">
                <h3 className="font-semibold text-lg text-translatewp-neutral m-0">
                    {__('Reading and translating', 'translate')}
                </h3>
                <p className="text-sm text-translatewp-muted m-0">
                    {__('Reading collects the text of your site spine, the front page and menu pages, in this tab and costs nothing. Translation runs on the service for the languages you chose, visible pages first. Other pages translate the first time someone views them.', 'translate')}
                </p>
                {status?.read && (
                    <p className="text-sm text-translatewp-muted m-0" role="status">
                        {sprintf(
                            /* translators: 1: pages read, 2: segments found, 3: pages still to read. */
                            __('Read: %1$d pages, %2$d segments. Waiting to be read: %3$d.', 'translate'),
                            status.read.pages,
                            status.read.segments,
                            status.read.pending,
                        )}
                    </p>
                )}
            </div>

            <ScheduledTasksLateNotice
                late={status?.scheduledTasksLate ?? false}
                onSyncNow={() => void handleSyncNow()}
                isSyncing={isSyncing}
            />

            {languagesReady ? (
                <div className="flex items-center gap-3 flex-wrap">
                    <Button
                        variant="primary"
                        onClick={isRunning ? handleStopIndexing : () => void handleIndexSite()}
                        disabled={!isRunning && (status === null || statusFailed)}
                    >
                        {isRunning
                            ? __('Stop', 'translate')
                            : (status?.read?.pages ?? 0) > 0 ? __('Read my site again', 'translate') : __('Read my site', 'translate')}
                    </Button>
                    {isRunning && <Spinner />}
                </div>
            ) : (
                <p className="text-sm text-translatewp-muted m-0">
                    {__('Choose the languages first; translation starts as soon as they are saved.', 'translate')}
                </p>
            )}

            {languagesReady && phase !== 'idle' && progress && (
                <div className="flex flex-col gap-1" role="status">
                    {(phase === 'fetching' || phase === 'done') && status?.scan && (
                        <ScanProgressBar
                            scan={{ pagesTotal: status.scan.pages_total, pagesDone: status.scan.pages_done, currentPage: status.scan.current_page }}
                            finished={phase === 'done'}
                            failed={progress.failed}
                        />
                    )}
                    <p className="text-sm text-translatewp-muted m-0">
                        {indexProgressMessage(phase, progress, totalPages, targetLocales, totalUnit)}
                        {isRunning && ` · ${elapsedLabel(elapsedSeconds)}`}
                    </p>
                    {phase === 'done' && progress.failed > 0 && (
                        <p className="text-sm text-translatewp-error m-0">
                            {indexFailureMessage(progress.failed)}
                        </p>
                    )}
                </div>
            )}

            {statusFailed && (
                <p className="text-sm text-translatewp-error m-0" role="alert">
                    {__('Indexing status could not be loaded. Try again.', 'translate')}
                </p>
            )}

            <div className="flex flex-col gap-2">
                <h3 className="text-base font-semibold text-translatewp-neutral m-0">
                    {__('Priority pages', 'translate')}
                </h3>
                <p className="text-sm text-translatewp-muted m-0">
                    {__('Pinned pages are indexed before everything else.', 'translate')}
                </p>
                <div className="relative w-full" ref={pinSelectRef}>
                    <div className="box-border px-3 py-2.75 w-full min-w-0 flex items-center gap-2 border border-translatewp-border rounded-sm hover:border-translatewp-primary focus-within:outline-2! focus-within:outline-solid! focus-within:outline-offset-2! focus-within:outline-translatewp-primary!">
                        <input
                            type="text"
                            value={filterValue}
                            placeholder={__('Search content to prioritize…', 'translate')}
                            aria-label={__('Add a page or post', 'translate')}
                            className="min-w-0 flex-1 m-0! p-0! h-auto! min-h-0! bg-transparent text-translatewp-neutral! text-sm! leading-normal! border-none! shadow-none! focus:ring-0! focus:outline-none! focus:shadow-none!"
                            onChange={event => setFilterValue(event.target.value)}
                            onFocus={() => setIsPinSelectOpen(true)}
                        />
                        <Search size={20} className="text-translatewp-icons shrink-0" />
                    </div>

                    {isPinSelectOpen && availableSearchResults.length > 0 && (
                        <div className="absolute top-14 start-0 w-full bg-white border border-translatewp-border rounded-sm max-h-40 overflow-y-auto z-999999">
                            {availableSearchResults.map(result => (
                                <button
                                    type="button"
                                    key={result.id}
                                    className="px-3 py-2.75 w-full cursor-pointer bg-white border-0 hover:bg-translatewp-border text-start"
                                    onClick={() => void handleAddPin(String(result.id))}
                                >
                                    <span className="text-translatewp-neutral! text-sm!">
                                        {`${result.title} (${result.subtype})`}
                                    </span>
                                </button>
                            ))}
                        </div>
                    )}

                    {pins.length > 0 && (
                        <div className="mt-2.5 flex flex-wrap gap-2.5">
                            {pins.map(pin => (
                                <span
                                    key={pin.id}
                                    className="flex items-center gap-2 rounded-sm bg-translatewp-selection px-2.5 py-1"
                                >
                                    <button
                                        type="button"
                                        className="inline-flex items-center justify-center m-0! p-0! h-auto! min-h-0! min-w-0! bg-transparent! border-none! shadow-none! cursor-pointer text-translatewp-icons hover:text-translatewp-primary"
                                        onClick={() => void handleRemovePin(pin.id)}
                                        aria-label={sprintf(
                                            /* translators: %s: pinned content title. */
                                            __('Remove priority pin for %s', 'translate'),
                                            pin.title || `#${pin.id}`
                                        )}
                                    >
                                        <X size={16} />
                                    </button>
                                    <span className="text-translatewp-neutral! text-sm!">
                                        {pin.title || `#${pin.id}`}
                                    </span>
                                </span>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {status !== null && languages.length === 0 && !statusFailed && (
                <p className="text-sm text-translatewp-muted m-0">
                    {(status.read?.pages ?? 0) > 0
                        ? __('Nothing queued yet. Read my site again to start warming your languages.', 'translate')
                        : __('Nothing queued yet. Read my site to start warming your languages.', 'translate')}
                </p>
            )}

            {languages.length > 0 && (
                <table className="w-full text-sm border border-translatewp-border rounded-lg border-separate border-spacing-0">
                    <thead>
                        <tr className="text-start text-translatewp-muted">
                            <th className="px-3 py-2 font-semibold text-start">{__('Language', 'translate')}</th>
                            <th className="px-3 py-2 font-semibold text-start">{__('Progress', 'translate')}</th>
                            <th className="px-3 py-2 font-semibold text-start">{__('Failed', 'translate')}</th>
                            <th className="px-3 py-2 font-semibold text-start">{__('State', 'translate')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {languages.map(([language, count]) =>
                        {
                            const finished = count.ready ?? 0;

                            return (
                                <tr key={language} className="border-t border-translatewp-border">
                                    <td className="px-3 py-2 font-semibold text-translatewp-neutral">
                                        <span className="flex items-center gap-2">
                                            {getCountryFlagFromLocale(language)}
                                            <span>{getLanguageNameFromLocale(language) || language}</span>
                                        </span>
                                    </td>
                                    <td className="px-3 py-2">
                                        {sprintf(
                                            /* translators: 1: page translations done, 2: page translations in total. */
                                            __('%1$d of %2$d translated', 'translate'),
                                            finished,
                                            count.readyTotal ?? count.total
                                        )}
                                    </td>
                                    <td className="px-3 py-2">{count.dead}</td>
                                    <td className={`px-3 py-2${count.readyComplete ? ' text-translatewp-success' : ''}`}>
                                        {count.readyComplete
                                            ? (
                                                <span className="inline-flex items-center gap-1.5" data-language-done>
                                                    <CircleCheck size={16} aria-hidden="true" />
                                                    {__('Done', 'translate')}
                                                </span>
                                            )
                                            : count.pending_extract > 0
                                                ? __('Waiting for extraction', 'translate')
                                                : __('Translating', 'translate')}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            )}

            <RetranslatePages
                targetLocales={targetLocales}
                statusLocales={status !== null ? statusLocales : undefined}
                onStatusRefresh={refreshStatus}
            />

            <ConfirmDialog {...confirmDialogProps} />
        </div>
    );
};
