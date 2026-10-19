import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import type { SetupTranslationProgress } from '@/shared/api/setupTranslationProgress';
import { TranslationProgressTable } from '@/apps/setup/components/translationStep/TranslationProgressTable';
import { useSetupStore } from '@/apps/setup/stores/setupStore';
import { completionSummaryText, formatElapsed } from '@/apps/setup/hooks/completionSummary';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockGetSetupTranslationProgress = jest.fn();
const mockRefreshReadiness = jest.fn();

jest.mock('@/shared/api/setupTranslationProgress', () => ({
    getSetupTranslationProgress: (...args: unknown[]) => mockGetSetupTranslationProgress(...args),
}));

jest.mock('@/shared/api/translationReadiness', () => ({
    refreshTranslationReadiness: (...args: unknown[]) => mockRefreshReadiness(...args),
}));

const row = (status: 'indexed', queueStatus: 'done') => ({
    id: 1, status, queueStatus, segments: 4, reason: '', updated: 1, title: 'stored' as const,
});

const finishedResponse = (): SetupTranslationProgress => ({
    status: { languages: { fr: { complete: true }, de: { complete: true } } },
    pages: {
        pages: [
            { objectType: 'page', objectId: 1, url: 'https://example.com/', title: 'Home', languages: { fr: row('indexed', 'done'), de: row('indexed', 'done') } },
            { objectType: 'page', objectId: 2, url: 'https://example.com/about', title: 'About', languages: { fr: row('indexed', 'done'), de: row('indexed', 'done') } },
        ],
        totals: { rows: 4, segments: 16 },
    },
});

let root: Root | null = null;

const renderTable = async (data: SetupTranslationProgress, languages: string[] = ['fr', 'de']): Promise<HTMLElement> =>
{
    mockGetSetupTranslationProgress.mockResolvedValue(data);
    mockRefreshReadiness.mockResolvedValue({ pages: data.pages, status: data.status, cursor: 0, language: '', hashes: [], subscription: null, translations: { translationsToday: 0, languageUsage: [], recentContent: [] } });
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    await act(async () =>
    {
        root?.render(
            <QueryClientProvider client={client}>
                <TranslationProgressTable languages={languages} />
            </QueryClientProvider>
        );
    });
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

    return container;
};

describe('the completion summary line', () =>
{
    beforeEach(() =>
    {
        mockGetSetupTranslationProgress.mockReset();
        mockRefreshReadiness.mockReset();
    });

    afterEach(async () =>
    {
        jest.restoreAllMocks();
        if (root) await act(async () => { root?.unmount(); });
        root = null;
        document.body.innerHTML = '';
    });

    it('formats the elapsed time under a minute in seconds, and at or over a minute in minutes and seconds', () =>
    {
        expect(formatElapsed(45_000)).toBe('45 s');
        expect(formatElapsed(165_000)).toBe('2 min 45 s');
    });

    it('reports the pages, languages and elapsed time, with plurals handled', () =>
    {
        expect(completionSummaryText({ pages: 1, languages: 1, elapsedMs: 9_000 })).toBe('1 page translated into 1 language in 9 s');
        expect(completionSummaryText({ pages: 2, languages: 2, elapsedMs: 165_000 })).toBe('2 pages translated into 2 languages in 2 min 45 s');
    });

    it('shows the summary line once the first run finishes, using the run start time the store recorded', async () =>
    {
        const start = 1_700_000_000_000;
        useSetupStore.setState({ spinePages: [], defaultLanguage: 'en-us', languagesSavedAt: start, firstRunFinishedAt: 0 });
        jest.spyOn(Date, 'now').mockReturnValue(start + 165_000);

        const container = await renderTable(finishedResponse());

        const summary = container.querySelector('[data-completion-summary]');
        expect(summary?.textContent).toContain('2 pages translated into 2 languages in 2 min 45 s');
        // 2 pages x 2 languages x 2.5 h = 10 working hours.
        expect(container.querySelector('[data-completion-comparison]')?.textContent).toBe('A translator would need about 1 day and 2 hours for the same work; you saved about 1 day and 2 hours.');
    });

    it('keeps the run duration on a later visit instead of the time since the languages were saved', async () =>
    {
        const start = 1_700_000_000_000;
        useSetupStore.setState({ spinePages: [], defaultLanguage: 'en-us', languagesSavedAt: start, firstRunFinishedAt: start + 45_000 });
        jest.spyOn(Date, 'now').mockReturnValue(start + 6 * 60 * 60 * 1000);

        const container = await renderTable(finishedResponse());

        expect(container.querySelector('[data-completion-summary]')?.textContent).toContain('2 pages translated into 2 languages in 45 s');
        expect(useSetupStore.getState().firstRunFinishedAt).toBe(start + 45_000);
    });

    it('shows no summary line while the run is still in progress', async () =>
    {
        useSetupStore.setState({ spinePages: [], defaultLanguage: 'en-us', languagesSavedAt: Date.now() });
        const data = finishedResponse();
        data.pages.pages[1].languages.fr = { id: 1, status: 'pending', queueStatus: 'claimed', segments: 0, reason: '', updated: 1, title: 'pending' };

        const container = await renderTable(data);

        expect(container.querySelector('[data-completion-summary]')).toBeNull();
    });
});

import { formatWorkingTime, humanComparisonText } from '@/apps/setup/hooks/completionSummary';

describe('the human comparison', () =>
{
    test('estimates a translator at 2.5 hours per page per language, in working weeks, days and hours', () =>
    {
        // 27 pages x 5 languages x 2.5 h = 337.5, about 338 h = 8 weeks, 2 days and 2 hours;
        // less the 3 minutes the run took, 337.45, about 337 h saved.
        expect(humanComparisonText({ pages: 27, languages: 5, elapsedMs: 172_000 })).toBe('A translator would need about 8 weeks, 2 days and 2 hours for the same work; you saved about 8 weeks, 2 days and 1 hour.');
        expect(humanComparisonText({ pages: 2, languages: 1, elapsedMs: 60_000 })).toBe('A translator would need about 5 hours for the same work; you saved about 5 hours.');
        expect(humanComparisonText({ pages: 0, languages: 3, elapsedMs: 1000 })).toBe('');
    });

    test.each([
        [3, '3 hours'],
        [1, '1 hour'],
        [8, '1 day'],
        [10, '1 day and 2 hours'],
        [40, '1 week'],
        [44, '1 week and 4 hours'],
        [176, '4 weeks and 2 days'],
        [180, '4 weeks, 2 days and 4 hours'],
    ])('writes %d working hours as "%s"', (hours, text) =>
    {
        expect(formatWorkingTime(hours)).toBe(text);
    });
});

import { progressLines } from '@/apps/setup/hooks/completionSummary';

describe('the running summary', () =>
{
    test('is two lines: the progress with its time, and the translator time for what is ready', () =>
    {
        expect(progressLines({ ready: 27, total: 54, pages: 28, languages: 5, elapsedMs: 667_000 })).toEqual([
            '27 of 54 page translations ready after 11 min 7 s (28 pages in 5 languages)',
            'A translator would need about 1 week, 3 days and 4 hours for those.',
        ]);
        expect(progressLines({ ready: 0, total: 54, pages: 28, languages: 5, elapsedMs: 5_000 })).toEqual([
            '0 of 54 page translations ready after 5 s (28 pages in 5 languages)',
            '',
        ]);
    });
});
