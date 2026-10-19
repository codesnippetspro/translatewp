import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { jest } from '@jest/globals';

let mockLexiconResponse: unknown;
let mockStaleResponse: unknown;

jest.mock('@tanstack/react-query', () => ({
    queryOptions: (options: unknown) => options,
    useQuery: (options: { queryKey?: unknown[] }) => (
        Array.isArray(options?.queryKey) && options.queryKey[0] === 'getStaleTranslations'
            ? { data: mockStaleResponse, isFetching: false }
            : { data: mockLexiconResponse, isFetching: false }
    ),
    useMutation: () => ({ mutate: () => {}, isPending: false }),
    useQueryClient: () => ({ invalidateQueries: () => {} }),
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

const renderLexicon = async (): Promise<string> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () =>
    {
        root!.render(<Lexicon />);
    });
    return container.textContent ?? '';
};

// A term change marks nothing stale by itself. The page reports the
// pages translated under the previous terms and offers the retranslation
// only while some are waiting.
describe('Lexicon retranslate action', () =>
{
    afterEach(async () =>
    {
        await act(async () =>
        {
            root?.unmount();
        });
        root = null;
        document.body.innerHTML = '';
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
