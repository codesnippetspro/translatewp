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

const render = async (): Promise<HTMLElement> =>
{
    useAdminSettingsStore.getState().setSetting('languages.switcher', previous => ({ ...previous, type: 'dropdown', show_flag: true }));
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () => { root?.render(<LanguagesSwitcher />); });
    return container;
};

afterEach(async () =>
{
    await act(async () => { root?.unmount(); });
    root = null;
    document.body.innerHTML = '';
});

describe('Settings, Switcher: layout', () =>
{
    it('puts where it shows first and the preview column second', async () =>
    {
        const container = await render();
        const columns = Array.from(container.querySelector('[data-switcher-layout]')?.children ?? []);

        expect(columns).toHaveLength(2);
        expect(columns[0].textContent).toContain('Where it shows');
        expect(columns[1].querySelector('[data-switcher-preview-column]')).not.toBeNull();
        expect(columns[1].querySelector('[data-switcher-sizes]')).not.toBeNull();
        expect(columns[0].querySelector('[data-switcher-sizes]')).toBeNull();
        expect(columns[1].querySelector('[data-switcher-background]')).not.toBeNull();
        expect(columns[0].querySelector('[data-switcher-language-names]')).not.toBeNull();
    });

    it('starts every option card at the top and keeps room for the check mark, so its text stays put when the choice changes', async () =>
    {
        const container = await render();
        const cards = Array.from(container.querySelectorAll<HTMLElement>('[data-option-cards] [role="radio"]'));

        expect(cards.length).toBeGreaterThan(4);
        for (const card of cards)
        {
            expect(card.className).toMatch(/\bflex-col\b/);
            expect(card.className).toMatch(/\bjustify-start\b/);
            const check = card.querySelector('[data-option-check]');
            expect(check).not.toBeNull();
            expect(check?.getAttribute('class') ?? '').toMatch(card.getAttribute('aria-checked') === 'true' ? /^(?!.*\binvisible\b)/ : /\binvisible\b/);
        }
    });
});
