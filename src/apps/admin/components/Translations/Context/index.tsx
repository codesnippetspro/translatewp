import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { RefreshCcw, ListPlus } from "lucide-react";
import { Button, TextareaControl } from "@wordpress/components";

import { ProBadge } from "@/shared/components/pro/ProBadge";
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
    const { state: scanState, startScan } = useContextScan();
    const isPending = scanState.phase === 'scanning';

    useEffect(() =>
    {
        if (scanState.phase !== 'done')
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
        void startScan();
    };

    // The scan proposes lexicon terms to a Pro site only
    // (a Free site gets none from the status route); they land only when asked.
    const queryClient = useQueryClient();
    const [lexiconNotice, setLexiconNotice] = useState<string | null>(null);
    const suggestions = scanState.phase === 'done' ? scanState.lexiconSuggestions : [];
    const applyLexicon = useMutation({
        mutationFn: applyScanLexicon,
        onSuccess: async (result) =>
        {
            setLexiconNotice(sprintf(
                /* translators: %d: number of terms added. */
                __('%d suggested terms were added to the lexicon.', 'translate'),
                result.added,
            ));
            await queryClient.invalidateQueries({ queryKey: ['getWordPressLexiconTerms'] });
        },
        onError: () => setLexiconNotice(__('The suggested terms could not be added. Try again in a moment.', 'translate')),
    });

    return (
        <div className="rounded-lg border border-translatewp-border p-5 flex flex-col gap-4 self-stretch" data-translation-context>
            <div className="flex flex-col gap-1">
                <h4 className="text-sm! font-bold! text-translatewp-neutral! m-0!">{ __('Site context', 'translate') }</h4>
                <p className="text-sm text-translatewp-muted!">{ __('What the translation AI should know about your site: who it is for, what it sells, how it speaks. The scan writes this brief for you; edit it freely.', 'translate') }</p>
            </div>

            <textarea
                aria-label={ __('Site context', 'translate') }
                placeholder={ __('Share anything about your website that would help the AI understand your content, audience, and goals.', 'translate') }
                value={context.text_context}
                onChange={(event) => updateContext('text_context', event.target.value)}
                rows={8}
                className="block! w-full! min-h-48 grow resize-y rounded-sm! border! border-translatewp-border! bg-white! p-3! text-sm! leading-6! text-translatewp-neutral! shadow-none! focus:border-translatewp-primary! focus:shadow-none!"
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

                { scanState.phase === 'done' && (
                    <p className="text-sm text-translatewp-muted!" role="status">
                        { scanDoneMessage(scanState.pages, scanState.applied) }
                    </p>
                ) }

                { scanState.phase === 'failed' && (
                    <p className="text-sm text-translatewp-error" role="alert">
                        { scanState.message }
                    </p>
                ) }

                { suggestions.length > 0 && !lexiconNotice && (
                    <div className="flex flex-col gap-3 rounded-sm border border-translatewp-border p-4">
                        <p className="text-sm text-translatewp-neutral! m-0">
                            { sprintf(
                                /* translators: %d: number of suggested terms. */
                                __('The scan suggests %d lexicon terms: %s', 'translate'),
                                suggestions.length,
                                suggestions.map(suggestion => suggestion.term).join(', ')
                            ) }
                        </p>
                        <p className="text-sm text-translatewp-muted! m-0">
                            { __('Nothing is added until you say so. Terms do not translate anything again by themselves.', 'translate') }
                        </p>
                        <Button
                            variant="secondary"
                            className="rounded-sm! w-fit"
                            disabled={applyLexicon.isPending}
                            isBusy={applyLexicon.isPending}
                            onClick={() => applyLexicon.mutate()}
                        >
                            { __('Add suggested terms', 'translate') }
                        </Button>
                    </div>
                ) }

                { lexiconNotice && (
                    <p className="text-sm text-translatewp-muted!" role="status">{ lexiconNotice }</p>
                ) }

            </div>
        </div>
    );
}
