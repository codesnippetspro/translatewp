import { useEffect, useId, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { RefreshCcw } from "lucide-react";
import { Button } from "@wordpress/components";

import { useAdminSettingsStore, type TranslationsContext as TranslationsContextType } from '@/apps/admin/stores/settingsStore';
import type { ScanContextResponse } from '@/shared/api/setup';
import { scanDoneMessage, scanProgressMessage, useContextScan } from '@/shared/hooks/useContextScan';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { applyScanLexicon } from '@/shared/api/setup';

export const TranslationsContext = () =>
{
    const context = useAdminSettingsStore(state => state.settings['translations.context']);
    const setSetting = useAdminSettingsStore(state => state.setSetting);

    const updateContext = <K extends keyof TranslationsContextType,>(key: K, value: TranslationsContextType[K]) =>
    {
        setSetting('translations.context', previous =>
        {
            if (previous[key] === value) {
                return previous;
            }

            return {
                ...previous,
                [key]: value,
            };
        });
    };

    const updateStyle = (values: Pick<ScanContextResponse, 'style' | 'tone' | 'formality' | 'creativity'>) =>
    {
        setSetting('translations.style', previous =>
        {
            const next = {
                ...previous,
                type: values.style,
                tone: values.tone,
                formality: values.formality,
                creativity: values.creativity,
            };

            if (
                previous.type === next.type
                && previous.tone === next.tone
                && previous.formality === next.formality
                && previous.creativity === next.creativity
            ) {
                return previous;
            }

            return next;
        });
        // A scan's proposal carries its own formality: a tone it picks does not move the slider.
        setSetting('translations.style', previous => previous.formality === values.formality ? previous : { ...previous, formality: values.formality });
    };

    const lastScanDaysAgo = context.last_scan_time
        ? Math.floor((Date.now() / 1000 - context.last_scan_time) / 86400)
        : null;

    // The scan runs on the site's scheduled tasks and finishes later; the
    // form fills in from the polled result, with the time elapsed shown
    // while it runs and a plain reason when it fails.
    const { state: scanState, startScan } = useContextScan({ restoreStored: true });
    const isPending = scanState.phase === 'scanning';

    useEffect(() =>
    {
        if (scanState.phase !== 'done' || scanState.restored)
        {
            return;
        }
        updateContext('text_context', scanState.result.context);
        if (scanState.result.style && scanState.result.tone)
        {
            updateStyle({
                style: scanState.result.style,
                tone: scanState.result.tone,
                formality: scanState.result.formality ?? 5,
                creativity: scanState.result.creativity ?? 5,
            });
        }
        updateContext('last_scan_time', Math.floor(Date.now() / 1000));
        // The store setters are stable; only a new scan result re-runs this.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [scanState]);

    const scanWebsite = () =>
    {
        setLexiconNotice(null);
        applyLexicon.reset();
        void startScan();
    };

    // The scan proposes lexicon terms to a Pro site only
    // (a Free site gets none from the status route); they land only when asked.
    const queryClient = useQueryClient();
    const [lexiconNotice, setLexiconNotice] = useState<string | null>(null);
    const suggestions = scanState.phase === 'done' ? scanState.lexiconSuggestions : [];
    const [omittedTerms, setOmittedTerms] = useState<string[]>([]);
    const suggestionId = useId();
    const suggestionKey = JSON.stringify(suggestions.map(suggestion => suggestion.term));
    useEffect(() => { setOmittedTerms([]); }, [suggestionKey]);
    const selectedTerms = suggestions.filter(suggestion => !omittedTerms.includes(suggestion.term)).map(suggestion => suggestion.term);
    const applyLexicon = useMutation({
        mutationFn: applyScanLexicon,
        onSuccess: async (result, terms) =>
        {
            setLexiconNotice(terms?.length === 0 ? __('Suggested terms were dismissed.', 'translate') : sprintf(
                /* translators: %d: number of terms added. */
                __('%d suggested terms were added to the lexicon.', 'translate'),
                result.added,
            ));
            await queryClient.invalidateQueries({ queryKey: ['getWordPressLexiconTerms'] });
        },
        onError: () => setLexiconNotice(__('The suggested terms could not be added. Try again in a moment.', 'translate')),
    });

    return (
        <section
            className="rounded-lg border border-translatewp-border p-5 flex flex-col gap-4 self-stretch lg:col-span-2"
            data-translation-context
            aria-labelledby="twp-site-context"
        >
            <div className="flex flex-col gap-1">
                <h3 id="twp-site-context" className="text-sm! font-bold! text-translatewp-neutral! m-0!">
                    { __('Site context', 'translate') }
                </h3>
                <p className="text-sm text-translatewp-muted!">{ __('What the translation AI should know about your site: who it is for, what it sells, how it speaks. The scan writes this brief for you; edit it freely.', 'translate') }</p>
            </div>

            <textarea
                aria-label={ __('Site context', 'translate') }
                placeholder={ __('Share anything about your website that would help the AI understand your content, audience, and goals.', 'translate') }
                value={context.text_context}
                onChange={(event) => updateContext('text_context', event.target.value)}
                rows={6}
                className="block! h-[168px]! min-h-0! w-full! grow-0 resize-y rounded-sm! border! border-translatewp-border! bg-white! p-3! text-sm! leading-6! text-translatewp-neutral! shadow-none! focus:border-translatewp-primary! focus:shadow-none!"
                data-site-context
            />

            <div className="flex flex-col gap-4 border-t border-translatewp-border pt-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <div className="flex items-center gap-2">
                            <RefreshCcw className="w-4 h-4 text-translatewp-primary!" />
                            <span className="text-sm font-bold text-translatewp-neutral!">{ __('Scan the site for context', 'translate') }</span>
                        </div>
                        <p className="text-sm text-translatewp-muted! m-0">
                            {lastScanDaysAgo === null
                                ? __('No scan yet', 'translate')
                                : lastScanDaysAgo === 0
                                    ? __('Last scan today', 'translate')
                                    : sprintf(
                                        /* translators: %d: number of days since the last scan. */
                                        _n('Last scan %d day ago', 'Last scan %d days ago', lastScanDaysAgo, 'translate'),
                                        lastScanDaysAgo
                                    )}
                        </p>
                    </div>

                    <Button
                        variant="secondary"
                        className="rounded-sm!"
                        isBusy={isPending}
                        disabled={isPending}
                        onClick={scanWebsite}
                    >
                        { __('Scan website', 'translate') }
                    </Button>
                </div>

                { scanState.phase === 'scanning' && (
                    <p className="text-sm text-translatewp-muted!" role="status">
                        { scanProgressMessage(scanState.elapsedSeconds, scanState.progress) }
                    </p>
                ) }

                { scanState.phase === 'done' && !scanState.restored && (
                    <p className="text-sm text-translatewp-muted!" role="status">
                        { scanDoneMessage(scanState.pages, scanState.applied) }
                    </p>
                ) }

                { scanState.phase === 'failed' && (
                    <p className="text-sm text-translatewp-error" role="alert">
                        { scanState.message }
                    </p>
                ) }

                { suggestions.length > 0 && !applyLexicon.isSuccess && (
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
                            className={`m-0! list-none border-y border-translatewp-border p-0! ${suggestions.length > 8 ? 'max-h-80 overflow-y-auto overscroll-contain' : ''}`}
                            aria-label={__('Suggested Lexicon terms', 'translate')}
                        >
                            {suggestions.map((suggestion, index) => (
                                <li key={suggestion.term} className="m-0! grid grid-cols-[20px_minmax(0,1fr)] items-start gap-x-3 gap-y-1 border-b border-translatewp-border px-1 py-3 last:border-b-0 md:grid-cols-[20px_minmax(8rem,2fr)_minmax(0,3fr)]">
                                    <input
                                        id={`${suggestionId}-term-${index}`}
                                        type="checkbox"
                                        aria-labelledby={`${suggestionId}-label-${index}`}
                                        aria-describedby={`${suggestionId}-reason-${index}`}
                                        className="m-0! mt-0.5! h-4! w-4! cursor-pointer"
                                        checked={!omittedTerms.includes(suggestion.term)}
                                        disabled={applyLexicon.isPending}
                                        onChange={event =>
                                        {
                                            const checked = event.target.checked;
                                            setOmittedTerms(previous => checked
                                                ? previous.filter(term => term !== suggestion.term)
                                                : [...previous, suggestion.term]);
                                        }}
                                    />
                                    <label id={`${suggestionId}-label-${index}`} htmlFor={`${suggestionId}-term-${index}`} className="m-0 text-sm font-medium text-translatewp-neutral! cursor-pointer break-words">
                                        {suggestion.term}
                                    </label>
                                    <p id={`${suggestionId}-reason-${index}`} className="col-start-2 m-0 text-sm text-translatewp-muted! break-words md:col-start-auto">
                                        {suggestion.description}
                                    </p>
                                </li>
                            ))}
                        </ul>
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

            </div>
        </section>
    );
}
