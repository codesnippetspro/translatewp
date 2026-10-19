import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { TranslationsAdvancedStyle } from '@/apps/admin/components/Translations/Style';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

jest.mock('@/shared/api/admin', () => ({
    updateAdminData: jest.fn(() => Promise.resolve({ success: true })),
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root | null = null;
const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () => { root!.render(<QueryClientProvider client={client}><TranslationsAdvancedStyle /></QueryClientProvider>); });
    return container;
};

const initialize = (style: unknown) =>
{
    useAdminSettingsStore.setState({ isInitialized: false } as never);
    useAdminSettingsStore.getState().initializeFromApiData({
        languagesList: [], switcher: {}, seo: {}, translationsSettings: {}, translationsContext: {},
        translationsStyle: style, setup: {}, advanced: {},
    });
};

const withPro = () =>
{
    (window as unknown as { translatewpPro?: unknown }).translatewpPro = { connected: true, entitled: true, installed: true, active: true, version: '1.0.0', upgradeUrl: '' };
};

describe('advanced style controls', () =>
{
    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
        delete (window as unknown as { translatewpPro?: unknown }).translatewpPro;
    });

    it('on Free shows Audience, Length and Cultural adaptation as locked rows with the Pro tag', async () =>
    {
        initialize({});
        const container = await render();
        const advanced = container.querySelector('[data-advanced-style]') as HTMLElement;
        expect(advanced).not.toBeNull();
        expect(advanced.children[0].querySelector('svg.lucide-sparkles')).toBeNull();
        for (const title of ['Audience', 'Length', 'Cultural adaptation']) {
            expect(advanced.textContent).toContain(title);
        }
        expect(advanced.querySelectorAll('button[aria-label^="Unlock"]').length).toBe(3);
    });

    it('with Pro the controls are live and store their values', async () =>
    {
        withPro();
        initialize({ length: 'shorter' });
        const container = await render();
        const advanced = container.querySelector('[data-advanced-style]') as HTMLElement;
        expect(advanced.querySelector('button[aria-label^="Unlock"]')).toBeNull();
        expect(advanced.children[0].querySelector('svg.lucide-sparkles')).not.toBeNull();
        expect(advanced.querySelectorAll('svg.lucide-sparkles')).toHaveLength(1);

        const input = advanced.querySelector('[data-audience-field] input') as HTMLInputElement;
        expect(input.maxLength).toBe(200);
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!;
        act(() =>
        {
            setter.call(input, 'Small business owners');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        expect(useAdminSettingsStore.getState().settings['translations.style'].audience).toBe('Small business owners');

        const shorter = advanced.querySelector('[data-option-cards="length"] [role="radio"][aria-checked="true"]') as HTMLElement;
        expect(shorter.textContent).toContain('Shorter');
        const longer = Array.from(advanced.querySelectorAll('[data-option-cards="length"] [role="radio"]')).find(card => card.textContent?.includes('Longer')) as HTMLButtonElement;
        act(() => longer.click());
        expect(useAdminSettingsStore.getState().settings['translations.style'].length).toBe('longer');

        const adapt = Array.from(advanced.querySelectorAll('[data-option-cards="cultural-adaptation"] [role="radio"]')).find(card => card.textContent?.includes('Adapt')) as HTMLButtonElement;
        act(() => adapt.click());
        expect(useAdminSettingsStore.getState().settings['translations.style'].cultural_adaptation).toBe('adapt');
    });

    it('loads the defaults when nothing is stored', () =>
    {
        initialize({});
        const style = useAdminSettingsStore.getState().settings['translations.style'];
        expect(style.audience).toBe('');
        expect(style.length).toBe('match');
        expect(style.cultural_adaptation).toBe('keep');
    });
});
