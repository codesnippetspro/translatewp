import { useEffect, useState } from '@wordpress/element';
import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { CheckCircle2, LoaderCircle } from 'lucide-react';

import type { IndexPageEntry } from '@/shared/api/admin';
import { getCountryFlagFromLocale, getLanguageNameFromLocale } from '@/shared/lib/languages';
import { readinessFigure, readinessSummary, translationCellState } from '@/shared/lib/setupTranslationProgress';
import { setupTranslationProgressQueryOptions } from '@/shared/query-options/setupTranslationProgress';
import { useSetupStore } from '@/apps/setup/stores/setupStore';
import { useTranslationReadinessState } from '@/shared/hooks/useTranslationReadiness';
import { completionSummaryText, humanComparisonText, progressLines } from '@/apps/setup/hooks/completionSummary';

type LanguageState = 'ready' | 'translating' | 'queued';

const languageName = (locale: string): string => getLanguageNameFromLocale(locale) || locale;

// A language is ready for visitors as soon as one of its spine pages is warm;
// the rest keep translating in the background.
// A chip ticks when every page of its language is ready, not at the first page.
const languageState = (pages: IndexPageEntry[], locale: string, readyPages: number, totalPages: number): LanguageState =>
{
    if (totalPages > 0 && readyPages >= totalPages) return 'ready';
    const states = pages.map(page => translationCellState(page.languages[locale]));

    return states.includes('translating') || readyPages > 0 ? 'translating' : 'queued';
};

const languageStateLabel = (state: LanguageState): string =>
{
    if (state === 'ready') return __('ready', 'translate');
    if (state === 'translating') return __('translating', 'translate');
    return __('queued', 'translate');
};

const ALMOST_THERE_PERCENT = 80;
// How far past the confirmed count the bar may creep while a run is quiet, and how fast.
const ANTICIPATION_MAX_PERCENT = 6;
const ANTICIPATION_STEP_MS = 1200;

/**
 * A bar that keeps moving between readiness updates: it creeps a few points
 * past the confirmed count, never past 99, and snaps to the count when it
 * arrives, so a quiet minute reads as progress rather than a stall.
 */
const ProgressBar = ({ percent, finished }: { percent: number; finished: boolean }) =>
{
    const [creep, setCreep] = useState(0);
    useEffect(() =>
    {
        setCreep(0);
        if (finished || percent >= 99) return undefined;
        const timer = window.setInterval(() => setCreep(previous => Math.min(ANTICIPATION_MAX_PERCENT, previous + 0.5)), ANTICIPATION_STEP_MS);
        return () => window.clearInterval(timer);
    }, [percent, finished]);

    const shown = finished ? 100 : Math.min(99, Math.max(percent, Math.min(99, percent + creep)));

    return (
        <div className='setup-translation-progress-bar' data-translation-bar={finished ? 'done' : 'running'}>
            <div
                className={`setup-translation-track${finished ? ' is-finished' : ''}`}
                role='progressbar'
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent}
                aria-label={__('Translation progress', 'translate')}
            >
                <span className={`setup-translation-fill${finished ? '' : ' is-running motion-reduce:animate-none'}`} style={{ width: `${shown}%` }} aria-hidden='true' />
            </div>
            <span className={`setup-translation-value${finished ? ' is-finished' : ''}`} data-translation-percent>{`${percent}%`}</span>
        </div>
    );
};

const LanguageChip = ({ pages, locale, readyPages, totalPages }: { pages: IndexPageEntry[]; locale: string; readyPages: number; totalPages: number }) =>
{
    const state = languageState(pages, locale, readyPages, totalPages);
    const label = sprintf(
        /* translators: 1: language name, 2: language state */
        __('%1$s: %2$s', 'translate'),
        languageName(locale),
        languageStateLabel(state)
    );

    return (
        <li className='setup-translation-language' aria-label={label} title={label}>
            <span aria-hidden='true'>{getCountryFlagFromLocale(locale)}</span>
            <span aria-hidden='true'>{languageName(locale)}</span>
            <span className={`setup-translation-status is-${state === 'ready' ? 'translated' : state}`} aria-hidden='true'>
                {state === 'ready'
                    ? <CheckCircle2 size={16} />
                    : <LoaderCircle size={16} className='animate-spin motion-reduce:animate-none' />}
            </span>
        </li>
    );
};

export const TranslationProgressTable = ({ languages: chosen, action = null }: { languages: string[]; action?: ReactNode }) =>
{
    // The wizard's own poll carries no counts, so the figure comes from the
    // live readiness status and survives every poll in between.
    const live = useTranslationReadinessState();
    const defaultLanguage = useSetupStore(state => state.defaultLanguage);
    const languagesSavedAt = useSetupStore(state => state.languagesSavedAt);
    const firstRunFinishedAt = useSetupStore(state => state.firstRunFinishedAt);
    const setFirstRunFinishedAt = useSetupStore(state => state.setFirstRunFinishedAt);
    const languages = chosen.filter(locale => locale !== defaultLanguage);
    const { data, isError } = useQuery(setupTranslationProgressQueryOptions(languages));
    const spinePages = useSetupStore(state => state.spinePages);

    if (!languages.length) {
        return <p className='text-sm! text-translatewp-muted!'>{__('No translation languages selected.', 'translate')}</p>;
    }

    // Setup promises its indexed pages; pages only a visitor opened translate
    // in the background and are listed on the Indexing page instead.
    const indexedPages = (data?.pages.pages ?? []).filter(page => page.indexed !== false);
    const pages = indexedPages.length ? indexedPages : spinePages;
    const cells = pages.flatMap(page => languages.map(locale => translationCellState(page.languages[locale])));
    // The same figure the Overview shows for the same status.
    const summary = readinessSummary(live.status ?? data?.status, pages, languages);
    const unconfirmed = live.unconfirmed || summary.unconfirmed;
    // Before any count is confirmed, a failed refresh says nothing about readiness.
    const percent = summary.percent;
    const finished = indexedPages.length > 0 && cells.every(state => state === 'translated' || state === 'failed');

    // The finish moment is stored once, so a later visit shows the run's own
    // duration and not the time since the languages were saved.
    useEffect(() =>
    {
        if (finished && firstRunFinishedAt === 0) setFirstRunFinishedAt(Date.now());
    }, [finished, firstRunFinishedAt, setFirstRunFinishedAt]);
    const elapsedMs = finished && firstRunFinishedAt > 0 && languagesSavedAt > 0 ? firstRunFinishedAt - languagesSavedAt : null;
    // A clock for the running figures, ticking while the run is on.
    const [now, setNow] = useState(() => Date.now());
    useEffect(() =>
    {
        if (finished) return undefined;
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, [finished]);
    // The running figure gives way to the completion line once the run ends.
    const showFigure = !(unconfirmed && !live.status) && elapsedMs === null;

    return (
        <div className='setup-translation-progress' role='status'>
            <ProgressBar percent={percent} finished={finished} />
            <h3 className={`font-semibold text-lg!${finished ? ' text-translatewp-success!' : ''}`}>
                {finished
                    ? __('Your translations are ready', 'translate')
                    : percent >= ALMOST_THERE_PERCENT
                        ? __('Almost there', 'translate')
                        : __('Your translations are processing in the background', 'translate')}
            </h3>
            {finished && elapsedMs !== null && (
                <>
                    <p className='m-0! text-sm! text-translatewp-muted!' data-completion-summary>
                        {completionSummaryText({ pages: pages.length, languages: languages.length, elapsedMs })}
                    </p>
                    <p className='m-0! max-w-[560px] text-sm! text-translatewp-muted!' data-completion-comparison>
                        {humanComparisonText({ pages: pages.length, languages: languages.length, elapsedMs })}
                    </p>
                </>
            )}
            {showFigure && (() =>
            {
                const [status, comparison] = languagesSavedAt > 0
                    ? progressLines({ ready: summary.ready, total: summary.total, pages: summary.pageCount, languages: summary.languageCount, elapsedMs: now - languagesSavedAt })
                    : [readinessFigure(summary), ''];
                return (
                    <>
                        <p className='m-0! text-sm! text-translatewp-muted!' data-readiness-figure>{status}</p>
                        {comparison !== '' && <p className='m-0! text-sm! text-translatewp-muted!' data-progress-summary>{comparison}</p>}
                    </>
                );
            })()}
            <ul className='setup-translation-languages'>
                {languages.map(locale => <LanguageChip key={locale} pages={pages} locale={locale} readyPages={summary.readyByLanguage[locale] ?? 0} totalPages={summary.totalByLanguage[locale] ?? 0} />)}
            </ul>
            <div className='setup-finish-callout' role='note' data-finish-callout>
                <CheckCircle2 size={20} className='setup-finish-callout-icon' aria-hidden='true' />
                <p className='m-0! flex-1 text-sm!'>
                    <strong>{__('You can finish setup now.', 'translate')}</strong>
                    {' '}
                    {finished
                        ? __('The Indexing tab on the Dashboard lists every page and its translations.', 'translate')
                        : __('The Indexing tab on the Dashboard shows progress for every page. Translation time depends on how much content your site has.', 'translate')}
                </p>
                {action && <div className='shrink-0 self-center'>{action}</div>}
            </div>
            {(isError || unconfirmed) && (
                <p className='text-xs! text-translatewp-muted!'>
                    {__('Live progress is reconnecting. Translation continues in the background.', 'translate')}
                </p>
            )}
        </div>
    );
};
