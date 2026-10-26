import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { LanguagesSwitcher } from '@/apps/admin/components/Languages/Switcher';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { updateAdminData } from '@/shared/api/admin';

jest.mock('@/shared/api/admin', () => ({
    updateAdminData: jest.fn(() => Promise.resolve({ success: true })),
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root | null = null;

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () => { root!.render(<LanguagesSwitcher />); });
    return container;
};

const initialize = (switcher: unknown) =>
{
    useAdminSettingsStore.setState({ isInitialized: false } as never);
    useAdminSettingsStore.getState().initializeFromApiData({
        languagesList: [], switcher, seo: {}, translationsSettings: {}, translationsContext: {},
        translationsStyle: {}, setup: {}, advanced: {},
    });
};

const radio = (container: HTMLElement, label: string) =>
{
    const card = Array.from(container.querySelectorAll('[data-option-cards="regional-names"] input[type="radio"]'))
        .find(button => button.getAttribute('aria-label') === label) as HTMLInputElement | undefined;

    return card;
};

describe('Regional language names switcher setting', () =>
{
    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
    });

    it('loads as Full name when nothing is stored, and keeps a stored Short', () =>
    {
        initialize({});
        expect(useAdminSettingsStore.getState().settings['languages.switcher'].regional_names).toBe('full');

        initialize({ regional_names: 'short' });
        expect(useAdminSettingsStore.getState().settings['languages.switcher'].regional_names).toBe('short');

        initialize({ regional_names: 'medium' });
        expect(useAdminSettingsStore.getState().settings['languages.switcher'].regional_names).toBe('full');
    });

    it('offers Full name and Short with plain examples, and saves the choice with the switcher settings', async () =>
    {
        initialize({});
        const container = await render();

        expect(container.textContent).toContain('Language names');
        expect(radio(container, 'Full name')?.checked).toBe(true);
        expect(radio(container, 'Short')).toBeDefined();
        expect(container.textContent).not.toMatch(/—/);

        await act(async () => { radio(container, 'Short')!.click(); });
        expect(useAdminSettingsStore.getState().settings['languages.switcher'].regional_names).toBe('short');

        await act(async () => { await useAdminSettingsStore.getState().saveSettings(); });
        const [option, data] = (updateAdminData as jest.Mock).mock.calls.at(-1)!;
        expect(option).toBe('languages.switcher');
        expect(data.regional_names).toBe('short');
    });
});
