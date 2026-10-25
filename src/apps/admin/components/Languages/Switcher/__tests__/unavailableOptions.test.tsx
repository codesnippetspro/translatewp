import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { LanguagesSwitcher } from '@/apps/admin/components/Languages/Switcher';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

jest.mock('@tanstack/react-query', () => ({
    queryOptions: (options: unknown) => options,
    useQuery: () => ({ data: undefined }),
    useMutation: () => ({ mutate: () => {}, isPending: false }),
    useQueryClient: () => ({ invalidateQueries: async () => {} }),
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root | null = null;

const render = async (patch: Record<string, unknown>): Promise<HTMLElement> =>
{
    useAdminSettingsStore.getState().setSetting('languages.switcher', previous => ({ ...previous, ...patch }));
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () => { root?.render(<LanguagesSwitcher />); });
    return container;
};

const reasons = (container: HTMLElement) => Array.from(container.querySelectorAll('[data-unavailable-reason]')).map(node => node.textContent ?? '');

afterEach(async () =>
{
    await act(async () => { root?.unmount(); });
    root = null;
    document.body.innerHTML = '';
});

describe('Settings, Switcher: options that do nothing for the current switcher', () =>
{
    it('offers everything for the dropdown with flags on', async () =>
    {
        const container = await render({ type: 'dropdown', show_flag: true, inline_placement: null });
        expect(reasons(container)).toEqual([]);
        expect(container.querySelector('[aria-disabled="true"]')).toBeNull();
    });

    it('disables what the native dropdown does not use, each with a short reason', async () =>
    {
        const container = await render({ type: 'native_select', show_flag: true });
        const said = reasons(container).join(' | ');
        expect(said).toContain('always shows the language name');
        expect(said).toContain('emoji flags');
        expect(said).toContain('chooses its own highlight');
        expect(said).toContain('only the offset applies');
        expect(container.querySelectorAll('[data-option-cards="show-selected-language"] input[type="radio"]').length).toBe(3);
        expect(container.querySelector('[data-size-icon]')?.getAttribute('aria-disabled')).toBe('true');
        expect(container.querySelector('[data-size-padding]')?.getAttribute('aria-disabled')).toBe('true');
    });

    it('asks for the flag icon to be on before its style is chosen', async () =>
    {
        const container = await render({ type: 'dropdown', show_flag: false });
        expect(reasons(container)).toEqual(['Turn on the flag icon to choose its style.']);
    });

    it('shows the setting for pages without the placed spot only once the switcher is placed', async () =>
    {
        let container = await render({ type: 'dropdown', inline_placement: null });
        expect(container.querySelector('[data-anchor-fallback]')).toBeNull();
        await act(async () => { root?.unmount(); });
        container = await render({ inline_placement: { selector: '.site-title', side: 'below' } });
        expect(container.querySelector('[data-anchor-fallback]')?.textContent).toContain('Pages without the placed spot');
    });
});
