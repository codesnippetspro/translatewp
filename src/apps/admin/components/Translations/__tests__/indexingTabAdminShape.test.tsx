import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider, onlineManager } from '@tanstack/react-query';
import { addFilter } from '@wordpress/hooks';

(globalThis as Record<string, unknown>).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@wordpress/api-fetch', () => ({
    __esModule: true,
    default: jest.fn(),
}));

import apiFetch from '@wordpress/api-fetch';
import { installHostBridge } from '@/shared/host';
import { DashboardPage as Translations } from '@/apps/admin/components/Dashboard/DashboardPage';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

const apiFetchMock = apiFetch as unknown as jest.Mock;
const pristineStoreState = useAdminSettingsStore.getState();

// A sample /translatewp/v1/indexing/status payload shaped like the free-tier
// response. The locales include 'en-us', which the admin localizer never
// exposes through window.TranslateWP.
const statusResponse = () => ({
    languages: {
        it: { total: 636, done: 609, dead: 0, pending_extract: 27, pending_push: 0, complete: false, reported: false },
        de: { total: 643, done: 616, dead: 0, pending_extract: 27, pending_push: 0, complete: false, reported: false },
        'en-us': { total: 533, done: 506, dead: 0, pending_extract: 27, pending_push: 0, complete: false, reported: false },
    },
    drainScheduled: false,
    scheduledTasksLate: true,
    lastRun: 1789111965,
    failed: [],
    pins: [],
});

const pagesResponse = () => ({ pages: [], totals: { rows: 0, segments: 0 } });

// The PR #139 /translatewp/v1/indexing/pages shape: one entry per page with a
// per-locale languages map. Its locales match the sample status payload, so
// the Pages table must surface the same it/de/en-us the warm-up rows show.
const pagesWithRows = () => ({
    pages: [
        {
            objectType: 'page',
            objectId: 101,
            url: 'https://example.test/',
            title: 'Home page',
            languages: {
                it: { id: 1, status: 'indexed', segments: 12, reason: '', updated: 1789111000 },
                de: { id: 2, status: 'indexed', segments: 12, reason: '', updated: 1789111000 },
                'en-us': { id: 3, status: 'pending', segments: 0, reason: '', updated: 0 },
            },
        },
        {
            objectType: 'page',
            objectId: 102,
            url: 'https://example.test/about',
            title: 'About us',
            languages: {
                it: { id: 4, status: 'failed', segments: 0, reason: 'page_fetch_403', updated: 0 },
                de: { id: 5, status: 'indexed', segments: 8, reason: '', updated: 1789111000 },
                'en-us': { id: 6, status: 'indexed', segments: 8, reason: '', updated: 1789111000 },
            },
        },
    ],
    totals: { rows: 6, segments: 40 },
});

// The wp-admin runtime shape: the frontend-only TranslateWP.php localizer does
// not run here, so window.TranslateWP is created solely by installHostBridge and
// carries the host bridge but NO options. The Pro addon registers its panels
// through the same bridge, simulated here with the slot filters it adds.
function installAdminWindow(): void
{
    delete (window as { TranslateWP?: unknown }).TranslateWP;
    installHostBridge();

    const AddonPanel = () => null;
    addFilter('translatewp.slot.seo', 'translatewp-pro', () => AddonPanel);
    addFilter('translatewp.slot.aiProviders', 'translatewp-pro', () => AddonPanel);
    addFilter('translatewp.slot.style', 'translatewp-pro', () => AddonPanel);
    addFilter('translatewp.slot.tone', 'translatewp-pro', () => AddonPanel);
}

function setAdminHash(hash: string): void
{
    window.history.replaceState(null, '', `/wp-admin/admin.php?page=translatewp${hash}`);
}

let container: HTMLDivElement;
let root: Root;

function paths(): string[]
{
    return apiFetchMock.mock.calls.map((call: [{ path: string }]) => call[0].path);
}

async function renderTranslationsAtIndexing(): Promise<void>
{
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);

    await act(async () =>
    {
        root.render(
            <QueryClientProvider client={queryClient}>
                <Translations />
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
    useAdminSettingsStore.setState(pristineStoreState, true);
    installAdminWindow();
    setAdminHash('#indexing');
    onlineManager.setOnline(true);
    apiFetchMock.mockReset();
    apiFetchMock.mockImplementation(async (options: { path: string }) =>
    {
        if (options.path.includes('/indexing/status')) return statusResponse();
        if (options.path.includes('/indexing/pages')) return pagesResponse();
        return {};
    });
});

afterEach(() =>
{
    onlineManager.setOnline(true);
    try { act(() => root.unmount()); } catch { /* already unmounted */ }
    container.remove();
    setAdminHash('');
});

describe('Indexing tab on the wp-admin window shape (no TranslateWP.options)', () =>
{
    it('loads the tab instead of the error boundary and fetches status once, plus once after the automatic extraction run', async () =>
    {
        await renderTranslationsAtIndexing();

        const text = container.textContent ?? '';
        expect(text).not.toContain('The Indexing tab could not load.');
        expect(text).toContain('Reading and translating');
        expect(text).toContain('American English');

        expect(paths().filter(path => path.includes('/indexing/claim-extract'))).toHaveLength(1);
        expect(paths().filter(path => path.includes('/indexing/status'))).toHaveLength(2);
    });

    it('asks for language setup instead of offering the failing run when the store has no languages', async () =>
    {
        await renderTranslationsAtIndexing();

        const text = container.textContent ?? '';
        expect(text).toContain('Choose the languages first');
        expect(
            Array.from(container.querySelectorAll('button'))
                .some(button => button.textContent?.trim() === 'Read my site again')
        ).toBe(false);
    });

    it('builds the Pages table language switcher from the status payload, not the empty store', async () =>
    {
        apiFetchMock.mockImplementation(async (options: { path: string }) =>
        {
            if (options.path.includes('/indexing/status')) return statusResponse();
            if (options.path.includes('/indexing/pages')) return pagesWithRows();
            return {};
        });

        await renderTranslationsAtIndexing();

        const text = container.textContent ?? '';
        expect(text).not.toContain('The Indexing tab could not load.');
        // The heading names the first target language instead of rendering an
        // empty "Pages in ", and no search reports a false empty result.
        expect(text).toContain('Pages in Italian');
        expect(text).not.toContain('No pages match your filters.');

        // The switcher derives its languages from the status payload, including
        // 'en-us', which the admin localizer never exposes through the store.
        const switcher = container.querySelector('[role="tablist"][aria-label="Language"]');
        expect(switcher).not.toBeNull();
        const switcherLanguages = Array.from((switcher as HTMLElement).querySelectorAll('[role="tab"]'))
            .map(tab => (tab.textContent ?? '').trim());
        expect(switcherLanguages).toEqual(['Italian', 'German', 'American English']);

        // One row per page for the selected language renders in the Pages table.
        expect(text).toContain('Home page');
        expect(text).toContain('About us');
        expect(container.querySelector('a[href="https://example.test/"]')).not.toBeNull();
    });
});
