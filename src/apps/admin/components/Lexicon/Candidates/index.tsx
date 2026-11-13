import { useEffect, useId, useRef, useState } from '@wordpress/element';
import { Button, Dropdown, MenuGroup, MenuItem, Spinner } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { MouseEvent as ReactMouseEvent } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';

import { actOnLexiconCandidate, startLexiconScan } from '@/shared/api/admin';
import { getLexiconCandidatesQueryOptions, getLexiconScanQueryOptions } from '@/shared/query-options/admin';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';
import { getProState } from '@/shared/lib/pro/proState';
import { ProMarker } from '@/shared/components/pro/ProMarker';
import { ProUnlockButton } from '@/shared/components/pro/ProUnlockButton';
import { getLanguageNameFromLocale } from '@/shared/lib/languages';
import { isScanOpen, type CandidateAction, type LexiconCandidate, type LexiconScan } from '@/shared/lib/lexiconCandidates';
import { Card } from '@/shared/components/adminSurface';
import { suggestionListClass, suggestionRowClass, suggestionTermClass, suggestionReasonClass } from '@/shared/components/siteScanSuggestions/style';

export type CandidatePrefill = { term: string; description: string; languages: string[]; candidateId: string };

// What the scan is doing right now, in the owner's words.
export const scanLine = (scan: LexiconScan | null | undefined, proposed = scan?.summary.proposed): string =>
{
    if (!scan) return __('No scan has run yet.', 'translate');
    const { progress, summary } = scan;
    switch (scan.state)
    {
        case 'queued': return __('The scan is queued and starts in a moment.', 'translate');
        case 'running':
            /* translators: 1: Number of candidate terms, 2: Number of stored translations. */
            if (progress.stage === 'judging') return sprintf(__('Reviewing %1$d terms found in %2$d translations.', 'translate'), progress.candidates ?? 0, progress.records ?? 0);
            /* translators: %d: Number of stored translations. */
            if (progress.stage === 'mining') return sprintf(__('Reading %d stored translations for repeated terms and names.', 'translate'), progress.records ?? 0);
            return __('Reading your stored translations.', 'translate');
        case 'done':
            /* translators: 1: number of stored translations, 2: number of eligible terms. */
            return sprintf(__('The scan read %1$d translations and proposed %2$d terms.', 'translate'), summary.records ?? 0, proposed ?? 0);
        case 'failed': return __('The scan could not finish. Scan again in a minute.', 'translate');
        default: return '';
    }
};

// A whole dismissal leaves the languages at new, so each of them counts as dismissed.
const dismissedLanguages = (candidate: LexiconCandidate): string[] =>
    Object.entries(candidate.languages)
        .filter(([, language]) => language.status === 'never' || (candidate.status === 'never' && language.status === 'new'))
        .map(([locale]) => locale);

const activeLanguages = (candidate: LexiconCandidate): string[] =>
    Object.entries(candidate.languages).filter(([, language]) => language.status !== 'never').map(([locale]) => locale);

const CANDIDATES_PER_PAGE = 10;
type CandidateDecision = { id: string; action: CandidateAction; language?: string };

const percent = (confidence: number): string => `${Math.round(Math.max(0, Math.min(1, confidence)) * 100)}%`;

const candidateReason = (reason: string): string => reason.replace(/^(wrong|inconsistent|untranslated|ambiguous|fine)(?=\.|$)/, code =>
{
    switch (code)
    {
        case 'wrong': return __('A translation may need correcting', 'translate');
        case 'inconsistent': return __('Translations use different terms', 'translate');
        case 'untranslated': return __('The term was left untranslated', 'translate');
        case 'ambiguous': return __('The translation needs more context', 'translate');
        case 'fine': return __('The term is translated consistently', 'translate');
        default: return code;
    }
});

const CandidateRow = ({ candidate, dismissed, onAct, onAdd, busy }: { candidate: LexiconCandidate; dismissed: boolean; onAct: (action: CandidateAction, language?: string) => void; onAdd: () => void; busy: boolean }) =>
{
    const [showAll, setShowAll] = useState(false);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const detailsId = useId();
    const evidence = showAll ? candidate.evidence : candidate.evidence.slice(0, 2);

    return (
        <li className={`${suggestionRowClass} @container flex flex-col gap-2`} data-candidate={candidate.id}>
            <div className="grid min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 @min-[700px]:grid-cols-[minmax(9rem,1fr)_minmax(12rem,2fr)_auto_auto]">
                <div className="col-start-1 row-start-1 flex min-w-0 items-center gap-2">
                    <Button
                        variant="tertiary"
                        className="h-7! min-w-7! shrink-0 rounded-sm! p-1!"
                        aria-label={sprintf(
                            /* translators: %s: suggested term. */
                            __('Details for %s', 'translate'), candidate.sourceText
                        )}
                        aria-expanded={detailsOpen}
                        aria-controls={detailsId}
                        onClick={() => setDetailsOpen(value => !value)}
                    >
                        <ChevronRight className={`h-4 w-4 ${detailsOpen ? 'rotate-90' : ''}`} aria-hidden="true" />
                        <span className="screen-reader-text">{__('Details', 'translate')}</span>
                    </Button>
                    <p className={`${suggestionTermClass} font-semibold!`}>{candidate.sourceText}</p>
                </div>
                <p className={`${suggestionReasonClass} col-span-2 row-start-2 break-normal! @min-[700px]:col-span-1 @min-[700px]:col-start-2 @min-[700px]:row-start-1`}>
                    {candidateReason(candidate.reason)}
                </p>
                <span className="col-start-2 row-start-1 flex h-8 shrink-0 items-center rounded-sm border border-translatewp-border px-2 text-xs font-medium text-translatewp-muted @min-[700px]:col-start-3"
                    title={__('How sure the review is that this term needs your decision', 'translate')}>
                    {
                        /* translators: %s: confidence percentage, including the percent sign. */
                        sprintf(__('%s sure', 'translate'), percent(candidate.confidence))
                    }
                </span>
                <div className="col-span-2 row-start-3 flex flex-wrap items-center gap-2 @min-[700px]:col-span-1 @min-[700px]:col-start-4 @min-[700px]:row-start-1">
                    {dismissed ? (
                        <>
                            <Button variant="secondary" className="h-8! rounded-sm! px-3! text-sm!" disabled={busy} onClick={() => onAct('restore')}>{__('Restore suggestion', 'translate')}</Button>
                            {Object.keys(candidate.languages).length > 1 && dismissedLanguages(candidate).map(locale => (
                                <Button key={locale} variant="tertiary" className="h-8! rounded-sm! px-3! text-sm!" disabled={busy} onClick={() => onAct('restore', locale)}>{
                                    /* translators: %s: Target language name. */
                                    sprintf(__('Restore %s', 'translate'), getLanguageNameFromLocale(locale) ?? locale)
                                }</Button>
                            ))}
                        </>
                    ) : (
                        <>
                            <Button variant="secondary" className="h-8! rounded-sm! px-3! text-sm!" disabled={busy} onClick={onAdd}>{__('Add to Lexicon', 'translate')}</Button>
                            <Dropdown
                                popoverProps={{ placement: 'bottom-end' }}
                                renderToggle={({ isOpen, onToggle }) => (
                                    <Button variant="tertiary" className="h-8! rounded-sm! px-2! text-sm!" disabled={busy}
                                        aria-haspopup="menu" aria-expanded={isOpen} onClick={onToggle}>
                                        {__('Dismiss', 'translate')}<ChevronDown className="ms-1 h-3 w-3" aria-hidden="true" />
                                    </Button>
                                )}
                                renderContent={({ onClose }) => (
                                    <MenuGroup>
                                        <MenuItem disabled={busy} onClick={() => { onClose(); onAct('skip'); }}>{__('Skip this scan', 'translate')}</MenuItem>
                                        <MenuItem disabled={busy} onClick={() => { onClose(); onAct('never'); }}>{__('Never suggest on this site', 'translate')}</MenuItem>
                                    </MenuGroup>
                                )}
                            />
                        </>
                    )}
                </div>
            </div>
            <div id={detailsId} hidden={!detailsOpen} data-candidate-details className="min-w-0 border-s border-translatewp-border ps-6 text-sm text-translatewp-muted">
                <p className="m-0 text-sm text-translatewp-muted">{candidateReason(candidate.reason)}</p>
                {Object.values(candidate.languages).every(language => !language.rendering && !language.proposed) ? (
                    <p className="m-0 text-sm text-translatewp-muted" data-not-seen>{__('Not seen in any translation yet.', 'translate')}</p>
                ) : (
                <ul className="m-0 flex list-none flex-col gap-1 p-0 text-sm">
                    {Object.entries(candidate.languages).filter(([, language]) => language.rendering || language.proposed).map(([locale, language]) => (
                        <li key={locale} data-language={locale} className="flex flex-wrap gap-2 text-translatewp-neutral">
                            <span className="font-semibold">{getLanguageNameFromLocale(locale) ?? locale}:</span>
                            <span>{language.rendering ?? __('not seen yet', 'translate')}</span>
                            {language.proposed && <span className="text-translatewp-muted">{
                                /* translators: %s: Proposed translation of the candidate term. */
                                sprintf(__('proposed %s', 'translate'), language.proposed)
                            }</span>}
                            {!dismissed && language.status === 'never' && <span className="text-translatewp-muted">{__('Dismissed', 'translate')}</span>}
                        </li>
                    ))}
                </ul>
                )}

                {evidence.length > 0 && (
                    <ul className="m-0 flex list-none flex-col gap-1 p-0 text-sm text-translatewp-muted">
                        {evidence.map((entry, index) => (
                            <li key={`${entry.url}-${index}`}>
                                <q>{entry.sentence}</q>
                                {entry.url && <> <a href={entry.url} target="_blank" rel="noopener noreferrer" className="text-translatewp-primary">{__('open page', 'translate')}</a></>}
                            </li>
                        ))}
                        {candidate.evidence.length > 2 && !showAll && (
                            <li><button type="button" className="cursor-pointer border-0 bg-transparent p-0 text-sm text-translatewp-primary" onClick={() => setShowAll(true)}>{
                                /* translators: %d: Total number of occurrences of the candidate term. */
                                sprintf(__('Show all %d occurrences', 'translate'), candidate.evidence.length)
                            }</button></li>
                        )}
                    </ul>
                )}

            </div>
        </li>
    );
};

const CandidateList = ({ candidates, dismissed, busy, onAct, onAdd }: {
    candidates: LexiconCandidate[];
    dismissed: boolean;
    busy: boolean;
    onAct: (decision: CandidateDecision) => void;
    onAdd: (candidate: LexiconCandidate) => void;
}) =>
{
    const [page, setPage] = useState(1);
    const pages = Math.max(1, Math.ceil(candidates.length / CANDIDATES_PER_PAGE));
    const currentPage = Math.min(page, pages);
    const start = (currentPage - 1) * CANDIDATES_PER_PAGE;

    const resultsId = dismissed ? 'dismissed-suggestions-results' : 'suggested-terms-results';

    const paginationButton = useRef<HTMLButtonElement | null>(null);
    const paginationAnnouncement = sprintf(
        /* translators: 1: current page, 2: total pages, 3: total suggestions. */
        __('Page %1$d of %2$d (%3$d suggestions)', 'translate'),
        currentPage, pages, candidates.length
                );
    const lastPaginationAnnouncement = useRef('');
    useEffect(() =>
    {
        if (!busy) lastPaginationAnnouncement.current = paginationAnnouncement;
    }, [paginationAnnouncement, busy]);
    useEffect(() =>
    {
        const button = paginationButton.current;
        if (!button || busy) return;
        paginationButton.current = null;
        const active = document.activeElement;
        if (active !== button && active !== document.body) return;
        if (pages <= 1 || !button.isConnected || button.disabled)
        {
            document.getElementById(resultsId)?.focus();
        }
        else if (active === document.body) button.focus();
    });

    return (
        <div className="flex min-w-0 flex-col gap-3">
            <ol id={resultsId} tabIndex={-1} aria-busy={busy}
                className={suggestionListClass}>
                {candidates.slice(start, start + CANDIDATES_PER_PAGE).map(candidate => (
                    <CandidateRow
                        key={candidate.id}
                        candidate={candidate}
                        dismissed={dismissed}
                        busy={busy}
                        onAct={(action, language) => onAct({ id: candidate.id, action, language })}
                        onAdd={() => onAdd(candidate)}
                    />
                ))}
            </ol>
            <span className="screen-reader-text" role="status" aria-live="polite" aria-atomic="true">
                {busy ? lastPaginationAnnouncement.current : paginationAnnouncement}
            </span>
            {pages > 1 && (
                <nav
                    className="flex flex-wrap items-center justify-between gap-4"
                    aria-label={dismissed ? __('Dismissed suggestions pages', 'translate') : __('Suggested terms pages', 'translate')}
                >
                    <span className="text-sm text-translatewp-muted">{paginationAnnouncement}</span>
                    <div className="flex items-center gap-2">
                        <Button
                            variant="secondary" type="button" className="h-8! rounded-sm! px-3! text-sm!"
                            aria-label={dismissed
                                ? __('Previous page of dismissed suggestions', 'translate')
                                : __('Previous page of suggested terms', 'translate')}
                            aria-controls={resultsId}
                            disabled={currentPage <= 1 || busy}
                            onClick={(event: ReactMouseEvent<HTMLButtonElement>) =>
                            {
                                paginationButton.current = event.currentTarget;
                                setPage(currentPage - 1);
                            }}
                        >
                            {__('Previous', 'translate')}
                        </Button>
                        <Button
                            variant="secondary" type="button" className="h-8! rounded-sm! px-3! text-sm!"
                            aria-label={dismissed
                                ? __('Next page of dismissed suggestions', 'translate')
                                : __('Next page of suggested terms', 'translate')}
                            aria-controls={resultsId}
                            disabled={currentPage >= pages || busy}
                            onClick={(event: ReactMouseEvent<HTMLButtonElement>) =>
                            {
                                paginationButton.current = event.currentTarget;
                                setPage(currentPage + 1);
                            }}
                        >
                            {__('Next', 'translate')}
                        </Button>
                    </div>
                </nav>
            )}
        </div>
    );
};

export const LexiconCandidates = ({ onAdd }: { onAdd: (prefill: CandidatePrefill) => void }) =>
{
    const queryClient = useQueryClient();
    const { data: connectStatus } = useQuery(getConnectStatusQueryOptions());
    const entitled = getProState(connectStatus).entitled;
    const [showDismissed, setShowDismissed] = useState(false);

    const scanQuery = useQuery({ ...getLexiconScanQueryOptions(), enabled: entitled });
    const scan = scanQuery.data?.scan ?? null;
    const open = isScanOpen(scan);
    const candidatesQuery = useQuery({ ...getLexiconCandidatesQueryOptions('new'), enabled: entitled && !open });
    const dismissedQuery = useQuery({ ...getLexiconCandidatesQueryOptions('never'), enabled: entitled && showDismissed });

    const refresh = () =>
    {
        void queryClient.invalidateQueries({ queryKey: ['lexiconCandidates'] });
        void queryClient.invalidateQueries({ queryKey: ['lexiconScan'] });
    };

    const startMutation = useMutation({ mutationFn: startLexiconScan, onSuccess: refresh });
    const actMutation = useMutation({
        mutationFn: ({ id, action, language }: CandidateDecision) => (language ? actOnLexiconCandidate(id, action, language) : actOnLexiconCandidate(id, action)),
        onSuccess: refresh,
    });

    const candidates = candidatesQuery.data?.candidates ?? [];
    const dismissed = dismissedQuery.data?.candidates ?? [];
    const startError = startMutation.error as { code?: string; data?: { payload?: { code?: string; retryAfterSeconds?: unknown } } } | null;
    const startCode = startError?.data?.payload?.code ?? startError?.code;
    const retryAfter = startError?.data?.payload?.retryAfterSeconds;
    const retryMinutes = typeof retryAfter === 'number' && Number.isFinite(retryAfter) && retryAfter >= 0
        ? Math.max(1, Math.ceil(retryAfter / 60)) : null;


    const body = (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-3">
                <Button variant="primary" className="rounded-sm! text-sm!" disabled={!entitled || open || startMutation.isPending} isBusy={startMutation.isPending} onClick={() => startMutation.mutate()}>
                    {open ? __('Scanning…', 'translate') : __('Find candidates', 'translate')}
                </Button>
                {open && <Spinner />}
                <p className="m-0 text-sm text-translatewp-muted" role="status">{scanLine(scan, candidatesQuery.isSuccess ? candidates.length : undefined)}</p>
            </div>
            {startError && (
                <p className="m-0 text-sm text-translatewp-error" role="alert">
                    {startCode === 'rate_limited'
                        ? retryMinutes === null
                            ? __('A scan ran for this site within the hour. Try again later.', 'translate')
                            : sprintf(
                                /* translators: %d: minutes until another scan is available. */
                                _n('You can scan again in about %d minute.', 'You can scan again in about %d minutes.', retryMinutes, 'translate'), retryMinutes
                            )
                        : startCode === 'languages_missing'
                            ? __('Choose a default language and at least one translation language first.', 'translate')
                            : __('The scan could not be started. Try again in a minute.', 'translate')}
                </p>
            )}
            {actMutation.isError && <p className="m-0 text-sm text-translatewp-error" role="alert">{__('That change did not reach the service. Try again.', 'translate')}</p>}

            {!open && candidatesQuery.isSuccess && candidates.length === 0 && scan?.state === 'done' && (
                <p className="m-0 text-sm text-translatewp-muted">{__('Nothing waits for your decision.', 'translate')}</p>
            )}
            {!open && candidatesQuery.isSuccess && (
                <CandidateList
                    key={scan?.id ?? 'new'}
                    candidates={candidates}
                    dismissed={false}
                    busy={actMutation.isPending}
                    onAct={decision => actMutation.mutate(decision)}
                    onAdd={candidate => onAdd({
                        candidateId: candidate.id,
                        term: candidate.sourceText,
                        description: candidate.reason,
                        languages: activeLanguages(candidate),
                    })}
                />
            )}

            <div className="flex flex-col gap-2">
                <button type="button" className="w-fit cursor-pointer border-0 bg-transparent p-0 text-sm text-translatewp-primary" onClick={() => setShowDismissed(value => !value)} aria-expanded={showDismissed}>
                    {showDismissed ? __('Hide dismissed suggestions', 'translate') : __('Dismissed suggestions', 'translate')}
                </button>
                {showDismissed && dismissedQuery.isSuccess && dismissed.length === 0 && (
                    <p className="m-0 text-sm text-translatewp-muted">{__('No suggestion is dismissed on this site.', 'translate')}</p>
                )}
                {showDismissed && dismissedQuery.isSuccess && (
                    <CandidateList
                        candidates={dismissed}
                        dismissed
                        busy={actMutation.isPending}
                        onAct={decision => actMutation.mutate(decision)}
                        onAdd={() => undefined}
                    />
                )}
            </div>
        </div>
    );

    return (
        <Card title={__('Suggested terms', 'translate')} badge={entitled ? undefined : <ProMarker />}>
            <p className="m-0 text-sm text-translatewp-muted">
                {__('A scan reads your stored translations and points out terms that look wrong, inconsistent or left untranslated. Adding a term changes nothing until you retranslate.', 'translate')}
            </p>
            {entitled ? body : <ProUnlockButton feature={__('suggested terms', 'translate')} />}
        </Card>
    );
};
