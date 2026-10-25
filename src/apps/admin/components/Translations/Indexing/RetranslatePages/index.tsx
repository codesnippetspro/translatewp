import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { indexFailureReason } from '@/apps/admin/components/Translations/Indexing/indexProgressMessage';
import { Button, SelectControl, Spinner, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { dateI18n } from '@wordpress/date';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { MoreHorizontal } from 'lucide-react';

import { getIndexPagesQueryOptions } from '@/shared/query-options/admin';
import { retranslatePageMutationOptions } from '@/shared/mutation-options/admin';
import { getCountryFlagFromLocale, getLanguageNameFromLocale } from '@/shared/lib/languages';
import { LanguageScopeSwitcher, type ScopeOption } from '@/shared/components/LanguageScopeSwitcher';
import { ConfirmDialog } from '@/shared/components/confirmDialog';
import { useConfirmDialog } from '@/shared/components/confirmDialog/useConfirmDialog';
import { clearFailed, extractFailedPageAgain, retryFailed, runExtractionLoop } from '@/apps/admin/components/Translations/Indexing/extractionDriver';
import { buildAdminUrl, currentHash } from '@/apps/admin/hooks/useNavigate';
import type { IndexPageEntry, IndexPageLanguage, IndexPageStatus } from '@/shared/api/admin';

const ROWS_PER_PAGE = 25;
const ALL_STATUS_FILTER = 'all';
/** The Indexing tab section the Dashboard links to while page translations are still pending. */
export const PENDING_PAGES_SECTION = 'pending-pages';

const isStillPending = (entry: IndexPageLanguage): boolean => 'pending' === entry.status || 'retranslating' === entry.status;

type StatusFilter = typeof ALL_STATUS_FILTER | Extract<IndexPageStatus, 'failed' | 'pending'>;

interface RetranslatePagesProps {
    targetLocales: string[];
    statusLocales?: string[];
    onStatusRefresh?: () => void | Promise<void>;
}

type LanguageRow = {
    page: IndexPageEntry;
    entry: IndexPageLanguage;
};

export const RetranslatePages = ({ targetLocales, statusLocales, onStatusRefresh }: RetranslatePagesProps) =>
{
    // The warm-up status payload is the reliable source of target languages on
    // this screen: the admin settings store can still be unloaded when the tab
    // mounts, which leaves the store-derived targetLocales empty. Fall back to
    // the store only when the payload carries no languages. `statusLocales` is
    // undefined until the payload arrives, distinct from an empty array once
    // it has, so the section can show a loading line instead of the
    // add-a-language message on a fresh mount.
    const availableLocales = statusLocales && statusLocales.length > 0 ? statusLocales : targetLocales;

    const queryClient = useQueryClient();
    const { data, isPending, isError } = useQuery(getIndexPagesQueryOptions());
    const [selectedLanguage, setSelectedLanguage] = useState(availableLocales[0] ?? '');
    const openedForPending = useRef(currentHash().split('/')[1] === PENDING_PAGES_SECTION).current;
    const [statusFilter, setStatusFilter] = useState<StatusFilter>(openedForPending ? 'pending' : ALL_STATUS_FILTER);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [openMenuId, setOpenMenuId] = useState<number | null>(null);
    const [isBusy, setIsBusy] = useState(false);
    const [actionFailed, setActionFailed] = useState(false);
    const menuRef = useRef<HTMLDivElement | null>(null);
    const { requestConfirmation, confirmDialogProps } = useConfirmDialog();

    const activeLanguage = availableLocales.includes(selectedLanguage) ? selectedLanguage : (availableLocales[0] ?? '');

    const mutation = useMutation({
        ...retranslatePageMutationOptions(),
        onSuccess: async () =>
        {
            // The retranslate row is re-queued for extraction: drain the extract
            // stage from this tab so the page is re-extracted with fresh
            // neighbours, title and context before it is pushed, rather than
            // leaving a stored payload for the drain to resend. A failure here is
            // picked up by the next Read my site run, so it must not block the
            // status refresh.
            try
            {
                await runExtractionLoop(() => true, () => {});
            }
            catch
            {
                // The next indexing run re-claims the row; surface nothing here.
            }
            await queryClient.invalidateQueries({ queryKey: ['indexPages'] });
            await onStatusRefresh?.();
        },
    });

    useEffect(() =>
    {
        if (null === openMenuId)
        {
            return;
        }

        const handlePointerDown = (event: MouseEvent) =>
        {
            if (menuRef.current && !menuRef.current.contains(event.target as Node))
            {
                setOpenMenuId(null);
            }
        };

        document.addEventListener('mousedown', handlePointerDown);

        return () => document.removeEventListener('mousedown', handlePointerDown);
    }, [openMenuId]);

    const pages = data?.pages ?? [];

    const pendingLanguageChosen = useRef(false);
    useEffect(() =>
    {
        if (!openedForPending || pendingLanguageChosen.current || 0 === pages.length || 0 === availableLocales.length) return;
        pendingLanguageChosen.current = true;
        const withPending = availableLocales.find(locale => pages.some(pageEntry => pageEntry.languages[locale] && isStillPending(pageEntry.languages[locale])));
        if (withPending) setSelectedLanguage(withPending);
    }, [openedForPending, pages, availableLocales]);

    // The section renders only once the languages and pages are known, so it is brought into view as it mounts.
    const scrolledToPending = useRef(false);
    const pendingSectionRef = (element: HTMLDivElement | null) =>
    {
        if (!element || !openedForPending || scrolledToPending.current) return;
        scrolledToPending.current = true;
        element.scrollIntoView();
    };

    const languageRows = useMemo<LanguageRow[]>(
        () => pages
            .filter(pageEntry => undefined !== pageEntry.languages[activeLanguage])
            .map(pageEntry => ({ page: pageEntry, entry: pageEntry.languages[activeLanguage] })),
        [pages, activeLanguage]
    );

    const query = search.trim().toLowerCase();
    const filteredRows = useMemo(
        () => languageRows.filter(({ page: pageEntry, entry }) =>
        {
            const matchesStatus = ALL_STATUS_FILTER === statusFilter
                || ('pending' === statusFilter ? isStillPending(entry) : entry.status === statusFilter);
            const matchesQuery = '' === query
                || pageEntry.title.toLowerCase().includes(query)
                || pageEntry.url.toLowerCase().includes(query);

            return matchesStatus && matchesQuery;
        }),
        [languageRows, statusFilter, query]
    );

    const totalPages = Math.max(1, Math.ceil(filteredRows.length / ROWS_PER_PAGE));
    const currentPage = Math.min(page, totalPages);
    const startIndex = (currentPage - 1) * ROWS_PER_PAGE;
    const visibleRows = filteredRows.slice(startIndex, startIndex + ROWS_PER_PAGE);
    const rangeStart = 0 === filteredRows.length ? 0 : startIndex + 1;
    const rangeEnd = Math.min(startIndex + ROWS_PER_PAGE, filteredRows.length);

    const hasFailed = languageRows.some(({ entry }) => 'failed' === entry.status);
    const languageName = getLanguageNameFromLocale(activeLanguage) ?? activeLanguage;
    const hasTargetLanguage = availableLocales.length > 0;

    const switcherOptions: ScopeOption[] = availableLocales.map(locale => ({
        value: locale,
        label: getLanguageNameFromLocale(locale) ?? locale,
        flag: getCountryFlagFromLocale(locale),
    }));

    const statusFilterOptions = [
        { label: __('All', 'translate'), value: ALL_STATUS_FILTER },
        { label: __('Failed', 'translate'), value: 'failed' },
        { label: __('Pending', 'translate'), value: 'pending' },
    ];

    const handleSelectLanguage = (value: string) =>
    {
        setSelectedLanguage(value);
        setPage(1);
        setOpenMenuId(null);
    };

    const handleSearchChange = (value: string) =>
    {
        setSearch(value);
        setPage(1);
    };

    const handleStatusFilterChange = (value: string) =>
    {
        setStatusFilter(value as StatusFilter);
        setPage(1);
    };

    const refreshAfterAction = async () =>
    {
        await queryClient.invalidateQueries({ queryKey: ['indexPages'] });
        await onStatusRefresh?.();
    };

    const handleRetranslate = async (pageEntry: IndexPageEntry, language?: string) =>
    {
        setOpenMenuId(null);

        const segments = language
            ? (pageEntry.languages[language]?.segments ?? 0)
            : Object.values(pageEntry.languages).reduce((total, entry) => total + entry.segments, 0);

        const title = language
            ? sprintf(
                /* translators: %s: target language name. */
                __('Retranslate this page in %s?', 'translate'),
                getLanguageNameFromLocale(language) ?? language
            )
            : __('Retranslate this page in every language?', 'translate');

        const message = sprintf(
            /* translators: %d: number of segments. */
            __('This resends %d segments for a fresh translation and replaces the cached version.', 'translate'),
            segments
        );

        const confirmed = await requestConfirmation({
            title,
            message,
            confirmLabel: __('Retranslate', 'translate'),
        });

        if (!confirmed)
        {
            return;
        }

        mutation.mutate({
            objectType: pageEntry.objectType,
            objectId: pageEntry.objectId,
            url: pageEntry.url,
            ...(language ? { language } : {}),
        });
    };

    const runBulkAction = async (action: () => Promise<unknown>) =>
    {
        setIsBusy(true);
        setActionFailed(false);
        try
        {
            await action();
            await refreshAfterAction();
        }
        catch
        {
            setActionFailed(true);
        }
        finally
        {
            setIsBusy(false);
        }
    };

    const handleExtractAgain = (id: number) =>
    {
        setOpenMenuId(null);
        void runBulkAction(() => extractFailedPageAgain(id));
    };

    if (undefined === statusLocales) {
        return (
            <div className="flex flex-col gap-1">
                <p className="text-sm text-translatewp-muted m-0">
                    {__('Loading pages…', 'translate')}
                </p>
            </div>
        );
    }

    if (!hasTargetLanguage) {
        return (
            <div className="flex flex-col gap-1">
                <h3 className="text-base font-semibold text-translatewp-neutral m-0">
                    {__('Pages', 'translate')}
                </h3>
                <p className="text-sm text-translatewp-muted m-0">
                    <a href={buildAdminUrl('languages')} className="text-translatewp-primary">
                        {__('Add a target language first', 'translate')}
                    </a>
                </p>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-3" id={PENDING_PAGES_SECTION} ref={pendingSectionRef}>
            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                    {getCountryFlagFromLocale(activeLanguage)}
                    <h3 className="text-base font-semibold text-translatewp-neutral m-0">
                        {sprintf(
                            /* translators: %s: target language name. */
                            __('Pages in %s', 'translate'),
                            languageName
                        )}
                    </h3>
                </div>
                <p className="text-sm text-translatewp-muted m-0">
                    {__('One row per page for the selected language. Retranslate a page to resend its text for a fresh translation and replace the cached version, or extract it again when it failed or has no saved translation yet.', 'translate')}
                </p>
            </div>

            {isPending && <Spinner />}

            {isError && (
                <p className="text-sm text-translatewp-error m-0" role="alert">
                    {__('Indexed pages could not be loaded. Try again.', 'translate')}
                </p>
            )}

            {!isPending && !isError && 0 === pages.length && (
                <p className="text-sm text-translatewp-muted m-0">
                    {__('No indexed pages yet. Read my site again to start.', 'translate')}
                </p>
            )}

            {!isPending && !isError && pages.length > 0 && (
                <>
                    {switcherOptions.length > 0 && (
                        <LanguageScopeSwitcher
                            options={switcherOptions}
                            selected={activeLanguage}
                            onSelect={handleSelectLanguage}
                            label={__('Language', 'translate')}
                        />
                    )}

                    <div className="flex items-end gap-3 flex-wrap">
                        <TextControl
                            __next40pxDefaultSize
                            __nextHasNoMarginBottom
                            label={__('Search pages', 'translate')}
                            placeholder={__('Filter by title or URL…', 'translate')}
                            value={search}
                            onChange={handleSearchChange}
                            className="max-w-86 grow"
                        />
                        <SelectControl
                            __next40pxDefaultSize
                            __nextHasNoMarginBottom
                            label={__('Status', 'translate')}
                            value={statusFilter}
                            options={statusFilterOptions}
                            onChange={handleStatusFilterChange}
                            className="max-w-52"
                        />
                        {hasFailed && (
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="secondary"
                                    disabled={isBusy}
                                    isBusy={isBusy}
                                    onClick={() => void runBulkAction(() => retryFailed(activeLanguage))}
                                >
                                    {__('Retry all failed', 'translate')}
                                </Button>
                                <Button
                                    variant="tertiary"
                                    isDestructive
                                    disabled={isBusy}
                                    onClick={() => void runBulkAction(() => clearFailed(activeLanguage))}
                                >
                                    {__('Clear failed', 'translate')}
                                </Button>
                            </div>
                        )}
                    </div>

                    {0 === filteredRows.length ? (
                        <p className="text-sm text-translatewp-muted m-0">
                            {__('No pages match your filters.', 'translate')}
                        </p>
                    ) : (
                        <>
                            <table className="w-full text-sm border border-translatewp-border rounded-lg border-separate border-spacing-0">
                                <thead>
                                    <tr className="text-start text-translatewp-muted">
                                        <th className="px-3 py-2 font-semibold text-start">{__('Page', 'translate')}</th>
                                        <th className="px-3 py-2 font-semibold text-start">{__('Status', 'translate')}</th>
                                        <th className="px-3 py-2 font-semibold text-start">{__('Segments', 'translate')}</th>
                                        <th className="px-3 py-2 font-semibold text-end">{__('Actions', 'translate')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {visibleRows.map(({ page: pageEntry, entry }) =>
                                    {
                                        const pageLabel = pageEntry.title || pagePath(pageEntry.url);
                                        const canExtractAgain = 'failed' === entry.status || 0 === entry.segments;

                                        return (
                                            <tr key={entry.id} className="border-t border-translatewp-border align-top">
                                                <td className="px-3 py-2">
                                                    <a
                                                        href={pageEntry.url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="text-translatewp-primary break-all font-medium"
                                                    >
                                                        {pageLabel}
                                                    </a>
                                                    <div className="text-translatewp-muted break-all">{pageEntry.url}</div>
                                                </td>
                                                <td className="px-3 py-2">
                                                    <StatusCell entry={entry} />
                                                </td>
                                                <td className="px-3 py-2 text-translatewp-neutral">{entry.segments}</td>
                                                <td className="px-3 py-2 text-end">
                                                    <div className="relative inline-block text-start" ref={openMenuId === entry.id ? menuRef : undefined}>
                                                        <Button
                                                            variant="tertiary"
                                                            aria-haspopup="menu"
                                                            aria-expanded={openMenuId === entry.id}
                                                            aria-label={sprintf(
                                                                /* translators: 1: page title or URL, 2: target language name. */
                                                                __('Actions for %1$s in %2$s', 'translate'),
                                                                pageLabel,
                                                                languageName
                                                            )}
                                                            disabled={confirmDialogProps.isOpen || mutation.isPending}
                                                            onClick={() => setOpenMenuId(openMenuId === entry.id ? null : entry.id)}
                                                        >
                                                            <MoreHorizontal size={18} />
                                                        </Button>

                                                        {openMenuId === entry.id && (
                                                            <div
                                                                role="menu"
                                                                className="absolute end-0 top-full z-999999 mt-1 min-w-52 flex flex-col bg-white border border-translatewp-border rounded-sm shadow-sm"
                                                            >
                                                                <button
                                                                    type="button"
                                                                    role="menuitem"
                                                                    disabled={confirmDialogProps.isOpen || mutation.isPending}
                                                                    className="px-3 py-2 text-start text-sm text-translatewp-neutral bg-white border-0 cursor-pointer hover:bg-translatewp-border/40 disabled:opacity-50 disabled:cursor-not-allowed"
                                                                    onClick={() => void handleRetranslate(pageEntry, activeLanguage)}
                                                                >
                                                                    {sprintf(
                                                                        /* translators: %s: target language name. */
                                                                        __('Retranslate in %s', 'translate'),
                                                                        languageName
                                                                    )}
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    role="menuitem"
                                                                    disabled={confirmDialogProps.isOpen || mutation.isPending}
                                                                    className="px-3 py-2 text-start text-sm text-translatewp-neutral bg-white border-0 cursor-pointer hover:bg-translatewp-border/40 disabled:opacity-50 disabled:cursor-not-allowed"
                                                                    onClick={() => void handleRetranslate(pageEntry)}
                                                                >
                                                                    {__('Retranslate in every language', 'translate')}
                                                                </button>
                                                                {canExtractAgain && (
                                                                    <button
                                                                        type="button"
                                                                        role="menuitem"
                                                                        className="px-3 py-2 text-start text-sm text-translatewp-neutral bg-white border-0 cursor-pointer hover:bg-translatewp-border/40"
                                                                        onClick={() => handleExtractAgain(entry.id)}
                                                                    >
                                                                        {__('Extract again', 'translate')}
                                                                    </button>
                                                                )}
                                                            </div>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>

                            <div className="flex items-center justify-between gap-4 flex-wrap">
                                <span className="text-sm text-translatewp-muted m-0">
                                    {sprintf(
                                        /* translators: 1: first visible row number, 2: last visible row number, 3: total matching rows. */
                                        __('Showing %1$d to %2$d of %3$d', 'translate'),
                                        rangeStart,
                                        rangeEnd,
                                        filteredRows.length
                                    )}
                                </span>

                                <div className="flex items-center gap-2">
                                    <Button
                                        variant="secondary"
                                        disabled={currentPage <= 1}
                                        onClick={() => setPage(currentPage - 1)}
                                    >
                                        {__('Previous', 'translate')}
                                    </Button>
                                    <Button
                                        variant="secondary"
                                        disabled={currentPage >= totalPages}
                                        onClick={() => setPage(currentPage + 1)}
                                    >
                                        {__('Next', 'translate')}
                                    </Button>
                                </div>
                            </div>
                        </>
                    )}
                </>
            )}

            {actionFailed && (
                <p className="text-sm text-translatewp-error m-0" role="alert">
                    {__('That action could not be completed. Try again.', 'translate')}
                </p>
            )}

            {mutation.isError && (
                <p className="text-sm text-translatewp-error m-0" role="alert">
                    {__('That page could not be queued for retranslation. Try again.', 'translate')}
                </p>
            )}

            <ConfirmDialog {...confirmDialogProps} />
        </div>
    );
};

const pagePath = (url: string): string =>
{
    try
    {
        return new URL(url).pathname;
    }
    catch
    {
        return url;
    }
};

/** "Translating since 12 min" or "… since 2 h 5 min" from its start; a page not started yet is waiting. */
const translatingLine = (since: number): string =>
{
    if (since <= 0)
    {
        return __('Waiting to be translated', 'translate');
    }

    const minutes = Math.max(1, Math.round((Date.now() / 1000 - since) / 60));
    const span = minutes < 60
        /* translators: %d: minutes. */
        ? sprintf(__('%d min', 'translate'), minutes)
        /* translators: 1: hours, 2: minutes. */
        : sprintf(__('%1$d h %2$d min', 'translate'), Math.floor(minutes / 60), minutes % 60);

    /* translators: %s: how long ago the translation started, e.g. "12 min" or "2 h 5 min". */
    return sprintf(__('Translating since %s', 'translate'), span);
};

const StatusCell = ({ entry }: { entry: IndexPageLanguage }) =>
{
    if ('failed' === entry.status)
    {
        return (
            <div className="flex flex-col gap-0.5">
                <span className="text-translatewp-error font-medium">{__('Failed', 'translate')}</span>
                <span className="text-translatewp-muted">
                    {indexFailureReason(entry.reason)}
                </span>
            </div>
        );
    }

    if ('indexed' !== entry.status)
    {
        return (
            <div className="flex flex-col gap-0.5">
                <span className="text-translatewp-neutral font-medium">
                    {'pending' === entry.status ? __('Pending', 'translate') : __('Translating again', 'translate')}
                </span>
                <span className="text-translatewp-muted">{translatingLine(entry.translatingSince ?? 0)}</span>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-0.5">
            <span className="text-translatewp-neutral font-medium">{__('Indexed', 'translate')}</span>
            {entry.updated > 0 && (
                <span className="text-translatewp-muted">
                    {dateI18n('M j, Y g:i a', entry.updated * 1000)}
                </span>
            )}
            {'stored' !== entry.title && (
                <span className="text-translatewp-warning">
                    {'pending' === entry.title
                        ? __('Title still translating', 'translate')
                        : __('Title not translated yet', 'translate')}
                </span>
            )}
        </div>
    );
};
