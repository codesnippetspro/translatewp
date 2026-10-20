import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockDriver = {
    fetchIndexStatus: jest.fn(),
    runReadSite: jest.fn(),
    startTranslation: jest.fn(),
    syncNow: jest.fn(),
    rereadChanged: jest.fn(),
    runExtractionLoop: jest.fn(),
    retryFailed: jest.fn(),
};
const mockApi = {
    getConnectStatus: jest.fn(),
    getStaleTranslations: jest.fn(),
    getJobEvents: jest.fn(),
    getRetranslationEstimate: jest.fn(),
    applyLexiconNow: jest.fn(),
    applyLocalizerNow: jest.fn(),
};

const defaultLanguages = [
    { locale: 'en-us', slug: 'en', active: true, default: true },
    { locale: 'de', slug: 'de', active: true, default: false },
];
let mockLanguages = defaultLanguages;

jest.mock('@/shared/hooks/useTranslationReadiness', () => ({ useTranslationReadiness: () => undefined }));
jest.mock('@/apps/admin/stores/settingsStore', () => ({
    useAdminSettingsStore: (selector: (state: unknown) => unknown) => selector({
        settings: { 'languages.list': mockLanguages },
    }),
}));
jest.mock('@/apps/admin/components/Translations/Indexing/extractionDriver', () => ({
    fetchIndexStatus: (...args: unknown[]) => mockDriver.fetchIndexStatus(...args),
    runReadSite: (...args: unknown[]) => mockDriver.runReadSite(...args),
    startTranslation: (...args: unknown[]) => mockDriver.startTranslation(...args),
    syncNow: (...args: unknown[]) => mockDriver.syncNow(...args),
    rereadChanged: (...args: unknown[]) => mockDriver.rereadChanged(...args),
    runExtractionLoop: (...args: unknown[]) => mockDriver.runExtractionLoop(...args),
    retryFailed: (...args: unknown[]) => mockDriver.retryFailed(...args),
}));
jest.mock('@/shared/api/quota', () => ({ getQuota: () => Promise.resolve({ percentAvailable: 60 }) }));
jest.mock('@/shared/api/connect', () => ({ getConnectStatus: (...args: unknown[]) => mockApi.getConnectStatus(...args) }));
jest.mock('@/shared/api/admin', () => ({
    getStaleTranslations: (...args: unknown[]) => mockApi.getStaleTranslations(...args),
    getJobEvents: (...args: unknown[]) => mockApi.getJobEvents(...args),
    getRetranslationEstimate: (...args: unknown[]) => mockApi.getRetranslationEstimate(...args),
    applyLexiconNow: (...args: unknown[]) => mockApi.applyLexiconNow(...args),
    applyLocalizerNow: (...args: unknown[]) => mockApi.applyLocalizerNow(...args),
}));

import { OverviewNextAction } from '@/apps/admin/components/Dashboard/OverviewNextAction';

const status = (overrides: Record<string, unknown>) => ({
    firstPassDone: false,
    languages: {},
    read: { pages: 0, pending: 0, failed: 0, segments: 0 },
    changedPages: 0,
    scheduledTasksLate: false,
    drainScheduled: false,
    lastRun: null,
    failed: [],
    pins: [],
    ...overrides,
});

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await act(async () =>
    {
        createRoot(container).render(
            <QueryClientProvider client={queryClient}>
                <OverviewNextAction />
            </QueryClientProvider>
        );
    });
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)); });
    return container;
};

describe('the Overview next action, resolved and rendered', () =>
{
    beforeEach(() =>
    {
        (window as { TranslateWP?: unknown }).TranslateWP = { context: { home_url: 'https://example.com/' } };
        mockApi.getConnectStatus.mockResolvedValue({ connected: true, connectionStatus: 'connected', addressChange: null });
        mockApi.getStaleTranslations.mockResolvedValue({ lexicon: {}, localizer: {} });
        mockApi.getJobEvents.mockResolvedValue({ events: [] });
    });

    afterEach(() =>
    {
        document.body.innerHTML = '';
        mockLanguages = defaultLanguages;
        jest.clearAllMocks();
    });

    it('runs the read from the Overview when nothing was read', async () =>
    {
        mockDriver.fetchIndexStatus.mockResolvedValue(status({ read: { pages: 0, pending: 0, failed: 0, segments: 0 } }));
        mockDriver.runReadSite.mockResolvedValue({ processed: 0, failed: 0 });
        mockDriver.startTranslation.mockResolvedValue({ queued: 0, status: status({}) });
        const container = await render();

        const button = Array.from(container.querySelectorAll('button')).find((element) => element.textContent === 'Read my site') ?? null;
        expect(button).not.toBeNull();
        await act(async () =>
        {
            button?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
            await new Promise((resolve) => setTimeout(resolve, 0));
        });
        expect(mockDriver.runReadSite).toHaveBeenCalledTimes(1);
    });

    it('marks a language ready on the Overview once any one of its pages is ready', async () =>
    {
        mockDriver.fetchIndexStatus.mockResolvedValue(status({
            read: { pages: 3, pending: 0, failed: 0, segments: 12 },
            languages: { de: { ready: 1, readyTotal: 3, readyComplete: false, total: 3, done: 1, dead: 0, pending_extract: 0, pending_push: 0, complete: false, reported: false } },
        }));
        const container = await render();

        const spans = Array.from(container.querySelectorAll('span'));
        expect(spans.some((element) => element.textContent === 'German')).toBe(true);
        expect(spans.some((element) => element.textContent === 'German…')).toBe(false);
    });

    const row = (overrides: Record<string, unknown>) => ({ ready: 0, readyTotal: 10, readyComplete: false, total: 10, done: 0, dead: 0, pending_extract: 0, pending_push: 0, repairing: 0, retranslating: 0, confirming: 0, translated: 0, complete: false, reported: false, ...overrides });
    const textOf = (container: HTMLElement) => container.textContent ?? '';

    it('says what is happening after the first pass instead of a first pass that never ends', async () =>
    {
        mockDriver.fetchIndexStatus.mockResolvedValue(status({
            firstPassDone: true,
            read: { pages: 10, pending: 0, failed: 0, segments: 40 },
            languages: { de: row({ ready: 7, pending_push: 3 }) },
        }));
        const container = await render();
        expect(textOf(container)).not.toContain('first pass');
        expect(textOf(container)).toContain('Translating 3 page translations');
    });

    it('names a repair and a retranslation after a change', async () =>
    {
        mockDriver.fetchIndexStatus.mockResolvedValue(status({
            firstPassDone: true,
            read: { pages: 10, pending: 0, failed: 0, segments: 40 },
            languages: { de: row({ ready: 8, pending_push: 2, repairing: 2 }) },
        }));
        let container = await render();
        expect(textOf(container)).toContain('Repairing 2 page translations');
        document.body.innerHTML = '';

        mockDriver.fetchIndexStatus.mockResolvedValue(status({
            firstPassDone: true,
            read: { pages: 10, pending: 0, failed: 0, segments: 40 },
            languages: { de: row({ ready: 4, pending_push: 6, retranslating: 6 }) },
        }));
        container = await render();
        expect(textOf(container)).toContain('Applying your change to German: 4 of 10 page translations');
        document.body.innerHTML = '';

        mockDriver.fetchIndexStatus.mockResolvedValue(status({
            firstPassDone: true,
            read: { pages: 10, pending: 0, failed: 0, segments: 40 },
            languages: Object.fromEntries(['de', 'fr', 'it', 'es'].map(locale => [locale, row({ ready: 5, pending_push: 5, retranslating: 5 })])),
        }));
        mockLanguages = [{ locale: 'en-us', slug: 'en', active: true, default: true }, ...['de', 'fr', 'it', 'es'].map(locale => ({ locale, slug: locale, active: true, default: false }))];
        container = await render();
        expect(textOf(container)).toContain('Applying your change to 4 languages: 20 of 40 page translations');
    });

    const linkTo = (container: HTMLElement, hash: string) =>
        Array.from(container.querySelectorAll('a')).find(link => (link.getAttribute('href') ?? '').endsWith(hash));

    it('while page translations are still pending, links to those pages on the Indexing tab', async () =>
    {
        const states = [
            { read: { pages: 3, pending: 0, failed: 0, segments: 12 }, languages: { de: row({ ready: 2, readyTotal: 3, total: 3 }) } },
            { firstPassDone: true, read: { pages: 10, pending: 0, failed: 0, segments: 40 }, languages: { de: row({ ready: 8, pending_push: 2, repairing: 2 }) } },
            { firstPassDone: true, read: { pages: 10, pending: 0, failed: 0, segments: 40 }, languages: { de: row({ ready: 4, pending_push: 6, retranslating: 6 }) } },
            { firstPassDone: true, read: { pages: 10, pending: 0, failed: 0, segments: 40 }, languages: { de: row({ ready: 7, pending_push: 3 }) } },
        ];
        for (const state of states)
        {
            mockDriver.fetchIndexStatus.mockResolvedValue(status(state));
            const container = await render();
            const link = linkTo(container, '#indexing/pending-pages');
            expect(link?.textContent).toBe('See the pages still pending');
            expect(linkTo(container, '#indexing')).toBeUndefined();
            const inProgressBlock = state !== states[0];
            expect(link?.classList.contains('self-end')).toBe(inProgressBlock);
            if (inProgressBlock) expect(link?.parentElement?.classList.contains('flex-col')).toBe(true);
            document.body.innerHTML = '';
        }
    });

    it('lists pages that could not be delivered with a Retry that re-queues them', async () =>
    {
        mockDriver.fetchIndexStatus.mockResolvedValue(status({
            firstPassDone: true,
            read: { pages: 10, pending: 0, failed: 0, segments: 40 },
            languages: { de: row({ ready: 8, dead: 2, readyComplete: true }) },
        }));
        mockDriver.retryFailed.mockResolvedValue({ retried: 2, status: status({ firstPassDone: true, languages: { de: row({ ready: 8, pending_push: 2 }) } }) });
        mockDriver.runExtractionLoop.mockResolvedValue({ processed: 2, failed: 0 });
        mockDriver.syncNow.mockResolvedValue({ status: status({ firstPassDone: true, languages: { de: row({ ready: 10, readyComplete: true }) } }) });
        mockDriver.fetchIndexStatus.mockResolvedValueOnce(status({
            firstPassDone: true,
            read: { pages: 10, pending: 0, failed: 0, segments: 40 },
            languages: { de: row({ ready: 8, dead: 2, readyComplete: true }) },
        }));
        const container = await render();
        expect(textOf(container)).toContain('2 page translations could not be delivered');
        const retry = Array.from(container.querySelectorAll('button')).find((element) => element.textContent === 'Retry') ?? null;
        expect(retry).not.toBeNull();
        await act(async () => { retry?.dispatchEvent(new MouseEvent('click', { bubbles: true })); await new Promise((resolve) => setTimeout(resolve, 0)); });
        expect(mockDriver.retryFailed).toHaveBeenCalledTimes(1);
        expect(mockDriver.runExtractionLoop).toHaveBeenCalledTimes(1);
        expect(mockDriver.syncNow).toHaveBeenCalledTimes(1);
    });

    const fullyTranslated = (locales: string[]) => status({
        read: { pages: 5, pending: 0, failed: 0, segments: 20 },
        languages: Object.fromEntries(locales.map((locale) => [locale, { ready: 5, readyTotal: 5, readyComplete: true, total: 5, done: 5, dead: 0, pending_extract: 0, pending_push: 0, complete: true, reported: true }])),
    });

    it('offers a real preview link once everything is read and translated', async () =>
    {
        mockDriver.fetchIndexStatus.mockResolvedValue(fullyTranslated(['de']));
        const container = await render();

        expect(container.textContent).toContain('Preview in');
        const link = Array.from(container.querySelectorAll('a')).find((element) => element.textContent?.includes('German'));
        expect(link).toBeDefined();
        expect(link?.getAttribute('href')).toContain('lang=de');
    });

    it('the preview row shows a flag button for every translation language and no underlined link', async () =>
    {
        mockLanguages = [...defaultLanguages, { locale: 'fr', slug: 'fr', active: true, default: false }];
        mockDriver.fetchIndexStatus.mockResolvedValue(fullyTranslated(['de', 'fr']));
        const container = await render();

        const links = Array.from(container.querySelectorAll('a'));
        expect(links.map((link) => new URL(link.getAttribute('href') ?? '').searchParams.get('lang'))).toEqual(['de', 'fr']);
        links.forEach((link) =>
        {
            expect(link.querySelector('.lang-icon')).not.toBeNull();
            expect(link.classList.contains('components-button')).toBe(true);
            expect(link.classList.contains('underline')).toBe(false);
        });
        expect(links[0].textContent).toBe('German');
        expect(links[1].textContent).toBe('French');
    });

    it('with more than five translation languages the preview row shows flags only, the name on hover', async () =>
    {
        const extra = ['fr', 'es', 'it', 'pt', 'nl'];
        mockLanguages = [...defaultLanguages, ...extra.map(locale => ({ locale, slug: locale, active: true, default: false }))];
        mockDriver.fetchIndexStatus.mockResolvedValue(fullyTranslated(['de', ...extra]));
        const container = await render();

        const row = container.querySelector('[data-preview-style]') as HTMLElement;
        expect(row.getAttribute('data-preview-style')).toBe('flags');
        const links = Array.from(row.querySelectorAll('a'));
        expect(links).toHaveLength(6);
        links.forEach((link) =>
        {
            expect(link.querySelector('.lang-icon')).not.toBeNull();
            expect(link.textContent?.trim()).toBe('');
            expect(link.getAttribute('aria-label')).toMatch(/^Preview in /);
        });
    });

    it('the overview offers no re-read on a fully translated site', async () =>
    {
        mockDriver.fetchIndexStatus.mockResolvedValue(fullyTranslated(['de']));
        const container = await render();

        expect(container.textContent).not.toMatch(/re-?read/i);
        expect(container.querySelector('a[href*="indexing"]')).toBeNull();
    });
});
