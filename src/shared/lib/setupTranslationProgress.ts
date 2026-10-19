import { __, _n, sprintf } from '@wordpress/i18n';
import type { IndexPageEntry, IndexPageLanguage } from '@/shared/api/admin';
import type { SetupTranslationProgress } from '@/shared/api/setupTranslationProgress';

export type TranslationCellState = 'queued' | 'translating' | 'translated' | 'failed';

export const translationCellState = (language?: IndexPageLanguage): TranslationCellState =>
{
    if (!language) return 'queued';
    if (language.status === 'failed') return 'failed';
    if (language.status === 'indexed') return 'translated';
    if (language.status === 'retranslating') return 'translating';

    return language.queueStatus === 'claimed' || language.queueStatus === 'done' ? 'translating' : 'queued';
};

export const languageReady = (readyPageCount: number): boolean => readyPageCount > 0;

export const hasOpenTranslationWork = (
    progress: SetupTranslationProgress | undefined,
    languages: string[]
): boolean =>
{
    if (!languages.length) return false;
    if (!progress || !progress.pages.pages.length) return true;

    return progress.pages.pages.some(page =>
        languages.some(locale =>
        {
            const state = translationCellState(page.languages[locale]);
            return state === 'queued' || state === 'translating';
        })
    );
};

export type ReadinessStatus = {
    languages?: Record<string, { ready?: number; readyTotal?: number; total?: number } | undefined>;
    read?: { pages: number };
    unconfirmed?: boolean;
};

export type ReadinessSummary = {
    ready: number;
    total: number;
    percent: number;
    pageCount: number;
    languageCount: number;
    /** Page translations ready per language, for the per-language ready mark. */
    readyByLanguage: Record<string, number>;
    /** Page translations counted per language, so each language has its own percent. */
    totalByLanguage: Record<string, number>;
    unconfirmed: boolean;
};

/**
 * The one readiness figure the wizard and the Overview share, counted
 * in page translations (one page in one translation language). The server's
 * per-language `ready` over `readyTotal` (queue rows minus dead rows) is the
 * figure; the page cells stand in only until the first readiness status
 * arrives, leaving out failed cells so they cannot hold it below 100.
 */
export const readinessSummary = (
    status: ReadinessStatus | undefined,
    pages: IndexPageEntry[],
    languages: string[]
): ReadinessSummary =>
{
    const fromStatus = languages.some(locale => typeof status?.languages?.[locale]?.ready === 'number');
    const readyByLanguage: Record<string, number> = {};
    const totalByLanguage: Record<string, number> = {};
    let ready = 0;
    let total = 0;

    languages.forEach(locale =>
    {
        if (fromStatus) {
            const row = status?.languages?.[locale];
            readyByLanguage[locale] = row?.ready ?? 0;
            totalByLanguage[locale] = row?.readyTotal ?? row?.total ?? 0;
        } else {
            const cells = pages.map(page => translationCellState(page.languages[locale]));
            readyByLanguage[locale] = cells.filter(state => state === 'translated').length;
            totalByLanguage[locale] = cells.filter(state => state !== 'failed').length;
        }
        ready += readyByLanguage[locale];
        total += totalByLanguage[locale];
    });

    return {
        ready,
        total,
        percent: total > 0 ? Math.floor((100 * ready) / total) : 0,
        pageCount: status?.read?.pages ?? pages.length,
        languageCount: languages.length,
        readyByLanguage,
        totalByLanguage,
        unconfirmed: status?.unconfirmed === true,
    };
};

/** 0 to 100 for one language; 0 before anything is counted. */
export const languagePercent = (summary: ReadinessSummary, locale: string): number =>
{
    const total = summary.totalByLanguage[locale] ?? 0;
    return total > 0 ? Math.min(100, Math.floor((100 * (summary.readyByLanguage[locale] ?? 0)) / total)) : 0;
};

export const readinessFigure = (summary: ReadinessSummary): string =>
    sprintf(
        /* translators: 1: page translations ready, 2: page translations in total, 3: "N pages", 4: "N languages". */
        __('%1$d of %2$d page translations ready (%3$s in %4$s)', 'translate'),
        summary.ready,
        summary.total,
        /* translators: %d: number of pages. */
        sprintf(_n('%d page', '%d pages', summary.pageCount, 'translate'), summary.pageCount),
        /* translators: %d: number of translation languages. */
        sprintf(_n('%d language', '%d languages', summary.languageCount, 'translate'), summary.languageCount)
    );
