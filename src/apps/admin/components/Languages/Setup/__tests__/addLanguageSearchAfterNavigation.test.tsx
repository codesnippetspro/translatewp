import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

jest.mock('@tanstack/react-query', () => ({
    useQuery: () => ({ data: { pro: { entitled: false } }, isLoading: false }),
    useMutation: () => ({ mutate: () => undefined, isPending: false }),
    useQueryClient: () => ({ invalidateQueries: async () => undefined }),
    queryOptions: (options: unknown) => options,
}));
jest.mock('@/shared/query-options/connect', () => ({
    getConnectStatusQueryOptions: () => ({ queryKey: ['connectStatus'] }),
}));

import { SettingsPage as Languages } from '@/apps/admin/components/SettingsPage';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import type { Language } from '@/shared/components/languageTable';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const SEARCH_QUERY = 'french';
const DEBOUNCE_WAIT = 260;

const mountedRoots: Root[] = [];

const setHash = (hash: string) =>
    window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}${hash}`);

const seedLanguages = (languages: Language[]) =>
{
    const current = useAdminSettingsStore.getState().settings;
    useAdminSettingsStore.setState({ isInitialized: false } as never);
    useAdminSettingsStore.getState().initializeSettings({
        ...current,
        'languages.list': languages,
    });
    useAdminSettingsStore.setState({ isLoading: false } as never);
};

const renderLanguages = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    mountedRoots.push(root);
    await act(async () =>
    {
        root.render(<Languages />);
    });
    return container;
};

const flush = async (ms = 0) =>
{
    await act(async () =>
    {
        await new Promise((resolve) => setTimeout(resolve, ms));
    });
};

const navigateToPage = async (page: string) =>
{
    await act(async () =>
    {
        setHash(`#${page}`);
        window.dispatchEvent(new Event('hashchange'));
    });
    await flush();
};

const clickButtonByText = async (label: string) =>
{
    const button = Array.from(document.querySelectorAll('button'))
        .find(candidate => (candidate.textContent ?? '').trim() === label);
    expect(button).toBeDefined();
    await act(async () =>
    {
        (button as HTMLButtonElement).click();
    });
    await flush();
};

const typeInSearch = async (query: string) =>
{
    const input = document.querySelector('[role="dialog"] input[type="text"]') as HTMLInputElement;
    expect(input).not.toBeNull();
    await act(async () =>
    {
        input.dispatchEvent(new Event('focusin', { bubbles: true }));
    });
    const nativeSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')!.set!;
    await act(async () =>
    {
        nativeSetter.call(input, query);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await flush(DEBOUNCE_WAIT);
};

const searchResultCount = () =>
{
    // The list is always open; a result is a checkbox row.
    return document.querySelectorAll('[role="dialog"] input[type="checkbox"]').length;
};

describe('Add-language search after navigating between settings pages', () =>
{
    beforeEach(() =>
    {
        setHash('');
        seedLanguages([{ locale: 'en-us', slug: 'en', active: true, default: true }]);
    });

    afterEach(async () =>
    {
        await act(async () =>
        {
            for (const root of mountedRoots.splice(0)) {
                root.unmount();
            }
        });
        document.body.innerHTML = '';
        setHash('');
    });

    it('still returns results after the Languages page has been navigated away and back', async () =>
    {
        const container = await renderLanguages();

        const addLanguages = () => Array.from(container.querySelectorAll('button'))
            .find(button => button.textContent?.trim() === 'Add languages');
        const initialAddButton = addLanguages();
        expect(initialAddButton).toBeDefined();

        await navigateToPage('switcher');
        expect(addLanguages()).toBeUndefined();
        expect(initialAddButton?.isConnected).toBe(false);

        await navigateToPage('languages');
        expect(addLanguages()).toBeDefined();
        expect(addLanguages()).not.toBe(initialAddButton);

        await clickButtonByText('Add languages');
        expect(container.querySelector('[role="dialog"]')).not.toBeNull();

        await typeInSearch(SEARCH_QUERY);

        expect(searchResultCount()).toBeGreaterThan(0);
        expect(document.body.textContent).not.toContain('Could not load the language list');
    });
});
