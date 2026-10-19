import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import type { SetupTranslationProgress } from '@/shared/api/setupTranslationProgress';
import { TranslationProgressTable } from '@/apps/setup/components/translationStep/TranslationProgressTable';
import { useSetupStore } from '@/apps/setup/stores/setupStore';
import { OverviewNextAction } from '@/apps/admin/components/Dashboard/OverviewNextAction';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockGetSetupTranslationProgress = jest.fn();
const mockRefreshReadiness = jest.fn();

jest.mock('@/shared/api/setupTranslationProgress', () => ({
    getSetupTranslationProgress: (...args: unknown[]) => mockGetSetupTranslationProgress(...args),
}));

jest.mock('@/shared/api/translationReadiness', () => ({
    refreshTranslationReadiness: (...args: unknown[]) => mockRefreshReadiness(...args),
}));

const mockFetchIndexStatus = jest.fn();
jest.mock('@/apps/admin/components/Translations/Indexing/extractionDriver', () => ({
    ...jest.requireActual('@/apps/admin/components/Translations/Indexing/extractionDriver'),
    fetchIndexStatus: (...args: unknown[]) => mockFetchIndexStatus(...args),
}));
jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: async () => ({ connected: true, connectionStatus: 'connected', addressChange: null }),
}));
jest.mock('@/shared/api/admin', () => ({
    ...jest.requireActual('@/shared/api/admin'),
    getStaleTranslations: async () => ({ lexicon: {}, localizer: {} }),
    getJobEvents: async () => ({ events: [] }),
}));
jest.mock('@/apps/admin/stores/settingsStore', () => ({
    useAdminSettingsStore: (selector: (state: unknown) => unknown) => selector({
        settings: { 'languages.list': [
            { locale: 'en-us', slug: 'en', active: true, default: true },
            { locale: 'fr', slug: 'fr', active: true, default: false },
            { locale: 'de', slug: 'de', active: true, default: false },
        ] },
    }),
}));

let root: Root | null = null;

const row = (status: 'pending' | 'indexed' | 'failed', queueStatus: 'pending' | 'claimed' | 'done' | 'dead', reason = '') => ({
    id: 1,
    status,
    queueStatus,
    segments: status === 'indexed' ? 4 : 0,
    reason,
    updated: 1,
    title: status === 'indexed' ? 'stored' as const : 'pending' as const,
});

const response = (terminal = false): SetupTranslationProgress => ({
    status: { languages: { fr: { complete: terminal }, de: { complete: terminal } } },
    pages: {
        pages: [
            {
                objectType: 'page', objectId: 1, url: 'https://example.com/', title: 'Home',
                languages: {
                    fr: terminal ? row('indexed', 'done') : row('pending', 'claimed'),
                    de: row('indexed', 'done'),
                },
            },
            {
                objectType: 'page', objectId: 2, url: 'https://example.com/pricing', title: 'Pricing',
                languages: {
                    fr: row('failed', 'dead', 'provider_unavailable'),
                    de: row('indexed', 'done'),
                },
            },
        ],
        totals: { rows: 4, segments: 12 },
    },
});

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

describe('TranslationProgressTable', () =>
{
    beforeEach(() => {
        mockGetSetupTranslationProgress.mockReset();
        useSetupStore.setState({ spinePages: [], defaultLanguage: 'en-us' });
    });

    afterEach(async () =>
    {
        jest.useRealTimers();
        if (root) await act(async () => { root?.unmount(); });
        root = null;
        document.body.innerHTML = '';
    });

    it('shows one background-processing status with the overall percentage and says setup can finish', async () =>
    {
        const container = await renderTable(response());

        expect(container.textContent).toContain('Your translations are processing in the background');
        expect(container.querySelector('[data-translation-percent]')?.textContent).toBe('66%');
        expect(container.querySelector('[role="progressbar"]')?.getAttribute('aria-valuenow')).toBe('66');
        expect(container.textContent).toContain('You can finish setup now');
        expect(container.querySelector('table')).toBeNull();
    });

    it('shows the finish-now callout with the content-size sentence', async () =>
    {
        const container = await renderTable(response());
        const callout = container.querySelector('[data-finish-callout]');

        expect(callout?.getAttribute('role')).toBe('note');
        expect(callout?.textContent).toContain('You can finish setup now. The Indexing tab on the Dashboard shows progress for every page.');
        expect(callout?.textContent).toContain('Translation time depends on how much content your site has.');
    });

    it('marks the finished bar with the success class', async () =>
    {
        const running = await renderTable(response());
        expect(running.querySelector('.setup-translation-track')?.classList.contains('is-finished')).toBe(false);
        await act(async () => { root?.unmount(); });
        root = null;

        const container = await renderTable(response(true));
        expect(container.querySelector('.setup-translation-track')?.classList.contains('is-finished')).toBe(true);
    });

    it('marks each language ready as soon as one of its pages is translated', async () =>
    {
        const container = await renderTable(response());

        expect(container.querySelector('[aria-label="German: ready"]')).not.toBeNull();
        expect(container.querySelector('[aria-label="French: translating"]')).not.toBeNull();
    });

    it('leaves out the default language and pages only a visitor opened', async () =>
    {
        const data = response();
        data.pages.pages[1].indexed = false;
        const container = await renderTable(data, ['en-us', 'fr', 'de']);

        expect(container.querySelector('[aria-label^="American English"]')).toBeNull();
        expect(container.querySelector('[data-translation-percent]')?.textContent).toBe('50%');
    });

    it('says the translations are ready once no page is still translating', async () =>
    {
        const data = response(true);
        data.pages.pages[1].languages.fr = row('indexed', 'done');
        const container = await renderTable(data);

        expect(container.textContent).toContain('Your translations are ready');
        expect(container.querySelector('[data-translation-percent]')?.textContent).toBe('100%');
    });

    it('reaches 100% when the only pages left over failed', async () =>
    {
        const container = await renderTable(response(true));

        expect(container.textContent).toContain('Your translations are ready');
        expect(container.querySelector('[data-translation-percent]')?.textContent).toBe('100%');
    });

    it('starts at 0% while the saved language rows are still being prepared', async () =>
    {
        const empty: SetupTranslationProgress = {
            status: { languages: {} },
            pages: { pages: [], totals: { rows: 0, segments: 0 } },
        };
        const container = await renderTable(empty);

        expect(container.querySelector('[data-translation-percent]')?.textContent).toBe('0%');
        expect(container.textContent).toContain('Your translations are processing in the background');
    });

    it('shows the same N of M as the Overview for the same status', async () =>
    {
        const language = (ready: number, readyTotal: number) => ({
            ready, readyTotal, readyComplete: ready >= readyTotal, total: readyTotal, done: ready, dead: 0,
            pending_extract: 0, pending_push: 0, complete: false, reported: false,
        });
        const status = {
            languages: { fr: language(0, 3), de: language(3, 3) },
            read: { pages: 3, pending: 0, failed: 0, segments: 12 },
            changedPages: 0, scheduledTasksLate: false, drainScheduled: false, lastRun: null, failed: [], pins: [],
        };
        const data = { ...response(), status } as SetupTranslationProgress;
        mockFetchIndexStatus.mockResolvedValue(status);

        const wizard = await renderTable(data);
        const overview = document.createElement('div');
        document.body.appendChild(overview);
        const overviewRoot = createRoot(overview);
        await act(async () =>
        {
            overviewRoot.render(
                <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
                    <OverviewNextAction />
                </QueryClientProvider>
            );
        });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        const wizardFigure = wizard.querySelector('[data-readiness-figure]')?.textContent;
        expect(wizardFigure).toBe('3 of 6 page translations ready (3 pages in 2 languages)');
        expect(overview.querySelector('[data-readiness-figure]')?.textContent).toBe(wizardFigure);
        expect(wizard.querySelector('[data-translation-percent]')?.textContent).toBe('50%');
        expect(overview.textContent).toContain('50% ready');
        await act(async () => { overviewRoot.unmount(); });
    });

    it('keeps the live readiness figure when the wizard polls between two readiness refreshes', async () =>
    {
        const language = (ready: number, readyTotal: number) => ({ complete: false, ready, readyTotal, total: readyTotal });
        const live = { languages: { fr: language(1, 5), de: language(4, 5) }, read: { pages: 5 } };
        const data = response();
        mockGetSetupTranslationProgress.mockResolvedValue({ ...data, status: { languages: {} } });
        mockRefreshReadiness.mockResolvedValue({ pages: data.pages, status: live, cursor: 0, language: '', hashes: [], subscription: null, translations: { translationsToday: 0, languageUsage: [], recentContent: [] } });
        const container = document.createElement('div');
        document.body.appendChild(container);
        root = createRoot(container);
        await act(async () =>
        {
            root?.render(
                <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
                    <TranslationProgressTable languages={['fr', 'de']} />
                </QueryClientProvider>
            );
        });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        const figure = () => container.querySelector('[data-readiness-figure]')?.textContent;
        expect(figure()).toBe('5 of 10 page translations ready (5 pages in 2 languages)');
        const polls = mockGetSetupTranslationProgress.mock.calls.length;
        const refreshes = mockRefreshReadiness.mock.calls.length;

        await act(async () => { await new Promise(resolve => setTimeout(resolve, 1200)); });

        expect(mockGetSetupTranslationProgress.mock.calls.length).toBeGreaterThan(polls);
        expect(mockRefreshReadiness.mock.calls.length).toBe(refreshes);
        expect(figure()).toBe('5 of 10 page translations ready (5 pages in 2 languages)');
        expect(container.querySelector('[data-translation-percent]')?.textContent).toBe('50%');
    });

    it('does not claim 0 ready when the first readiness request fails before any counts exist', async () =>
    {
        const data = response();
        data.pages.pages.forEach(page => { page.languages.de = row('pending', 'pending'); });
        mockGetSetupTranslationProgress.mockResolvedValue({ ...data, status: { languages: {} } });
        mockRefreshReadiness.mockRejectedValue(new Error('offline'));
        const container = document.createElement('div');
        document.body.appendChild(container);
        root = createRoot(container);
        await act(async () =>
        {
            root?.render(
                <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
                    <TranslationProgressTable languages={['fr', 'de']} />
                </QueryClientProvider>
            );
        });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 1200)); });

        expect(container.textContent).not.toMatch(/0 of \d+ page translations ready/);
        expect(container.textContent).toContain('Live progress is reconnecting. Translation continues in the background.');
    });

    it('keeps the percentage it showed when a readiness request fails', async () =>
    {
        const data = response();
        mockGetSetupTranslationProgress.mockResolvedValue({ ...data, status: { languages: {} } });
        mockRefreshReadiness.mockRejectedValue(new Error('offline'));
        const container = document.createElement('div');
        document.body.appendChild(container);
        root = createRoot(container);
        await act(async () =>
        {
            root?.render(
                <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
                    <TranslationProgressTable languages={['fr', 'de']} />
                </QueryClientProvider>
            );
        });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 1200)); });

        expect(container.textContent).toContain('Live progress is reconnecting.');
        expect(container.querySelector('[data-translation-percent]')?.textContent).toBe('66%');
    });

    it('stops polling after every page reaches a terminal state', async () =>
    {
        await renderTable(response(true));
        expect(mockGetSetupTranslationProgress).toHaveBeenCalledTimes(1);

        jest.useFakeTimers();
        await act(async () => { jest.advanceTimersByTime(1500); });

        expect(mockGetSetupTranslationProgress).toHaveBeenCalledTimes(1);
    });
});
