import { act, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { LanguageScopeSwitcher, type ScopeOption } from '@/shared/components/LanguageScopeSwitcher';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const makeOptions = (count: number): ScopeOption[] =>
    Array.from({ length: count }, (_, index) => ({ value: `lang-${index}`, label: `Language ${index}` }));

const Harness = ({ options, onSelect }: { options: ScopeOption[]; onSelect?: (value: string) => void }) =>
{
    const [selected, setSelected] = useState(options[0]?.value ?? '');
    return (
        <LanguageScopeSwitcher
            options={options}
            selected={selected}
            onSelect={(value) => { setSelected(value); onSelect?.(value); }}
            label="Language"
        />
    );
};

const mount = async (ui: React.ReactElement) =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    await act(async () => { root.render(ui); });
    return {
        container,
        unmount: async () => { await act(async () => { root.unmount(); }); },
    };
};

const typeInto = (input: HTMLInputElement, value: string) =>
{
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')?.set;
    setter?.call(input, value);
    input.dispatchEvent(new Event('input', { bubbles: true }));
};

afterEach(() => { document.body.innerHTML = ''; });

describe('LanguageScopeSwitcher', () =>
{
    it('renders tabs at or below the threshold', async () =>
    {
        const { container, unmount } = await mount(<Harness options={makeOptions(6)} />);

        expect(container.querySelectorAll('[role=tab]')).toHaveLength(6);
        expect(container.querySelector('[role=combobox]')).toBeNull();

        await unmount();
    });

    it('renders a searchable select past the threshold and filters by type-ahead', async () =>
    {
        const onSelect = jest.fn();
        const { container, unmount } = await mount(<Harness options={makeOptions(12)} onSelect={onSelect} />);

        expect(container.querySelectorAll('[role=tab]')).toHaveLength(0);
        const input = container.querySelector('[role=combobox]') as HTMLInputElement;
        expect(input).not.toBeNull();

        await act(async () => { typeInto(input, 'Language 1'); });

        const labels = Array.from(container.querySelectorAll('[role=option]')).map((option) => option.textContent);
        expect(labels).toEqual(expect.arrayContaining(['Language 1', 'Language 10', 'Language 11']));
        expect(labels).not.toContain('Language 2');

        const tenth = Array.from(container.querySelectorAll('[role=option]')).find((option) => option.textContent === 'Language 10') as HTMLElement;
        await act(async () => { tenth.dispatchEvent(new MouseEvent('mousedown', { bubbles: true })); });

        expect(onSelect).toHaveBeenCalledWith('lang-10');

        await unmount();
    });

    it('moves the selection with the arrow keys in tabs mode', async () =>
    {
        const onSelect = jest.fn();
        const { container, unmount } = await mount(<Harness options={makeOptions(3)} onSelect={onSelect} />);

        const firstTab = container.querySelector('[role=tab]') as HTMLElement;
        await act(async () => { firstTab.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true })); });

        expect(onSelect).toHaveBeenCalledWith('lang-1');
        const tabs = container.querySelectorAll('[role=tab]');
        expect(document.activeElement).toBe(tabs[1]);

        await unmount();
    });
});
