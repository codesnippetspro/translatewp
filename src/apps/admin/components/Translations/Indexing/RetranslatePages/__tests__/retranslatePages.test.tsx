import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { jest } from '@jest/globals';

import type { IndexPageEntry } from '@/shared/api/admin';

const roomPage: IndexPageEntry = {
    objectType: 'page',
    objectId: 42,
    url: 'https://example.test/room',
    title: 'Room name',
    languages: {
        de: { id: 10, status: 'indexed', segments: 5, reason: '', updated: 1731000000, title: 'stored' },
        fr: { id: 11, status: 'indexed', segments: 7, reason: '', updated: 1731000000, title: 'stored' },
    },
};

// An indexed body whose title has not caught up: the defect this surfaces.
const titleLaggingPage: IndexPageEntry = {
    objectType: 'page',
    objectId: 44,
    url: 'https://example.test/about',
    title: 'About us',
    languages: {
        de: { id: 40, status: 'indexed', segments: 9, reason: '', updated: 1731000000, title: 'pending' },
    },
};

const kitchenPage: IndexPageEntry = {
    objectType: 'page',
    objectId: 43,
    url: 'https://example.test/kitchen',
    title: 'Kitchen name',
    languages: {
        de: { id: 20, status: 'failed', segments: 0, reason: 'page_fetch_403', updated: 0, title: 'missing' },
    },
};

const untitledPage: IndexPageEntry = {
    objectType: 'miss',
    objectId: 0,
    url: 'https://example.test/hall/back-rooms?foo=bar',
    title: '',
    languages: {
        de: { id: 30, status: 'pending', segments: 2, reason: '', updated: 0, title: 'missing' },
    },
};

let mockPages: IndexPageEntry[] = [roomPage, kitchenPage];

jest.mock('@tanstack/react-query', () =>
{
    const mutate = jest.fn();

    return {
        __mutate: mutate,
        queryOptions: (options: unknown) => options,
        useQuery: () => ({
            data: {
                pages: mockPages,
                totals: { rows: mockPages.length, segments: 0 },
            },
            isPending: false,
            isError: false,
        }),
        useMutation: () => ({ mutate, isPending: false, isError: false }),
        useQueryClient: () => ({ invalidateQueries: async () => {} }),
    };
});

jest.mock('@wordpress/api-fetch', () => ({
    __esModule: true,
    default: jest.fn(),
}));

import * as reactQuery from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import { RetranslatePages } from '@/apps/admin/components/Translations/Indexing/RetranslatePages';
import { getLanguageNameFromLocale } from '@/shared/lib/languages';

const mockMutate = (reactQuery as unknown as { __mutate: jest.Mock }).__mutate;
const apiFetchMock = apiFetch as unknown as jest.Mock;

const germanName = getLanguageNameFromLocale('de') ?? 'de';
const frenchName = getLanguageNameFromLocale('fr') ?? 'fr';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mountedRoots: Root[] = [];

// `null` here (rather than `undefined`, which a default parameter cannot
// distinguish from an omitted argument) selects the "status payload has not
// arrived yet" case and is translated to an omitted statusLocales prop below.
const renderComponent = async (
    targetLocales: string[] = ['de', 'fr'],
    statusLocales: string[] | null = targetLocales
): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    mountedRoots.push(root);
    await act(async () =>
    {
        root.render(
            <RetranslatePages
                targetLocales={targetLocales}
                statusLocales={null === statusLocales ? undefined : statusLocales}
            />
        );
    });
    return container;
};

const dataRows = (container: HTMLElement): HTMLTableRowElement[] =>
    Array.from(container.querySelectorAll('tbody tr'));

const buttonByText = (container: HTMLElement, text: string): HTMLButtonElement =>
{
    const button = Array.from(container.querySelectorAll('button'))
        .find(candidate => (candidate.textContent ?? '').trim() === text);
    expect(button).toBeDefined();
    return button as HTMLButtonElement;
};

const tabByText = (container: HTMLElement, text: string): HTMLElement =>
{
    const tab = Array.from(container.querySelectorAll('[role="tab"]'))
        .find(candidate => (candidate.textContent ?? '').trim() === text);
    expect(tab).toBeDefined();
    return tab as HTMLElement;
};

const openRowMenu = async (container: HTMLElement, titleFragment: string) =>
{
    const trigger = Array.from(container.querySelectorAll('button[aria-haspopup="menu"]'))
        .find(candidate => (candidate.getAttribute('aria-label') ?? '').includes(titleFragment));
    expect(trigger).toBeDefined();
    await act(async () => { (trigger as HTMLButtonElement).click(); });
};

const menuItemByText = (container: HTMLElement, text: string): HTMLButtonElement =>
{
    const item = Array.from(container.querySelectorAll('[role="menuitem"]'))
        .find(candidate => (candidate.textContent ?? '').trim() === text);
    expect(item).toBeDefined();
    return item as HTMLButtonElement;
};

const confirmDialog = (): HTMLElement | null => document.querySelector('[role="dialog"]');

const dialogButtonByText = (text: string): HTMLButtonElement =>
{
    const button = Array.from(document.querySelectorAll('[role="dialog"] button'))
        .find(candidate => (candidate.textContent ?? '').trim() === text);
    expect(button).toBeDefined();
    return button as HTMLButtonElement;
};

const changeSelect = async (container: HTMLElement, value: string) =>
{
    const select = container.querySelector('select') as HTMLSelectElement;
    expect(select).toBeDefined();
    const setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value')?.set;
    await act(async () =>
    {
        setter?.call(select, value);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });
};

describe('RetranslatePages', () =>
{
    beforeEach(() =>
    {
        mockPages = [roomPage, kitchenPage];
        apiFetchMock.mockReset();
        apiFetchMock.mockResolvedValue({});
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
        window.location.hash = '';
        mockPages = [roomPage, kitchenPage];
        mockMutate.mockClear();
    });

    it('shows one row per page for the selected language', async () =>
    {
        const container = await renderComponent();

        expect(dataRows(container)).toHaveLength(2);
        expect(container.textContent).toContain('Room name');
        expect(container.textContent).toContain('Kitchen name');
    });

    it('shows the selected language flag before the pages heading in one row', async () =>
    {
        const container = await renderComponent();
        const heading = [...container.querySelectorAll('h3')].find(h => /Pages in/.test(h.textContent ?? '')) as HTMLElement;
        const row = heading.parentElement as HTMLElement;
        const flag = row.querySelector('.lang-icon') as HTMLElement;

        expect(row.className).toContain('flex');
        expect(heading.className).not.toContain('flex');
        expect(flag).not.toBeNull();
        expect(flag.compareDocumentPosition(heading) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('shows a page being translated again as translating, never as indexed', async () =>
    {
        mockPages = [{
            ...roomPage,
            languages: { de: { id: 50, status: 'retranslating', segments: 5, reason: '', updated: 1731000000, title: 'stored' } },
        }];
        const container = await renderComponent();

        expect(container.textContent).toContain('Translating again');
        expect(container.textContent).not.toContain('Indexed');
    });

    it('flags an indexed page whose title has not caught up', async () =>
    {
        mockPages = [titleLaggingPage];
        const container = await renderComponent();

        expect(container.textContent).toContain('Indexed');
        expect(container.textContent).toContain('Title still translating');
    });

    it('shows the URL path, not the full URL, as the title of a page with no title', async () =>
    {
        mockPages = [untitledPage];
        const container = await renderComponent();

        const pageLink = container.querySelector('tbody td a') as HTMLAnchorElement;
        expect(pageLink).not.toBeNull();
        expect(pageLink.textContent).toBe('/hall/back-rooms');
        expect(pageLink.textContent).not.toContain('example.test');
    });

    it('changes the rows when the language switcher changes', async () =>
    {
        const container = await renderComponent();

        await act(async () => { tabByText(container, frenchName).click(); });

        expect(dataRows(container)).toHaveLength(1);
        expect(container.textContent).toContain('Room name');
        expect(container.textContent).not.toContain('Kitchen name');
    });

    it('filters the rows by status for the selected language', async () =>
    {
        const container = await renderComponent();

        await changeSelect(container, 'failed');

        expect(container.textContent).toContain('Kitchen name');
        expect(container.textContent).toContain('page_fetch_403');
        expect(container.textContent).not.toContain('Room name');

        await changeSelect(container, 'pending');

        expect(container.textContent).toContain('No pages match your filters.');
    });

    it('counts a page being translated again as pending', async () =>
    {
        mockPages = [roomPage, { ...kitchenPage, languages: { de: { ...kitchenPage.languages.de, status: 'retranslating', reason: '' } } }];
        const container = await renderComponent();

        await changeSelect(container, 'pending');

        expect(container.textContent).toContain('Kitchen name');
        expect(container.textContent).not.toContain('Room name');
        mockPages = [roomPage, kitchenPage];
    });

    it('opened from the Dashboard, shows the pages still pending in the first language that has any', async () =>
    {
        const frenchPending: IndexPageEntry = {
            ...untitledPage,
            objectType: 'page',
            objectId: 45,
            url: 'https://example.test/contact',
            title: 'Contact',
            languages: { fr: { id: 50, status: 'pending', segments: 3, reason: '', updated: 0, title: 'missing' } },
        };
        mockPages = [roomPage, frenchPending];
        window.location.hash = '#indexing/pending-pages';
        const scrollIntoView = jest.fn();
        Element.prototype.scrollIntoView = scrollIntoView;
        const container = await renderComponent();

        expect(scrollIntoView).toHaveBeenCalled();
        expect(container.querySelector('#pending-pages')).not.toBeNull();
        expect(dataRows(container)).toHaveLength(1);
        expect(container.textContent).toContain('Contact');
        expect(container.textContent).toContain(frenchName);
        expect(container.textContent).not.toContain('Room name');
        mockPages = [roomPage, kitchenPage];
    });

    it('opened from the Dashboard before the languages are known, still lands on the language with a pending page', async () =>
    {
        const frenchPending: IndexPageEntry = {
            ...untitledPage,
            objectType: 'page',
            objectId: 46,
            url: 'https://example.test/contact',
            title: 'Contact',
            languages: { fr: { id: 51, status: 'pending', segments: 3, reason: '', updated: 0, title: 'missing' } },
        };
        mockPages = [roomPage, frenchPending];
        window.location.hash = '#indexing/pending-pages';
        Element.prototype.scrollIntoView = jest.fn();
        const container = document.createElement('div');
        document.body.appendChild(container);
        const root = createRoot(container);
        mountedRoots.push(root);

        await act(async () => { root.render(<RetranslatePages targetLocales={[]} statusLocales={undefined} />); });
        await act(async () => { root.render(<RetranslatePages targetLocales={['de', 'fr']} statusLocales={['de', 'fr']} />); });

        expect(dataRows(container)).toHaveLength(1);
        expect(container.textContent).toContain('Contact');
        expect(container.textContent).toContain(frenchName);
    });

    it('says since when a page is translating, and that a queued page is waiting', async () =>
    {
        const now = Math.floor(Date.now() / 1000);
        mockPages = [
            { ...roomPage, languages: { de: { id: 60, status: 'pending', queueStatus: 'claimed', segments: 0, reason: '', updated: 0, title: 'missing', translatingSince: now - 12 * 60 } } },
            { ...kitchenPage, languages: { de: { id: 61, status: 'retranslating', queueStatus: 'done', segments: 4, reason: '', updated: now - 2 * 3600 - 5 * 60, title: 'stored', translatingSince: now - 2 * 3600 - 5 * 60 } } },
            { ...titleLaggingPage, languages: { de: { id: 62, status: 'pending', queueStatus: 'pending', segments: 0, reason: '', updated: 0, title: 'missing', translatingSince: 0 } } },
        ] as IndexPageEntry[];
        const container = await renderComponent(['de']);
        const rowText = (title: string) => dataRows(container).find(row => (row.textContent ?? '').includes(title))?.textContent ?? '';

        expect(rowText('Room name')).toContain('Translating since 12 min');
        expect(rowText('Kitchen name')).toContain('Translating again');
        expect(rowText('Kitchen name')).toContain('Translating since 2 h 5 min');
        expect(rowText('About us')).toContain('Waiting to be translated');
    });

    it('retranslates one page in the selected language through the row menu', async () =>
    {
        const container = await renderComponent();

        await openRowMenu(container, 'Room name');
        await act(async () => { menuItemByText(container, `Retranslate in ${germanName}`).click(); });
        await act(async () => { dialogButtonByText('Retranslate').click(); });

        expect(mockMutate).toHaveBeenCalledWith({
            objectType: 'page',
            objectId: 42,
            url: 'https://example.test/room',
            language: 'de',
        });
    });

    it('retranslates one page in every language without a language scope', async () =>
    {
        const container = await renderComponent();

        await openRowMenu(container, 'Room name');
        await act(async () => { menuItemByText(container, 'Retranslate in every language').click(); });
        await act(async () => { dialogButtonByText('Retranslate').click(); });

        expect(mockMutate).toHaveBeenCalledWith({
            objectType: 'page',
            objectId: 42,
            url: 'https://example.test/room',
        });
    });

    it('confirms with the segment count before retranslating one language, and cancelling stops it', async () =>
    {
        const container = await renderComponent();

        await openRowMenu(container, 'Room name');
        await act(async () => { menuItemByText(container, `Retranslate in ${germanName}`).click(); });

        expect(confirmDialog()?.textContent).toContain('5');

        await act(async () => { dialogButtonByText('Cancel').click(); });

        expect(mockMutate).not.toHaveBeenCalled();
        expect(confirmDialog()).toBeNull();
    });

    it('confirms the total segment count across languages for an every-language retranslate', async () =>
    {
        const container = await renderComponent();

        await openRowMenu(container, 'Room name');
        await act(async () => { menuItemByText(container, 'Retranslate in every language').click(); });

        expect(confirmDialog()?.textContent).toContain('12');

        await act(async () => { dialogButtonByText('Retranslate').click(); });

        expect(mockMutate).toHaveBeenCalledWith({
            objectType: 'page',
            objectId: 42,
            url: 'https://example.test/room',
        });
    });

    it('retries all failed pages for the selected language', async () =>
    {
        const container = await renderComponent();

        await act(async () => { buttonByText(container, 'Retry all failed').click(); });

        expect(apiFetchMock).toHaveBeenCalledWith(expect.objectContaining({
            path: '/translatewp/v1/indexing/retry-failed',
            data: { language: 'de' },
        }));
    });

    it('clears the failed pages for the selected language', async () =>
    {
        const container = await renderComponent();

        await act(async () => { buttonByText(container, 'Clear failed').click(); });

        expect(apiFetchMock).toHaveBeenCalledWith(expect.objectContaining({
            path: '/translatewp/v1/indexing/clear-failed',
            data: { language: 'de' },
        }));
    });

    it('extracts a failed page again through the row menu', async () =>
    {
        apiFetchMock.mockResolvedValue({ row: null });
        const container = await renderComponent();

        await openRowMenu(container, 'Kitchen name');
        await act(async () => { menuItemByText(container, 'Extract again').click(); });

        expect(apiFetchMock).toHaveBeenCalledWith(expect.objectContaining({
            path: '/translatewp/v1/indexing/reextract-page',
            data: { id: 20 },
        }));
    });

    it('shows a loading line, not the add-a-language message, before the status payload arrives', async () =>
    {
        const container = await renderComponent(['de', 'fr'], null);

        expect(container.textContent).toContain('Loading pages…');
        expect(container.textContent).not.toContain('Add a target language first');
        expect(container.querySelector('h3')).toBeNull();
    });

    it('shows a loading line even with no store-derived target locales yet', async () =>
    {
        const container = await renderComponent([], null);

        expect(container.textContent).toContain('Loading pages…');
        expect(container.textContent).not.toContain('Add a target language first');
    });

    it('shows the add-a-language message once status has arrived with no target languages', async () =>
    {
        const container = await renderComponent([], []);

        expect(container.textContent).not.toContain('Loading pages…');
        expect(container.textContent).toContain('Add a target language first');
        expect(container.querySelector('h3')?.textContent).toBe('Pages');
    });
});
