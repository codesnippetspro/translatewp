import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { TranslationsContext } from '@/apps/admin/components/Translations/Context';
import { applyScanLexicon } from '@/shared/api/setup';
import type { ScanPollState } from '@/shared/hooks/useContextScan';

const mockScan: { state: ScanPollState } = { state: { phase: 'idle' } };
jest.mock('@/shared/hooks/useContextScan', () => ({
    useContextScan: () => ({ state: mockScan.state, startScan: jest.fn() }),
    scanDoneMessage: () => '',
    scanProgressMessage: () => '',
}));
jest.mock('@/shared/api/setup', () => ({ applyScanLexicon: jest.fn() }));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
const apply = jest.mocked(applyScanLexicon);
let root: Root;
let client: QueryClient;
let container: HTMLDivElement;
const suggestions = ['Quillforge Atelier', 'Fernwick Ledger', 'Tidewell Nib'].map(term => ({ term, description: `Keep ${term} as written`, category: 'Brand' }));

const mount = async (terms = suggestions) =>
{
    mockScan.state = { phase: 'done', restored: true, context: '', result: { context: '' }, applied: true, pages: 3, lexiconSuggestions: terms };
    await act(async () => { root.render(<QueryClientProvider client={client}><TranslationsContext /></QueryClientProvider>); });
};
const button = (label: string) => Array.from(container.querySelectorAll('button')).find(each => each.textContent === label)!;
const click = async (element: HTMLElement) => { await act(async () => { element.click(); }); };
const flush = async () => { await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); }); };

beforeEach(() =>
{
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    client = new QueryClient({ defaultOptions: { mutations: { retry: false }, queries: { retry: false } } });
    apply.mockReset().mockImplementation(async terms => ({ added: terms?.length ?? 0 }));
});
afterEach(async () =>
{
    await act(async () => root.unmount());
    client.clear();
    container.remove();
});

it('starts checked with labelled descriptions, selects all/none and sends only the chosen names', async () =>
{
    await mount();
    const inputs = Array.from(container.querySelectorAll<HTMLInputElement>('input[type=checkbox]'));
    expect(inputs).toHaveLength(3);
    inputs.forEach((input, index) =>
    {
        expect(input.checked).toBe(true);
        expect(document.getElementById(input.getAttribute('aria-labelledby')!)?.textContent).toBe(suggestions[index].term);
        expect(document.getElementById(input.getAttribute('aria-describedby')!)?.textContent).toBe(suggestions[index].description);
        expect(input.classList.contains('w-4!')).toBe(false);
        expect(input.classList.contains('h-4!')).toBe(false);
    });
    expect(container.textContent).not.toContain('Show all 3 terms');
    await click(button('Select none'));
    expect(inputs.every(input => !input.checked)).toBe(true);
    expect(button('Add 0 selected terms').disabled).toBe(true);
    await click(button('Select all'));
    expect(inputs.every(input => input.checked)).toBe(true);
    expect(button('Add 3 selected terms').disabled).toBe(false);
    await click(inputs[1]);
    expect(container.textContent).toContain('2 selected');
    await click(button('Add 2 selected terms'));
    await flush();
    expect(apply.mock.calls[0][0]).toEqual(['Quillforge Atelier', 'Tidewell Nib']);
    expect(container.querySelector('[data-lexicon-suggestions]')).toBeNull();
});

it('dismisses every suggestion with an empty list and reports dismissal', async () =>
{
    await mount();
    await click(button('Dismiss all'));
    await flush();
    expect(apply.mock.calls[0][0]).toEqual([]);
    expect(container.textContent).toContain('Suggested terms were dismissed.');
    expect(container.querySelector('[data-lexicon-suggestions]')).toBeNull();
});

it('expands and collapses long lists with an accessible explicit control', async () =>
{
    await mount(Array.from({ length: 12 }, (_, index) => ({ term: `Name ${index}`, description: 'Brand name', category: 'Brand' })));
    const expand = button('Show all 12 terms');
    const list = document.getElementById(expand.getAttribute('aria-controls')!)!;
    expect(list.classList.contains('max-h-80')).toBe(true);
    expect(expand.getAttribute('aria-expanded')).toBe('false');
    await click(expand);
    expect(expand.textContent).toBe('Show fewer terms');
    expect(expand.getAttribute('aria-expanded')).toBe('true');
    expect(list.classList.contains('max-h-80')).toBe(false);
    await click(expand);
    expect(expand.getAttribute('aria-expanded')).toBe('false');
    expect(list.classList.contains('max-h-80')).toBe(true);
});

it('keeps the chosen list available for retry when applying fails', async () =>
{
    apply.mockRejectedValueOnce(new Error('Temporary failure'));
    await mount();
    await click(button('Add 3 selected terms'));
    await flush();
    expect(container.textContent).toContain('The suggested terms could not be added. Try again in a moment.');
    expect(container.querySelector('[data-lexicon-suggestions]')).not.toBeNull();
    await click(button('Add 3 selected terms'));
    await flush();
    expect(apply.mock.calls.map(call => call[0])).toEqual([suggestions.map(term => term.term), suggestions.map(term => term.term)]);
    expect(container.querySelector('[data-lexicon-suggestions]')).toBeNull();
});
