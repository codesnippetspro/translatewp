import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { SiteScanSuggestions } from '@/shared/components/siteScanSuggestions';
import { applyScanLexicon, getScanContextStatus } from '@/shared/api/setup';
import { siteScanSuggestionsQueryOptions } from '@/shared/query-options/siteScanSuggestions';

jest.mock('@/shared/api/setup', () => ({ applyScanLexicon: jest.fn(), getScanContextStatus: jest.fn() }));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
const suggestions = [
    { term: 'Quillforge', description: 'Keep the brand as written', category: 'Brand' },
    { term: 'Tidewell', description: 'A product name', category: 'Product' },
];
const read = jest.mocked(getScanContextStatus);
const apply = jest.mocked(applyScanLexicon);
let root: Root;
let client: QueryClient;
let container: HTMLDivElement;
let originalData: typeof window.TranslateWP;
const flush = async () => { await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); }); };
const click = async (element: HTMLElement) => { await act(async () => { element.click(); }); await flush(); };
const button = (scope: Element, label: string) => Array.from(scope.querySelectorAll('button')).find(each => each.textContent === label)!;
const mount = async () =>
{
    await act(async () => {
        root.render(<QueryClientProvider client={client}>
            <section data-view="context"><SiteScanSuggestions /></section>
            <section data-view="lexicon"><SiteScanSuggestions framed /></section>
        </QueryClientProvider>);
    });
    await flush();
};

beforeEach(() =>
{
    originalData = window.TranslateWP;
    read.mockReset().mockResolvedValue({ status: 'done', lexiconSuggestions: suggestions });
    apply.mockReset();
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
});
afterEach(async () =>
{
    await act(async () => { root.unmount(); });
    client.clear();
    container.remove();
    window.TranslateWP = originalData;
});

it.each(['context', 'lexicon'])('consuming terms in the %s view clears both consumers and refreshes manual terms', async view =>
{
    apply.mockImplementation(async terms => {
        read.mockResolvedValue({ status: 'done', lexiconSuggestions: [] });
        return { added: terms?.length ?? 0 };
    });
    const invalidate = jest.spyOn(client, 'invalidateQueries');
    await mount();
    expect(container.querySelectorAll('[data-lexicon-suggestions]')).toHaveLength(2);
    expect(read).toHaveBeenCalled();
    read.mock.calls.forEach(call => expect(call).toEqual([{ readOnly: true }]));
    const scope = container.querySelector(`[data-view="${view}"]`)!;
    await click(button(scope, 'Add 2 selected terms'));
    await flush();
    expect(apply.mock.calls[0][0]).toEqual(['Quillforge', 'Tidewell']);
    expect(container.querySelectorAll('[data-lexicon-suggestions]')).toHaveLength(0);
    expect(client.getQueryData(siteScanSuggestionsQueryOptions.queryKey)).toMatchObject({ lexiconSuggestions: [] });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['getWordPressLexiconTerms'] });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: siteScanSuggestionsQueryOptions.queryKey });
    expect(scope.textContent).toContain('2 suggested terms were added to the lexicon.');
});

it('dismissal consumes both lists without adding any term', async () =>
{
    apply.mockImplementation(async () => {
        read.mockResolvedValue({ status: 'done', lexiconSuggestions: [] });
        return { added: 0 };
    });
    await mount();
    const scope = container.querySelector('[data-view="lexicon"]')!;
    await click(button(scope, 'Dismiss all'));
    await flush();
    expect(apply.mock.calls[0][0]).toEqual([]);
    expect(container.querySelectorAll('[data-lexicon-suggestions]')).toHaveLength(0);
    expect(scope.textContent).toContain('Suggested terms were dismissed.');
});

it('keeps an unchecked term omitted when a failed application is retried', async () =>
{
    apply.mockRejectedValue(new Error('Unavailable'));
    await mount();
    const scope = container.querySelector('[data-view="lexicon"]')!;
    const inputs = scope.querySelectorAll<HTMLInputElement>('input[type="checkbox"]');
    await click(inputs[1]);
    await click(button(scope, 'Add 1 selected term'));
    expect(scope.textContent).toContain('The suggested terms could not be added. Try again in a moment.');
    expect(inputs[1].checked).toBe(false);
    expect(container.querySelectorAll('[data-lexicon-suggestions]')).toHaveLength(2);
    await click(button(scope, 'Add 1 selected term'));
    expect(apply.mock.calls.map(call => call[0])).toEqual([['Quillforge'], ['Quillforge']]);
});

it('does not read or display suggestions without administration permission', async () =>
{
    window.TranslateWP = { ...window.TranslateWP, context: {
        ...window.TranslateWP?.context, can_administer: false,
    } } as typeof window.TranslateWP;
    await mount();
    expect(read).not.toHaveBeenCalled();
    expect(container.querySelector('[data-lexicon-suggestions]')).toBeNull();
    expect(apply).not.toHaveBeenCalled();
});
