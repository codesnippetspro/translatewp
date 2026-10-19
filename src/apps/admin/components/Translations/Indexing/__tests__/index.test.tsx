import { TextEncoder } from 'node:util';
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

if (typeof globalThis.TextEncoder === 'undefined')
{
    (globalThis as Record<string, unknown>).TextEncoder = TextEncoder;
}

(globalThis as Record<string, unknown>).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@wordpress/api-fetch', () => ({
    __esModule: true,
    default: jest.fn(),
}));

// The sibling Indexed-pages table is a TanStack Query unit with its own test;
// stub it so this suite exercises only the header action area and notice.
jest.mock('../RetranslatePages', () => ({
    __esModule: true,
    RetranslatePages: () => null,
}));

import apiFetch from '@wordpress/api-fetch';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import type { Language } from '@/shared/components/languageTable';
import type { IndexStatus } from '../extractionDriver';
import { LanguagesIndexing } from '../index';

const apiFetchMock = apiFetch as unknown as jest.Mock;

const statusFixture = (scheduledTasksLate: boolean, readPending = 0, languagePendingExtract = 0, readPages = 3): IndexStatus => ({
    languages: languagePendingExtract > 0
        ? { de: { total: 3, done: 3 - languagePendingExtract, dead: 0, pending_extract: languagePendingExtract, pending_push: 0, complete: false, reported: false } }
        : {},
    read: { pages: readPages, pending: readPending, failed: 0, segments: 30 },
    drainScheduled: false,
    scheduledTasksLate,
    lastRun: null,
    failed: [],
    pins: [],
});

const originalTranslateWP = window.TranslateWP;
const pristineStoreState = useAdminSettingsStore.getState();

function installTranslateWP(): void
{
    Object.defineProperty(window, 'TranslateWP', {
        value: {
            context: {
                home_url: 'https://example.com',
                site_id: 'site-1',
                nonce: 'nonce-1',
                remote_api_base: 'https://api.example.test',
                translation_base_url: 'https://example.com/wp-json/translatewp/v1/translation',
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

async function renderTab(scheduledTasksLate: boolean, readPending = 0, languagePendingExtract = 0, readPages = 3): Promise<void>
{
    apiFetchMock.mockImplementation(async (options: { path: string }) =>
    {
        if (options.path.endsWith('/status')) return statusFixture(scheduledTasksLate, readPending, languagePendingExtract, readPages);
        if (options.path.endsWith('/claim-extract')) return { rows: [] };
        throw new Error(`Unexpected apiFetch path: ${options.path}`);
    });

    await mountTab();
}

async function mountTab(): Promise<void>
{
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);

    await act(async () =>
    {
        root.render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><LanguagesIndexing /></QueryClientProvider>);
    });
    await act(async () =>
    {
        await Promise.resolve();
    });
}

function buttonWithText(text: string): HTMLButtonElement | undefined
{
    return Array.from(container.querySelectorAll('button'))
        .find(button => button.textContent?.trim() === text) as HTMLButtonElement | undefined;
}

beforeEach(() =>
{
    installTranslateWP();
    apiFetchMock.mockReset();
});

afterEach(() =>
{
    act(() => root.unmount());
    container.remove();
    useAdminSettingsStore.setState(pristineStoreState, true);
    Object.defineProperty(window, 'TranslateWP', {
        value: originalTranslateWP,
        configurable: true,
        writable: true,
    });
});

describe('LanguagesIndexing header action area', () =>
{
    it('offers one primary "Read my site again" action and drops the old buttons', async () =>
    {
        await renderTab(false);

        expect(buttonWithText('Read my site again')).toBeDefined();
        expect(buttonWithText('Queue warm-up index')).toBeUndefined();
        expect(buttonWithText('Extract in this tab')).toBeUndefined();
    });

    it('removes "Sync now" from the button row', async () =>
    {
        await renderTab(false);

        expect(buttonWithText('Sync now')).toBeUndefined();
    });

    it('renders "Sync now" only inside the scheduled-tasks-late notice', async () =>
    {
        await renderTab(true);

        const notice = Array.from(container.querySelectorAll('[role="status"]'))
            .find(element => element.textContent?.includes('Scheduled tasks are running late'));
        expect(notice).toBeDefined();

        const syncNow = buttonWithText('Sync now');
        expect(syncNow).toBeDefined();
        expect(notice!.contains(syncNow!)).toBe(true);
    });
});

describe('LanguagesIndexing reading again', () =>
{
    const readCalls = () => apiFetchMock.mock.calls
        .filter(([options]) => String((options as { path: string }).path).endsWith('/read-site'));

    const click = async (element: HTMLElement | undefined): Promise<void> =>
    {
        await act(async () =>
        {
            element?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
            await Promise.resolve();
        });
    };

    it('reading again on a read site asks first', async () =>
    {
        await renderTab(false);

        await click(buttonWithText('Read my site again'));

        const dialog = document.querySelector('[role="dialog"]');
        expect(dialog).not.toBeNull();
        expect(dialog?.textContent).toContain('Are you sure');
        expect(dialog?.textContent).toMatch(/allowance/);
        expect(readCalls()).toHaveLength(0);

        await click(Array.from(dialog?.querySelectorAll('button') ?? []).find(button => button.textContent === 'Cancel') as HTMLElement | undefined);

        expect(document.querySelector('[role="dialog"]')).toBeNull();
        expect(readCalls()).toHaveLength(0);
    });

    it('a site never read is read at once, under the name "Read my site"', async () =>
    {
        await renderTab(false, 0, 0, 0);

        expect(buttonWithText('Read my site again')).toBeUndefined();
        await click(buttonWithText('Read my site'));

        expect(document.querySelector('[role="dialog"]')).toBeNull();
        expect(readCalls()).toHaveLength(1);
    });
});

describe('LanguagesIndexing before the site status is known', () =>
{
    const readCalls = () => apiFetchMock.mock.calls
        .filter(([options]) => String((options as { path: string }).path).endsWith('/read-site'));

    const readButton = (): HTMLButtonElement | undefined => buttonWithText('Read my site') ?? buttonWithText('Read my site again');

    const clickReadButton = async (): Promise<void> =>
    {
        await act(async () =>
        {
            readButton()?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
            await Promise.resolve();
        });
    };

    it('cannot start a read while the status is still loading', async () =>
    {
        apiFetchMock.mockImplementation((options: { path: string }) =>
        {
            if (options.path.endsWith('/status')) return new Promise(() => undefined);
            if (options.path.endsWith('/read-site')) return Promise.resolve({ total: 0, unit: 'pages' });
            return new Promise(() => undefined);
        });
        await mountTab();

        expect(readButton()).toBeDefined();
        expect(readButton()?.disabled).toBe(true);

        await clickReadButton();

        expect(readCalls()).toHaveLength(0);
    });

    it('cannot start a read when the status failed to load', async () =>
    {
        apiFetchMock.mockImplementation((options: { path: string }) =>
        {
            if (options.path.endsWith('/status')) return Promise.reject(new Error('status failed'));
            if (options.path.endsWith('/read-site')) return Promise.resolve({ total: 0, unit: 'pages' });
            return new Promise(() => undefined);
        });
        await mountTab();

        expect(readButton()?.disabled).toBe(true);

        await clickReadButton();

        expect(readCalls()).toHaveLength(0);
    });
});

describe('LanguagesIndexing waiting page reads', () =>
{
    const claimCalls = () => apiFetchMock.mock.calls
        .filter(([options]) => String((options as { path: string }).path).endsWith('/claim-extract'));

    it('reads pages waiting to be read without a click', async () =>
    {
        await renderTab(false, 1);
        await act(async () =>
        {
            await Promise.resolve();
        });

        expect(claimCalls().length).toBeGreaterThan(0);
    });

    it('extracts language rows waiting for extraction without a click', async () =>
    {
        await renderTab(false, 0, 2);
        await act(async () =>
        {
            await Promise.resolve();
        });

        expect(claimCalls().length).toBeGreaterThan(0);
    });

    it('starts no reading when no page is waiting', async () =>
    {
        await renderTab(false, 0);

        expect(claimCalls()).toHaveLength(0);
    });
});
