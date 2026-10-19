import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider, onlineManager } from '@tanstack/react-query';

(globalThis as Record<string, unknown>).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@wordpress/api-fetch', () => ({
    __esModule: true,
    default: jest.fn(),
}));

import apiFetch from '@wordpress/api-fetch';
import { LanguagesIndexing } from '../index';
import { ErrorBoundary } from '@/shared/components/ErrorBoundary';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import type { Language } from '@/shared/components/languageTable';

const apiFetchMock = apiFetch as unknown as jest.Mock;

// Built from the REST endpoints the PHP returns, so this suite breaks if the
// wire shape and the tab drift apart:
//   GET /translatewp/v1/indexing/status -> Indexer::status() + pin_details()
//   GET /translatewp/v1/indexing/pages  -> Indexing::pages()
const statusResponse = () => ({
    languages: {
        de: { total: 4, done: 1, dead: 1, pending_extract: 1, pending_push: 1, complete: false, reported: false },
    },
    drainScheduled: true,
    scheduledTasksLate: true,
    lastRun: 1731000000,
    failed: [
        { id: 9, language: 'de', url: 'https://example.test/broken', reason: 'page_fetch_403', objectType: 'page', objectId: 12, attempts: 3 },
    ],
    pins: [],
});

const pagesResponse = () => ({
    pages: [
        {
            objectType: 'page',
            objectId: 42,
            url: 'https://example.test/room',
            title: 'Room name',
            languages: { de: { id: 1, status: 'indexed', segments: 5, reason: '', updated: 1731000000, title: 'stored' } },
        },
        {
            objectType: 'page',
            objectId: 12,
            url: 'https://example.test/broken',
            title: 'Broken page',
            languages: { de: { id: 9, status: 'failed', segments: 0, reason: 'page_fetch_403', updated: 0, title: 'missing' } },
        },
    ],
    totals: { rows: 2, segments: 5 },
});

const originalTranslateWP = window.TranslateWP;
const pristineStoreState = useAdminSettingsStore.getState();

function installTranslateWP(): void
{
    Object.defineProperty(window, 'TranslateWP', {
        value: {
            context: {
                home_url: 'https://example.test',
                site_id: 'site-1',
                nonce: 'nonce-1',
                remote_api_base: 'https://api.example.test',
                translation_base_url: 'https://example.test/wp-json/translatewp/v1/translation',
            },
            lexicon_terms: [],
            host: { version: 1 },
        },
        configurable: true,
        writable: true,
    });

    const languages = [
        { locale: 'en', slug: 'en', active: true, default: true },
        { locale: 'de', slug: 'de', active: true, default: false },
    ] as unknown as Language[];

    useAdminSettingsStore.setState({
        isInitialized: true,
        isLoading: false,
        persistedSettings: { ...pristineStoreState.persistedSettings, 'languages.list': languages },
        settings: { ...pristineStoreState.settings, 'languages.list': languages },
    });
}

let container: HTMLDivElement;
let root: Root;

function paths(): string[]
{
    return apiFetchMock.mock.calls.map((call: [{ path: string }]) => call[0].path);
}

// Mount the real LanguagesIndexing inside a real QueryClientProvider, the way
// the admin app mounts it: no stubbed RetranslatePages, no mocked React Query.
async function renderTab(): Promise<void>
{
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);

    await act(async () =>
    {
        root.render(
            <QueryClientProvider client={queryClient}>
                <LanguagesIndexing />
            </QueryClientProvider>
        );
    });
    for (let i = 0; i < 6; i++)
    {
        await act(async () => { await Promise.resolve(); });
    }
}

beforeEach(() =>
{
    installTranslateWP();
    onlineManager.setOnline(true);
    apiFetchMock.mockReset();
    apiFetchMock.mockImplementation(async (options: { path: string }) =>
    {
        if (options.path.includes('/indexing/status')) return statusResponse();
        if (options.path.includes('/indexing/pages')) return pagesResponse();
        throw new Error(`Unexpected apiFetch path: ${options.path}`);
    });
});

afterEach(() =>
{
    onlineManager.setOnline(true);
    try { act(() => root.unmount()); } catch { /* already unmounted */ }
    container.remove();
    useAdminSettingsStore.setState(pristineStoreState, true);
    Object.defineProperty(window, 'TranslateWP', {
        value: originalTranslateWP,
        configurable: true,
        writable: true,
    });
});

describe('Indexing tab (real LanguagesIndexing + real React Query)', () =>
{
    it('renders every section from the mocked REST data', async () =>
    {
        await renderTab();

        const text = container.textContent ?? '';
        expect(text).toContain('Read my site');
        expect(text).toContain('Scheduled tasks are running late');
        expect(text).toContain('Waiting for extraction');
        expect(text).toContain('page_fetch_403');
        expect(text).toContain('Retranslate to try again');
        expect(text).toContain('Pages in');
        expect(text).toContain('Room name');

        expect(paths().filter(path => path.includes('/indexing/pages'))).toHaveLength(1);
    });

    it('fetches status once per mount plus once after the automatic extraction run (no render or refetch loop)', async () =>
    {
        await renderTab();

        // The 5s poll is never advanced here. The fixture has a row waiting for
        // extraction, so the tab makes one extraction attempt and refreshes the
        // status once after it; anything more would be a loop.
        expect(paths().filter(path => path.includes('/indexing/claim-extract'))).toHaveLength(1);
        expect(paths().filter(path => path.includes('/indexing/status'))).toHaveLength(2);
    });

    it('still fetches the indexed pages when React Query reports the browser offline', async () =>
    {
        // The live defect: navigator.onLine misreports offline, which paused the
        // default-networkMode query so /indexing/pages was never requested and
        // the Indexed pages table stayed blank. networkMode 'always' fixes it.
        onlineManager.setOnline(false);

        await renderTab();

        expect(paths().filter(path => path.includes('/indexing/pages'))).toHaveLength(1);
        expect(container.textContent ?? '').toContain('Room name');
    });
});

describe('Indexing tab error boundary', () =>
{
    it('shows a visible message instead of a blank tab when a child throws', async () =>
    {
        const Boom = (): never => { throw new Error('render exploded'); };

        container = document.createElement('div');
        document.body.appendChild(container);
        root = createRoot(container);

        await act(async () =>
        {
            root.render(
                <ErrorBoundary title="The Indexing tab could not load.">
                    <Boom />
                </ErrorBoundary>
            );
        });

        // React logs the caught error; consume it so jest-console stays green.
        expect(console).toHaveErrored();

        expect(container.querySelector('[role="alert"]')).not.toBeNull();
        expect(container.textContent ?? '').toContain('The Indexing tab could not load.');
        expect((container.textContent ?? '').trim()).not.toBe('');
    });
});
