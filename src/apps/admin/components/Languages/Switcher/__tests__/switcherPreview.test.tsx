import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { LanguagesSwitcher } from '@/apps/admin/components/Languages/Switcher';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { updateAdminData } from '@/shared/api/admin';

jest.mock('@/shared/api/admin', () => ({
    updateAdminData: jest.fn(() => Promise.resolve({ success: true })),
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const LANGUAGES = [
    { locale: 'en_US', slug: 'en', active: true, default: true },
    { locale: 'de_DE', slug: 'de', active: true, default: false },
];

let root: Root | null = null;

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () => { root!.render(<LanguagesSwitcher />); });
    return container;
};

const initialize = (switcher: Record<string, unknown> = {}) =>
{
    useAdminSettingsStore.setState({ isInitialized: false } as never);
    useAdminSettingsStore.getState().initializeFromApiData({
        languagesList: LANGUAGES, switcher, seo: {}, translationsSettings: {}, translationsContext: {},
        translationsStyle: {}, setup: {}, advanced: {},
    });
};

const setPro = (state: { entitled: boolean; installed: boolean; active: boolean }) =>
{
    (window as unknown as { translatewpPro?: unknown }).translatewpPro = { connected: true, version: '', upgradeUrl: '', ...state };
};

const click = async (element: Element | null | undefined) =>
{
    expect(element).toBeTruthy();
    await act(async () => { (element as HTMLElement).click(); });
};

const buttonByText = (container: HTMLElement, text: string) =>
    Array.from(container.querySelectorAll('button')).find(button => (button.textContent ?? '').includes(text));

const section = (container: HTMLElement) => container.querySelector('[data-twp-where-it-shows]') as HTMLElement;

const unlockControls = (element: HTMLElement) =>
    Array.from(element.querySelectorAll('[aria-label]')).filter(node => (node.getAttribute('aria-label') ?? '').includes('Unlock'));

describe('Settings > Switcher preview and where it shows', () =>
{
    beforeEach(() =>
    {
        (window as unknown as { TranslateWP?: unknown }).TranslateWP = { context: { home_url: 'https://example.test/' } };
        setPro({ entitled: false, installed: false, active: false });
    });

    afterEach(async () =>
    {
        await act(async () => { root?.unmount(); });
        root = null;
        document.body.innerHTML = '';
    });

    it('the preview follows the chosen type before saving', async () =>
    {
        initialize({ type: 'dropdown' });
        const container = await render();
        const preview = () => container.querySelector('[data-twp-switcher-preview]') as HTMLElement;

        expect(preview()).toBeTruthy();
        expect(preview().querySelector('.twp-trigger')).toBeTruthy();

        await click(buttonByText(container, 'Native dropdown'));
        expect(preview().querySelector('select.twp-native-select')).toBeTruthy();
        expect(preview().querySelector('.twp-trigger')).toBeNull();

        await click(buttonByText(container, 'Dropdown'));
        expect(preview().querySelector('.twp-trigger')).toBeTruthy();
    });

    it('describes Native dropdown as the same floating switcher drawn as the browser\'s own dropdown', async () =>
    {
        initialize();
        const container = await render();

        expect(container.textContent).toContain('The same floating switcher, drawn as your browser\'s own dropdown.');
    });

    it('lists the six placements as working on Free, with only the inline placement behind the lock', async () =>
    {
        initialize();
        const container = await render();
        const where = section(container);
        const locked = where.querySelector('[data-twp-locked-placements]') as HTMLElement;

        expect(where.textContent).toContain('Where it shows');
        expect(where.textContent).toContain('Top right');
        expect(locked.textContent).toContain('Inline placement');
        expect(unlockControls(where)).toHaveLength(1);
        locked.remove();
        for (const placement of ['Floating switcher', 'Widget', 'Menu item', 'Block', 'Button', 'Shortcode', '[twp-languages]'])
        {
            expect(where.textContent).toContain(placement);
        }
        expect(where.querySelector('a[href="nav-menus.php"]')).toBeTruthy();
        // The old one-option Position card is gone.
        expect(container.textContent).not.toContain('Top right (default)');
    });

    it('names the saved anchor, and Reset position returns the switcher to the default corner', async () =>
    {
        initialize({ floating_position: { anchor: 'bottom-left', x: 40, y: 60 } });
        const container = await render();

        expect(section(container).textContent).toContain('Bottom left');
        await click(buttonByText(section(container), 'Reset position'));

        expect(useAdminSettingsStore.getState().settings['languages.switcher'].floating_position).toBeNull();
        expect(section(container).textContent).toContain('Top right');
        expect(buttonByText(section(container), 'Reset position')).toBeUndefined();
    });

    it('saving after Reset position sends floating_position null', async () =>
    {
        initialize({ floating_position: { anchor: 'bottom-left', x: 40, y: 60 } });
        const container = await render();
        (updateAdminData as jest.Mock).mockClear();

        await click(buttonByText(section(container), 'Reset position'));
        await act(async () => { await useAdminSettingsStore.getState().saveSettings(); });

        expect(updateAdminData).toHaveBeenCalledWith(
            'languages.switcher',
            expect.objectContaining({ floating_position: null }),
        );
    });

    it('offers no Reset position while the switcher is already in the default corner', async () =>
    {
        initialize();
        const container = await render();

        expect(buttonByText(section(container), 'Reset position')).toBeUndefined();
    });

    it('with Pro active shows the inline zone and a link that opens the site in edit mode', async () =>
    {
        setPro({ entitled: true, installed: true, active: true });
        initialize({ inline_placement: { kind: 'zone', zone: 'footer' } });
        const container = await render();
        const where = section(container);

        expect(unlockControls(where)).toHaveLength(0);
        expect(where.textContent).toContain('Footer');
        const link = where.querySelector('a[data-twp-open-edit-mode]') as HTMLAnchorElement;
        expect(link).toBeTruthy();
        expect(link.getAttribute('href')).toBe('https://example.test/?twp-edit=1');
        expect(where.textContent).toContain('[twp-languages]');
    });

    it('with Pro active locks nothing and still lists the block and the shortcode', async () =>
    {
        setPro({ entitled: true, installed: true, active: true });
        initialize();
        const container = await render();
        const where = section(container);

        expect(where.querySelector('[data-twp-inline-placement]')).toBeTruthy();
        expect(unlockControls(where)).toHaveLength(0);
        expect(where.querySelector('[data-twp-locked-placements]')).toBeNull();
        expect(where.textContent).toContain('Add the Language Switcher block in the block editor.');
        expect(where.textContent).toContain('[twp-languages]');
    });

    it('with Pro active and no inline placement says none', async () =>
    {
        setPro({ entitled: true, installed: true, active: true });
        initialize();
        const container = await render();

        expect(section(container).querySelector('[data-twp-inline-placement]')?.textContent).toContain('None');
    });
});
