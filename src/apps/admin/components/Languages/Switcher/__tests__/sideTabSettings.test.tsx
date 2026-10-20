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
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () => { root?.render(<LanguagesSwitcher />); });
    return container;
};

const switcher = () => useAdminSettingsStore.getState().settings['languages.switcher'];
const typeCards = (container: HTMLElement) => Array.from(container.querySelectorAll<HTMLButtonElement>('[data-option-cards="switcher-type"] [role="radio"]'));
const sideTabCard = (container: HTMLElement) => typeCards(container).find(card => card.textContent?.includes('Side tab')) as HTMLButtonElement;

const setPro = (active: boolean) =>
{
    (window as unknown as { translatewpPro?: unknown }).translatewpPro = active
        ? { connected: true, entitled: true, installed: true, active: true, version: '1.0.0', upgradeUrl: '' }
        : { connected: true, entitled: false, installed: false, active: false, version: '', upgradeUrl: '' };
};

const store = (patch: Record<string, unknown>) =>
    useAdminSettingsStore.getState().setSetting('languages.switcher', previous => ({ ...previous, ...patch }));

afterEach(async () =>
{
    await act(async () => { root?.unmount(); });
    root = null;
    document.body.innerHTML = '';
});

describe('Settings, Switcher: the Side tab', () =>
{
    it('is the third switcher type; with Pro it is chosen like the others', async () =>
    {
        setPro(true);
        store({ type: 'dropdown', side_tab_position: null, floating_position: null });
        const container = await render();

        expect(typeCards(container).map(card => card.textContent)).toEqual([
            expect.stringContaining('Dropdown'), expect.stringContaining('Native dropdown'), expect.stringContaining('Side tab'),
        ]);
        expect(sideTabCard(container).textContent).toContain('Placed switchers stay dropdowns.');
        await act(async () => { sideTabCard(container).click(); });
        expect(switcher().type).toBe('side_tab');
    });

    it('without Pro it is locked with the Pro marker and cannot be chosen', async () =>
    {
        setPro(false);
        store({ type: 'dropdown' });
        const container = await render();

        const lock = sideTabCard(container).closest('.relative') as HTMLElement;
        expect(lock.querySelector('[data-pro-lock-badge]')).not.toBeNull();
        expect(lock.querySelector('button[aria-label*="side tab"]')).not.toBeNull();
        expect(sideTabCard(container).disabled).toBe(true);
        await act(async () => { sideTabCard(container).click(); });
        expect(switcher().type).toBe('dropdown');
    });

    it('says the switcher offset does not apply, and the preview draws a tab on the edge', async () =>
    {
        setPro(true);
        store({ type: 'side_tab', side_tab_position: null });
        const container = await render();

        expect(container.querySelector('[data-size-offset]')?.getAttribute('aria-disabled')).toBe('true');
        expect(container.textContent).toContain('The side tab sits on the screen edge, so the offset does not apply.');
        const tab = container.querySelector('[data-twp-switcher-preview] .twp-side-tab');
        expect(tab?.getAttribute('data-preview')).toBe('true');
    });

    it('names the edge in Where it shows, and Reset puts back only the side tab', async () =>
    {
        setPro(true);
        const floating = { anchor: 'bottom-left', x: 4, y: 5 };
        store({ type: 'side_tab', side_tab_position: null, floating_position: floating });
        let container = await render();
        expect(container.textContent).toContain('Position: bottom edge, the default. Drag it in edit mode on your site to move it.');
        await act(async () => { root?.unmount(); });

        store({ side_tab_position: { edge: 'left', offset: 30 } });
        container = await render();
        expect(container.textContent).toContain('Position: left edge, where you dragged it in edit mode on your site.');
        const reset = Array.from(container.querySelectorAll('button')).find(button => button.textContent === 'Reset position') as HTMLButtonElement;
        await act(async () => { reset.click(); });
        expect(switcher().side_tab_position).toBeNull();
        expect(switcher().floating_position).toEqual(floating);
    });

    it('after a lapse shows what the site renders, the dropdown, and keeps the stored Side tab', async () =>
    {
        setPro(false);
        store({ type: 'side_tab', side_tab_position: { edge: 'left', offset: 40 }, floating_position: null });
        const container = await render();

        expect(typeCards(container).map(card => card.getAttribute('aria-checked'))).toEqual(['true', 'false', 'false']);
        expect(container.querySelector('[data-twp-switcher-preview] .twp-side-tab')).toBeNull();
        expect(container.textContent).toContain('Position: Top right, the default.');
        expect(container.querySelector('[data-size-offset]')?.getAttribute('aria-disabled')).toBeNull();
        expect(switcher().type).toBe('side_tab');
    });
});
