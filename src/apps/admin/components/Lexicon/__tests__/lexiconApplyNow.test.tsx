import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { jest } from '@jest/globals';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { deleteWordPressLexiconTerm } from '@/shared/api/wordpress';

let mockLexiconResponse: unknown;
let mockStaleResponse: unknown;
let mockSiteScanResponse: unknown;
const mockInvalidateQueries = jest.fn<() => Promise<void>>().mockResolvedValue(undefined);

jest.mock('@tanstack/react-query', () => ({
    ...jest.requireActual<typeof import('@tanstack/react-query')>('@tanstack/react-query'),
    queryOptions: (options: unknown) => options,
    useQuery: (options: { queryKey?: unknown[] }) => (
        Array.isArray(options?.queryKey) && options.queryKey[0] === 'connectStatus'
            ? { data: { connected: true, pro: { entitled: true } } }
            : Array.isArray(options?.queryKey) && options.queryKey[0] === 'siteScanSuggestions'
            ? { data: mockSiteScanResponse, isFetching: false }
            : Array.isArray(options?.queryKey) && options.queryKey[0] === 'getStaleTranslations'
            ? { data: mockStaleResponse, isFetching: false }
            : { data: mockLexiconResponse, isFetching: false }
    ),
    useQueryClient: () => ({ invalidateQueries: mockInvalidateQueries }),
}));

jest.mock('@/shared/api/wordpress', () => ({
    ...jest.requireActual<typeof import('@/shared/api/wordpress')>('@/shared/api/wordpress'),
    deleteWordPressLexiconTerm: jest.fn(),
}));
jest.mock('@/shared/components/confirmDialog/useConfirmDialog', () => ({
    useConfirmDialog: () => ({ requestConfirmation: async () => true, confirmDialogProps: { isOpen: false } }),
}));

jest.mock('@/apps/admin/components/Lexicon/AddLexiconDrawer', () => ({
    AddLexiconDrawer: () => null,
}));

import { Lexicon } from '@/apps/admin/components/Lexicon';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const pagination = (total: number) => ({
    page: 1,
    total,
    total_pages: 1,
    has_prev: false,
    has_next: false,
});

const term = (id: number) => ({ id, term: `Term ${id}`, languages: [], categories: [] });

let root: Root | null = null;
let client: QueryClient;

const renderLexicon = async (): Promise<string> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    await act(async () =>
    {
        root!.render(<QueryClientProvider client={client}><Lexicon /></QueryClientProvider>);
    });
    return container.textContent ?? '';
};

// A term change marks nothing stale by itself. The page reports the
// pages translated under the previous terms and offers the retranslation
// only while some are waiting.
describe('Lexicon retranslate action', () =>
{
    beforeEach(() => { mockSiteScanResponse = undefined; mockInvalidateQueries.mockClear(); });
    afterEach(async () =>
    {
        await act(async () =>
        {
            root?.unmount();
        });
        root = null;
        client.clear();
        document.body.innerHTML = '';
    });

    it('refreshes site-scan suppression after deleting a term from its row', async () =>
    {
        mockLexiconResponse = { items: [term(7)], pagination: pagination(1) };
        mockStaleResponse = { lexicon: {}, localizer: {} };
        jest.mocked(deleteWordPressLexiconTerm).mockResolvedValue({ id: 7, success: true });
        await renderLexicon();
        const remove = document.querySelector<HTMLButtonElement>('button[aria-label="Delete lexicon term"]')!;
        expect(remove).not.toBeNull();
        await act(async () => { remove.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(deleteWordPressLexiconTerm).toHaveBeenCalledWith(7);
        expect(mockInvalidateQueries).toHaveBeenCalledWith({ queryKey: ['siteScanSuggestions'] });
        expect(mockInvalidateQueries).toHaveBeenCalledWith({ queryKey: ['getWordPressLexiconTerms'] });
    });

    it('shows stored site-scan suggestions inside the Lexicon page', async () =>
    {
        mockLexiconResponse = { items: [term(1)], pagination: pagination(1) };
        mockStaleResponse = { lexicon: {}, localizer: {} };
        mockSiteScanResponse = { status: 'done', lexiconSuggestions: [
            { term: 'Quillforge', description: 'Keep the brand as written', category: 'Brand' },
        ] };
        const text = await renderLexicon();
        expect(text).toContain('Suggested terms');
        expect(text).not.toContain('Site scan suggestions');
        expect(text).toContain('1 suggestion to review.');
        expect(text).toContain('Keep the brand as written');
        expect(text).toContain('Add selected (1)');
        expect(document.querySelector<HTMLInputElement>('[data-candidate] input[type="checkbox"]')?.checked).toBe(true);
    });

    it('offers nothing while every translated page uses the current terms', async () =>
    {
        mockLexiconResponse = { items: [term(1)], pagination: pagination(1) };
        mockStaleResponse = { lexicon: {}, localizer: {} };

        const text = await renderLexicon();

        expect(text).toContain('Add term');
        expect(text).not.toContain('Retranslate with current terms');
        expect(text).not.toContain('still use the previous terms');
    });

    it('counts the waiting pages per language and offers the retranslation', async () =>
    {
        mockLexiconResponse = { items: [term(1)], pagination: pagination(1) };
        mockStaleResponse = { lexicon: { de: 3, it: 2 }, localizer: { de: 9 } };

        window.TranslateWP = { ...window.TranslateWP, can: { edit: false, retranslate: true, settings: false, lexicon: true } } as typeof window.TranslateWP;
        const text = await renderLexicon();

        expect(text).toContain('Retranslate with current terms');
        expect(text).toContain('5 translated pages in German, Italian still use the previous terms');
    });

    it('says which pages wait but offers no retranslation to someone who may not retranslate', async () =>
    {
        mockLexiconResponse = { items: [term(1)], pagination: pagination(1) };
        mockStaleResponse = { lexicon: { de: 3, it: 2 }, localizer: { de: 9 } };
        window.TranslateWP = { ...window.TranslateWP, can: { edit: false, retranslate: false, settings: false, lexicon: true } } as typeof window.TranslateWP;

        const text = await renderLexicon();

        expect(text).not.toContain('Retranslate with current terms');
        expect(text).toContain('5 translated pages in German, Italian still use the previous terms');
    });

    it('counts the languages instead of listing them past three', async () =>
    {
        mockLexiconResponse = { items: [term(1)], pagination: pagination(1) };
        mockStaleResponse = { lexicon: { de: 3, it: 2, fr: 1, es: 1 }, localizer: {} };

        const text = await renderLexicon();

        expect(text).toContain('7 translated pages in 4 languages still use the previous terms');
    });
});
