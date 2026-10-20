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
const originalMatchMedia = window.matchMedia;
const originalRect = HTMLElement.prototype.getBoundingClientRect;

const useViewport = (desktop: boolean) =>
{
    window.matchMedia = ((query: string) => ({
        matches: desktop,
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
    })) as unknown as typeof window.matchMedia;
};

const GAP = 24;

// jsdom has no layout: a section reports its height by name, and a column the
// height of its sections plus the gaps between them, as the browser lays it out.
const useHeights = (heights: Record<string, number>) =>
{
    HTMLElement.prototype.getBoundingClientRect = function (this: HTMLElement)
    {
        const children = Array.from(this.children) as HTMLElement[];
        const height = this.dataset.switcherColumn !== undefined
            ? children.reduce((total, child) => total + (heights[child.dataset.switcherSection ?? ''] ?? 0), 0) + GAP * Math.max(0, children.length - 1)
            : heights[this.dataset.switcherSection ?? ''] ?? 0;
        return { x: 0, y: 0, top: 0, left: 0, right: 0, bottom: height, width: 0, height, toJSON: () => ({}) } as DOMRect;
    };
};

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
    window.matchMedia = originalMatchMedia;
    HTMLElement.prototype.getBoundingClientRect = originalRect;
});

describe('Settings, Switcher: layout', () =>
{
    it('lays out two desktop columns in the owner\'s order with Support TranslateWP last', async () =>
    {
        useViewport(true);
        const container = await render();
        const columns = Array.from(container.querySelector('[data-switcher-layout]')?.children ?? []);

        expect(columns).toHaveLength(2);
        expect(sections(columns[0]).filter(name => name !== 'pending-message'))
            .toEqual(['where', 'flag-style', 'background', 'colors']);
        expect(sections(columns[1]).filter(name => name !== 'pending-message'))
            .toEqual(['preview', 'type', 'show-selected', 'language-names', 'sizes', 'support']);
        expect(columns[1].querySelector('[data-switcher-preview-column]')?.className).toMatch(/\bsticky\b/);
    });

    it('shows the translating message once, at the end of the column with more free room', async () =>
    {
        useViewport(true);
        useHeights({ where: 600, 'flag-style': 260, background: 230, colors: 320, 'language-names': 240, preview: 200, type: 200, 'show-selected': 210, sizes: 200, support: 100, 'pending-message': 160 });
        const right = await render();
        let columns = Array.from(right.querySelector('[data-switcher-layout]')?.children ?? []);
        expect(right.querySelectorAll('[data-switcher-section="pending-message"]')).toHaveLength(1);
        expect(sections(columns[1])).toEqual(['preview', 'type', 'show-selected', 'language-names', 'sizes', 'pending-message', 'support']);

        await act(async () => { root?.unmount(); });
        document.body.innerHTML = '';
        useHeights({ where: 300, 'flag-style': 100, background: 100, colors: 200, 'language-names': 300, preview: 300, type: 300, 'show-selected': 300, sizes: 200, support: 100, 'pending-message': 150 });
        const left = await render();
        columns = Array.from(left.querySelector('[data-switcher-layout]')?.children ?? []);
        expect(left.querySelectorAll('[data-switcher-section="pending-message"]')).toHaveLength(1);
        expect(sections(columns[0])).toEqual(['where', 'flag-style', 'background', 'colors', 'pending-message']);
    });

    it('counts the gaps between boxes, so a column with more boxes is not mistaken for the emptier one', async () =>
    {
        useViewport(true);
        // Sections alone: left 400, right 390. With the gaps: left 472, right 510.
        useHeights({ where: 100, 'flag-style': 100, background: 100, colors: 100, preview: 65, type: 65, 'show-selected': 65, sizes: 65, 'language-names': 65, support: 65, 'pending-message': 50 });
        const container = await render();
        const columns = Array.from(container.querySelector('[data-switcher-layout]')?.children ?? []);

        expect(sections(columns[0])).toEqual(['where', 'flag-style', 'background', 'colors', 'pending-message']);
        expect(container.querySelectorAll('[data-switcher-section="pending-message"]')).toHaveLength(1);
    });

    it('reads as one column on a phone: where it shows, the sticky preview, the rest, Support TranslateWP last', async () =>
    {
        useViewport(false);
        const container = await render();
        const layout = container.querySelector('[data-switcher-layout]');

        expect(sections(layout)).toEqual([
            'where', 'preview', 'type', 'show-selected', 'flag-style', 'background', 'colors', 'language-names', 'sizes', 'pending-message', 'support',
        ]);
        expect(Array.from(layout?.children ?? []).map(child => (child as HTMLElement).dataset.switcherSection)).toEqual(sections(layout));
        expect(layout?.querySelector('[data-switcher-preview-column]')?.className).toMatch(/\bsticky\b/);
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

    it('keeps the switcher type and show selected language descriptions about the same length', async () =>
    {
        const container = await render();
        for (const group of ['switcher-type', 'show-selected-language'])
        {
            const lengths = Array.from(container.querySelectorAll(`[data-option-cards="${group}"] [role="radio"]`))
                .map(card => (card.querySelector('[data-option-description]')?.textContent ?? '').length);
            expect(lengths).toHaveLength(3);
            expect(Math.max(...lengths) - Math.min(...lengths)).toBeLessThanOrEqual(24);
        }
    });
});
