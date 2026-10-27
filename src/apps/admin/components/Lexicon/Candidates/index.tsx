import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { MouseEvent as ReactMouseEvent } from 'react';

import { actOnLexiconCandidate, startLexiconScan } from '@/shared/api/admin';
import { getLexiconCandidatesQueryOptions, getLexiconScanQueryOptions } from '@/shared/query-options/admin';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';
import { getProState } from '@/shared/lib/pro/proState';
import { ProBadge } from '@/shared/components/pro/ProBadge';
import { ProUnlockButton } from '@/shared/components/pro/ProUnlockButton';
import { getLanguageNameFromLocale } from '@/shared/lib/languages';
import { isScanOpen, type CandidateAction, type LexiconCandidate, type LexiconScan } from '@/shared/lib/lexiconCandidates';
import { Card } from '@/apps/admin/components/Dashboard/cards';

export type CandidatePrefill = { term: string; description: string; languages: string[]; candidateId: string };

// What the scan is doing right now, in the owner's words.
export const scanLine = (scan: LexiconScan | null | undefined): string =>
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
            return summary.proposed
                /* translators: 1: Number of stored translations, 2: Number of proposed terms. */
                ? sprintf(__('The scan read %1$d translations and proposed %2$d terms.', 'translate'), summary.records ?? 0, summary.proposed)
                /* translators: %d: Number of stored translations. */
                : sprintf(__('The scan read %d translations and found nothing to propose. Translate more of your site, then scan again.', 'translate'), summary.records ?? 0);
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

const CandidateRow = ({ candidate, dismissed, onAct, onAdd, busy }: { candidate: LexiconCandidate; dismissed: boolean; onAct: (action: CandidateAction, language?: string) => void; onAdd: () => void; busy: boolean }) =>
{
    const [showAll, setShowAll] = useState(false);
    const evidence = showAll ? candidate.evidence : candidate.evidence.slice(0, 2);

    return (
        <li className="m-0! flex min-w-0 flex-col gap-1 py-2" data-candidate={candidate.id}>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <div className="flex min-w-0 flex-1 basis-40 flex-col gap-1">
                    <p className="m-0 break-words text-sm font-semibold text-translatewp-neutral">{candidate.sourceText}</p>
                    <p className="m-0 truncate text-xs text-translatewp-muted" title={candidate.reason}>{candidate.reason}</p>
                </div>
                <span className="shrink-0 rounded-sm bg-translatewp-selection px-2 py-1 text-xs font-semibold text-translatewp-neutral" title={__('How sure the review is that this term needs your decision', 'translate')}>
                    {
                        /* translators: %s: Confidence percentage, including the percent sign. */
                        sprintf(__('%s sure', 'translate'), percent(candidate.confidence))
                    }
                </span>
                <div className="flex flex-wrap items-center gap-2 lg:ms-auto">
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
                            <Button variant="primary" className="h-8! rounded-sm! px-3! text-sm!" disabled={busy} onClick={onAdd}>{__('Add to Lexicon', 'translate')}</Button>
                            <Button variant="secondary" className="h-8! rounded-sm! px-3! text-sm!" disabled={busy} onClick={() => onAct('skip')}>{__('Skip this scan', 'translate')}</Button>
                            <Button variant="tertiary" className="h-8! rounded-sm! px-3! text-sm!" disabled={busy} onClick={() => onAct('never')}>{__('Never suggest on this site', 'translate')}</Button>
                        </>
                    )}
                </div>
            </div>

            <details className="min-w-0 text-sm [overflow-wrap:anywhere]">
                <summary
                    className="w-fit cursor-pointer text-translatewp-primary"
                    aria-label={sprintf(
                        /* translators: %s: suggested term. */
                        __('Details for %s', 'translate'), candidate.sourceText
                    )}
                >{__('Details', 'translate')}</summary>
                <div className="flex flex-col gap-2 py-2">
                    <p className="m-0 text-sm text-translatewp-muted">{candidate.reason}</p>
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
            </details>
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
                className="m-0! list-none divide-y divide-translatewp-border p-0!">
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
    const startError = startMutation.error as { code?: string; message?: string } | null;

    const body = (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center gap-3">
                <Button variant="primary" className="rounded-sm! text-sm!" disabled={!entitled || open || startMutation.isPending} isBusy={startMutation.isPending} onClick={() => startMutation.mutate()}>
                    {open ? __('Scanning…', 'translate') : __('Find candidates', 'translate')}
                </Button>
                {open && <Spinner />}
                <p className="m-0 text-sm text-translatewp-muted" role="status">{scanLine(scan)}</p>
            </div>
            {startError && (
                <p className="m-0 text-sm text-translatewp-error" role="alert">
                    {startError.code === 'rate_limited'
                        ? __('A scan ran for this site within the hour. Try again later.', 'translate')
                        : startError.code === 'languages_missing'
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
        <Card title={__('Suggested terms', 'translate')} badge={entitled ? undefined : <ProBadge />}>
            <p className="m-0 text-sm text-translatewp-muted">
                {__('A scan reads your stored translations and points out terms that look wrong, inconsistent or left untranslated. Adding a term changes nothing until you retranslate.', 'translate')}
            </p>
            {entitled ? body : <ProUnlockButton feature={__('suggested terms', 'translate')} />}
        </Card>
    );
};
