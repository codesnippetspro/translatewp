import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { LanguageChecklist, LanguageMultiSelect, SelectedLanguageChips } from '@/shared/components/languageMultiselect';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

type Props = Parameters<typeof LanguageMultiSelect>[0];

const render = async (props: Partial<Props> & { onChange: Props['onChange'] }): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    await act(async () =>
    {
        createRoot(container).render(
            <LanguageMultiSelect selected={props.selected ?? []} excludeLocales={props.excludeLocales} onChange={props.onChange} />
        );
    });
    return container;
};

const setInputValue = async (input: HTMLInputElement, value: string) =>
{
    await act(async () =>
    {
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
        setter?.call(input, value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
};

describe('LanguageMultiSelect', () =>
{
    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    it('shows the whole list open with a checkbox per language, without typing', async () =>
    {
        const container = await render({ onChange: () => {} });
        expect(container.querySelectorAll('input[type="checkbox"]').length).toBeGreaterThan(20);
        expect(container.querySelector('[aria-label^="Remove "]')).toBeNull();
    });

    it('leaves out languages already on the site', async () =>
    {
        const all = await render({ onChange: () => {} });
        const total = all.querySelectorAll('input[type="checkbox"]').length;
        document.body.innerHTML = '';
        const fewer = await render({ onChange: () => {}, excludeLocales: ['ja', 'pt-br'] });
        expect(fewer.querySelectorAll('input[type="checkbox"]').length).toBeLessThan(total);
    });

    it('adds a language to the selection on toggle and shows removable chips', async () =>
    {
        let selection: string[] = [];
        const container = await render({ selected: [], onChange: (next) => { selection = next; } });
        const box = container.querySelector('input[type="checkbox"]') as HTMLInputElement;
        await act(async () => { box.click(); });
        expect(selection).toHaveLength(1);

        document.body.innerHTML = '';
        let next: string[] = ['ar', 'hi'];
        const counted = await render({ selected: ['ar', 'hi'], onChange: (value) => { next = value; } });
        const chips = counted.querySelectorAll('[aria-label^="Remove "]');
        expect(chips.length).toBe(2);
        await act(async () => { (chips[0] as HTMLButtonElement).click(); });
        expect(next).toHaveLength(1);
    });

    it('narrows the list as the filter is typed and never hides it', async () =>
    {
        const container = await render({ onChange: () => {} });
        const input = container.querySelector('input[type="text"]') as HTMLInputElement;
        const before = container.querySelectorAll('input[type="checkbox"]').length;
        await setInputValue(input, 'japan');
        const after = container.querySelectorAll('input[type="checkbox"]').length;
        expect(after).toBeGreaterThan(0);
        expect(after).toBeLessThan(before);

        const clear = container.querySelector('[aria-label="Clear filter"]') as HTMLButtonElement;
        expect(clear).not.toBeNull();
        await act(async () => { clear.click(); });
        expect(container.querySelectorAll('input[type="checkbox"]').length).toBe(before);
        expect(container.querySelector('[aria-label="Clear filter"]')).toBeNull();
    });
});

describe('LanguageMultiSelect compact form', () =>
{
    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    it('keeps the list at one fixed height and scrolls the chips so a card holding it never grows', async () =>
    {
        const container = document.createElement('div');
        document.body.appendChild(container);
        await act(async () =>
        {
            createRoot(container).render(
                <LanguageMultiSelect selected={['de', 'it', 'fr', 'es', 'ja', 'pt-br', 'nl', 'pl']} onChange={() => {}} compact />
            );
        });
        const list = container.querySelector('[role="group"]') as HTMLElement;
        expect(list.className).toContain('h-56');
        expect(list.className).not.toContain('max-h-[60vh]');
        const chips = container.querySelector('[aria-live="polite"]') as HTMLElement;
        expect(chips.className).toContain('overflow-y-auto');
    });
});

describe('LanguageMultiSelect composition', () =>
{
    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    it('renders selected chips and the checklist as independent controlled pieces', async () =>
    {
        const container = document.createElement('div');
        document.body.appendChild(container);
        let selection = ['fr'];

        await act(async () =>
        {
            createRoot(container).render(
                <>
                    <SelectedLanguageChips selected={selection} onChange={(next) => { selection = next; }} />
                    <LanguageChecklist selected={selection} onChange={(next) => { selection = next; }} compact />
                </>
            );
        });

        expect(container.querySelector('[aria-label="Remove French"]')).not.toBeNull();
        expect(container.querySelector('[role="group"][aria-label="Languages"]')).not.toBeNull();
        const french = container.querySelector('input[aria-label="French"]') as HTMLInputElement;
        expect(french.checked).toBe(true);

        await act(async () => { french.click(); });
        expect(selection).toEqual([]);
    });

    it('keeps the wrapper chips between its filter and checklist', async () =>
    {
        const container = await render({ selected: ['fr'], onChange: () => {} });
        const root = container.firstElementChild as HTMLElement;
        expect(root.children[0].querySelector('input[type="text"]')).not.toBeNull();
        expect(root.children[1].getAttribute('aria-live')).toBe('polite');
        expect(root.children[2].getAttribute('role')).toBe('group');
    });
});
