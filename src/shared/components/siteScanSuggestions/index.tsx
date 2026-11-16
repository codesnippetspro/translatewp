import { useEffect, useId, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { applyScanLexicon } from '@/shared/api/setup';
import type { ScanPollState } from '@/shared/hooks/useContextScan';
import { siteScanSuggestionsQueryOptions } from '@/shared/query-options/siteScanSuggestions';
import { canAdminister } from '@/shared/lib/access';
import { SuggestionCheckbox } from './checkbox';
import { Card } from '@/shared/components/adminSurface';
import { suggestionListClass, suggestionRowClass, suggestionTermClass, suggestionReasonClass } from './style';

export const SiteScanSuggestions = ({ scanState, framed = false }: { scanState?: ScanPollState; framed?: boolean }) =>
{
    const queryClient = useQueryClient();
    const [lexiconNotice, setLexiconNotice] = useState<string | null>(null);
    const { data } = useQuery({ ...siteScanSuggestionsQueryOptions, enabled: canAdminister() });
    const suggestions = scanState?.phase === 'scanning' ? [] : data?.lexiconSuggestions ?? [];
    const [omittedTerms, setOmittedTerms] = useState<string[]>([]);
    const suggestionId = useId();
    const [showAllSuggestions, setShowAllSuggestions] = useState(false);
    const suggestionKey = JSON.stringify(suggestions.map(suggestion => suggestion.term));
    useEffect(() => { setOmittedTerms([]); setShowAllSuggestions(false); }, [suggestionKey]);
    const selectedTerms = suggestions.filter(suggestion => !omittedTerms.includes(suggestion.term)).map(suggestion => suggestion.term);
    const applyLexicon = useMutation({
        mutationFn: (terms?: string[]) => applyScanLexicon(terms),
        onSuccess: async (result, terms) =>
        {
            setLexiconNotice(terms?.length === 0 ? __('Suggested terms were dismissed.', 'translate') : sprintf(
                /* translators: %d: number of terms added. */
                __('%d suggested terms were added to the lexicon.', 'translate'),
                result.added,
            ));
            queryClient.setQueryData(siteScanSuggestionsQueryOptions.queryKey, previous => ({ ...(previous ?? { status: 'done' as const }), lexiconSuggestions: [] }));
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: ['getWordPressLexiconTerms'] }),
                queryClient.invalidateQueries({ queryKey: siteScanSuggestionsQueryOptions.queryKey }),
            ]);
        },
        onError: () => setLexiconNotice(__('The suggested terms could not be added. Try again in a moment.', 'translate')),
    });

    const completedSuggestions = scanState?.phase === 'done' ? scanState.lexiconSuggestions : undefined;
    useEffect(() =>
    {
        if (scanState?.phase === 'scanning')
        {
            setLexiconNotice(null);
            queryClient.setQueryData(siteScanSuggestionsQueryOptions.queryKey, { status: 'pending', lexiconSuggestions: [] });
        }
        else if (scanState?.phase === 'done')
        {
            void queryClient.invalidateQueries({ queryKey: siteScanSuggestionsQueryOptions.queryKey });
        }
    }, [scanState?.phase, completedSuggestions, queryClient]);
    useEffect(() => { if (suggestions.length > 0) setLexiconNotice(null); }, [suggestionKey]);

    const content = (<>
        { suggestions.length > 0 && (
            <div className="flex min-w-0 flex-col gap-3" data-lexicon-suggestions>
                <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                    <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <p className="text-sm font-medium text-translatewp-neutral! m-0">
                            {sprintf(
                                /* translators: %d: number of suggested terms. */
                                _n('The scan suggests %d term', 'The scan suggests %d terms', suggestions.length, 'translate'),
                                suggestions.length
                            )}
                        </p>
                        <span className="text-sm text-translatewp-muted!" aria-live="polite" aria-atomic="true">
                            {sprintf(
                                /* translators: %d: number of selected terms. */
                                __('%d selected', 'translate'), selectedTerms.length
                            )}
                        </span>
                    </div>
                    <div className="ms-auto flex items-center gap-2">
                        <button type="button" className="min-h-9 border-0 bg-transparent p-0 text-sm text-translatewp-primary! underline cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2" disabled={applyLexicon.isPending} onClick={() => setOmittedTerms([])}>
                            {__('Select all', 'translate')}
                        </button>
                        <span className="text-translatewp-muted!" aria-hidden="true">/</span>
                        <button type="button" className="min-h-9 border-0 bg-transparent p-0 text-sm text-translatewp-primary! underline cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2" disabled={applyLexicon.isPending} onClick={() => setOmittedTerms(suggestions.map(suggestion => suggestion.term))}>
                            {__('Select none', 'translate')}
                        </button>
                    </div>
                </div>
                <p className="text-sm text-translatewp-muted! m-0">
                    {__('Nothing is added until you choose. Unchecked terms are dismissed.', 'translate')}
                </p>
                <ul
                    id={`${suggestionId}-list`}
                    className={`${suggestionListClass} ${suggestions.length > 8 && !showAllSuggestions ? 'max-h-80 overflow-y-auto overscroll-contain' : ''}`}
                    aria-label={__('Suggested Lexicon terms', 'translate')}
                >
                    {suggestions.map((suggestion, index) => (
                        <li key={suggestion.term} className={`${suggestionRowClass} grid grid-cols-[32px_minmax(0,1fr)] items-start gap-x-3 gap-y-1 min-[783px]:grid-cols-[20px_minmax(8rem,2fr)_minmax(0,3fr)]`}>
                            <SuggestionCheckbox
                                id={`${suggestionId}-term-${index}`}
                                labelId={`${suggestionId}-label-${index}`}
                                reasonId={`${suggestionId}-reason-${index}`}
                                checked={!omittedTerms.includes(suggestion.term)}
                                disabled={applyLexicon.isPending}
                                onChange={checked => setOmittedTerms(previous => checked
                                    ? previous.filter(term => term !== suggestion.term)
                                    : [...previous, suggestion.term])}
                            />
                            <label id={`${suggestionId}-label-${index}`} htmlFor={`${suggestionId}-term-${index}`} className={`${suggestionTermClass} cursor-pointer`}>
                                {suggestion.term}
                            </label>
                            <p id={`${suggestionId}-reason-${index}`} className={`${suggestionReasonClass} col-start-2 min-[783px]:col-start-auto`}>
                                {suggestion.description}
                            </p>
                        </li>
                    ))}
                </ul>
                {suggestions.length > 8 && (
                    <button
                        type="button"
                        className="min-h-9 w-fit border-0 bg-transparent p-0 text-sm text-translatewp-primary! underline cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2"
                        aria-controls={`${suggestionId}-list`}
                        aria-expanded={showAllSuggestions}
                        onClick={() => setShowAllSuggestions(previous => !previous)}
                    >
                        {showAllSuggestions ? __('Show fewer terms', 'translate') : sprintf(
                            /* translators: %d: total number of suggested terms. */
                            __('Show all %d terms', 'translate'), suggestions.length
                        )}
                    </button>
                )}
                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        variant="primary"
                        className="rounded-sm! w-fit"
                        disabled={applyLexicon.isPending || selectedTerms.length === 0}
                        isBusy={applyLexicon.isPending}
                        onClick={() => applyLexicon.mutate(selectedTerms)}
                    >
                        {sprintf(
                            /* translators: %d: number of selected terms. */
                            _n('Add %d selected term', 'Add %d selected terms', selectedTerms.length, 'translate'),
                            selectedTerms.length
                        )}
                    </Button>
                    <Button variant="tertiary" className="rounded-sm!" disabled={applyLexicon.isPending} onClick={() => applyLexicon.mutate([])}>
                        {__('Dismiss all', 'translate')}
                    </Button>
                </div>
            </div>
        ) }

        { lexiconNotice && (
            <p className="text-sm text-translatewp-muted!" role="status">{ lexiconNotice }</p>
        ) }
    </>);
    if (!canAdminister() || (suggestions.length === 0 && !lexiconNotice)) return null;
    return framed ? <Card title={__('Site scan suggestions', 'translate')}>{content}</Card> : content;
};
