import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { LanguagesSwitcher } from '@/apps/admin/components/Languages/Switcher';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

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

describe('Translating message switcher setting', () =>
{
    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
    });

    it('offers a text box that shows the default line as its placeholder and stores what is typed', async () =>
    {
        initialize({});
        const container = await render();
        const input = container.querySelector('[data-pending-message] input') as HTMLInputElement;

        expect(input).not.toBeNull();
        expect(input.value).toBe('');
        expect(input.placeholder).toContain('Translating this page for you');
        expect(input.maxLength).toBe(200);

        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!;
        act(() =>
        {
            setter.call(input, 'Un momento…');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });

        expect(useAdminSettingsStore.getState().settings['languages.switcher'].pending_message).toBe('Un momento…');
    });

    it('shows a stored message', async () =>
    {
        initialize({ pending_message: 'Bitte warten' });
        const container = await render();
        const input = container.querySelector('[data-pending-message] input') as HTMLInputElement;

        expect(input.value).toBe('Bitte warten');
    });
});
