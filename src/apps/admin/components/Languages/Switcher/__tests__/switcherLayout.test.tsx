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

const sections = (element: Element | null | undefined): string[] =>
    Array.from(element?.querySelectorAll<HTMLElement>('[data-switcher-section]') ?? []).map(section => section.dataset.switcherSection ?? '');

afterEach(async () =>
{
    await act(async () => { root?.unmount(); });
    root = null;
    document.body.innerHTML = '';
});

describe('Settings, Switcher: layout', () =>
{
    it('keeps settings in order beside a 300px Preview rail from 1280px', async () =>
    {
        const container = await render();
        const layout = container.querySelector('[data-switcher-layout]') as HTMLElement;
        const settings = layout.querySelector('[data-switcher-column="settings"]') as HTMLElement;
        const preview = layout.querySelector('[data-switcher-preview-column]') as HTMLElement;

        expect(Array.from(layout.children)).toEqual([settings, preview]);
        expect(sections(settings)).toEqual([
            'type', 'show-selected', 'flag-style', 'language-names', 'sizes', 'background', 'colors', 'pending-message', 'support',
        ]);
        expect(layout.className).toContain('min-[1280px]:grid-cols-[minmax(0,1fr)_300px]');
        expect(layout.className).toContain('min-[1280px]:gap-x-10');
        expect(preview.className).toContain('min-[1280px]:sticky');
    });

    it('puts Preview first below 1280px and restores the right rail above it', async () =>
    {
        const container = await render();
        const preview = container.querySelector('[data-switcher-preview-column]') as HTMLElement;

        expect(preview.className).toContain('order-first');
        expect(preview.className).toContain('min-[1280px]:order-none');
        expect(preview.className).toContain('min-[1280px]:col-start-2');
        expect(preview.className).toContain('min-[1280px]:row-start-1');
    });

    it('uses container widths for one, two and three-column option grids', async () =>
    {
        const container = await render();
        const threeOptions = container.querySelector('[data-option-cards="switcher-type"]') as HTMLElement;
        const twoOptions = container.querySelector('[data-option-cards="regional-names"]') as HTMLElement;
        const sizes = container.querySelector('[data-switcher-sizes] > div:last-child') as HTMLElement;

        expect(threeOptions.className).toContain('@max-[379px]:grid-cols-1');
        expect(threeOptions.className).toContain('@min-[380px]:grid-cols-2');
        expect(threeOptions.className).toContain('@min-[500px]:grid-cols-3');
        expect(twoOptions.className).toContain('@min-[380px]:grid-cols-2');
        expect(sizes.className).toContain('@min-[500px]:grid-cols-3');
    });

    it('renders the translating message once immediately before Support TranslateWP', async () =>
    {
        const container = await render();
        const settings = container.querySelector('[data-switcher-column="settings"]') as HTMLElement;
        const names = sections(settings);

        expect(container.querySelectorAll('[data-switcher-section="pending-message"]')).toHaveLength(1);
        expect(names.slice(-2)).toEqual(['pending-message', 'support']);
    });

    it('starts every option card at the top and keeps room for the check mark, so its text stays put when the choice changes', async () =>
    {
        const container = await render();
        const cards = Array.from(container.querySelectorAll<HTMLInputElement>('[data-option-cards] input[type="radio"]'))
            .map(input => input.closest('.box-border') as HTMLElement);

        expect(cards.length).toBeGreaterThan(4);
        for (const card of cards)
        {
            expect(card.className).toMatch(/\bflex-col\b/);
            expect(card.className).toMatch(/\bjustify-start\b/);
            const check = card.querySelector('[data-option-check]');
            expect(check).not.toBeNull();
            expect(check?.getAttribute('class') ?? '').toMatch(card.querySelector<HTMLInputElement>('input[type="radio"]')?.checked ? /^(?!.*\binvisible\b)/ : /\binvisible\b/);
        }
    });

    it('keeps the switcher type and show selected language descriptions about the same length', async () =>
    {
        const container = await render();
        for (const group of ['switcher-type', 'show-selected-language'])
        {
            const lengths = Array.from(container.querySelectorAll(`[data-option-cards="${group}"] [data-option-description]`))
                .map(description => (description.textContent ?? '').length);
            expect(lengths).toHaveLength(3);
            expect(Math.max(...lengths) - Math.min(...lengths)).toBeLessThanOrEqual(24);
        }
    });
});
