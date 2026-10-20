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

const rowLabels = (container: HTMLElement, selector: string): string[] =>
    Array.from((container.querySelector(selector) as HTMLElement).querySelectorAll('button[aria-haspopup="dialog"]'))
        .map(button => button.getAttribute('aria-label') ?? '');

const swatch = (container: HTMLElement, label: string): HTMLButtonElement =>
    Array.from(container.querySelectorAll('button[aria-haspopup="dialog"]'))
        .find(button => button.getAttribute('aria-label') === label) as HTMLButtonElement;

describe('Settings > Switcher > Colors layout', () =>
{
    beforeEach(() =>
    {
        (window as unknown as { TranslateWP?: unknown }).TranslateWP = { context: { home_url: 'https://example.test/' } };
    });

    afterEach(async () =>
    {
        await act(async () => { root?.unmount(); });
        root = null;
        document.body.innerHTML = '';
        jest.clearAllMocks();
    });

    it('lays the colours out in three rows, with both border colours on the last row', async () =>
    {
        initialize({ type: 'dropdown' });
        const container = await render();

        expect(rowLabels(container, '[data-primary-colors]')).toEqual(['Text color', 'Background color']);
        expect(rowLabels(container, '[data-highlight-colors]')).toEqual(['Highlight text color', 'Highlight background']);
        expect(rowLabels(container, '[data-border-colors]')).toEqual(['Border color', 'Loading border color']);
    });

    it('shows the loading ring in the preview while the loading border picker is open, and removes it on close', async () =>
    {
        initialize({ type: 'dropdown' });
        const container = await render();
        const previewTrigger = () => container.querySelector('[data-twp-switcher-preview] .twp-trigger') as HTMLElement;

        expect(previewTrigger().getAttribute('data-translating')).toBeNull();

        await act(async () => { swatch(container, 'Loading border color').click(); });
        expect(previewTrigger().getAttribute('data-translating')).toBe('true');

        await act(async () => { swatch(container, 'Loading border color').click(); });
        expect(previewTrigger().getAttribute('data-translating')).toBeNull();
    });

    it('does not save or call the API when the loading border picker only opens and closes', async () =>
    {
        initialize({ type: 'dropdown' });
        const container = await render();

        await act(async () => { swatch(container, 'Loading border color').click(); });
        await act(async () => { swatch(container, 'Loading border color').click(); });

        expect(updateAdminData as jest.Mock).not.toHaveBeenCalled();
    });

    it('opening another colour picker does not show the loading ring', async () =>
    {
        initialize({ type: 'dropdown' });
        const container = await render();
        const previewTrigger = () => container.querySelector('[data-twp-switcher-preview] .twp-trigger') as HTMLElement;

        await act(async () => { swatch(container, 'Border color').click(); });
        expect(previewTrigger().getAttribute('data-translating')).toBeNull();
    });
});
