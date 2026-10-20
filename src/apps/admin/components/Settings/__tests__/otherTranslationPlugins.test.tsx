import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

jest.mock('@/shared/api/admin', () => ({
    clearSiteCache: jest.fn(() => Promise.resolve({ success: true })),
    resetAllPluginData: jest.fn(() => Promise.resolve({ success: true })),
    getTranslationManagers: jest.fn(() => Promise.resolve({ users: [] })),
    saveTranslationManagers: jest.fn(),
}));
jest.mock('@/shared/components/confirmDialog/useConfirmDialog', () => ({
    useConfirmDialog: () => ({ requestConfirmation: () => Promise.resolve(true), confirmDialogProps: { isOpen: false } }),
}));
jest.mock('@/shared/components/confirmDialog', () => ({ ConfirmDialog: () => null }));

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { SettingsAdvanced } from '@/apps/admin/components/Settings/Advanced';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root | null = null;

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () =>
    {
        root?.render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><SettingsAdvanced /></QueryClientProvider>);
    });
    return container;
};

const setContext = (context: Record<string, unknown>) =>
{
    (window as unknown as { TranslateWP: unknown }).TranslateWP = { context };
};

describe('Settings, Advanced: other translation plugins section', () =>
{
    afterEach(async () =>
    {
        await act(async () => { root?.unmount(); });
        root = null;
        document.body.innerHTML = '';
        delete (window as { TranslateWP?: unknown }).TranslateWP;
    });

    it('omits the section entirely when no other translation plugin is detected', async () =>
    {
        setContext({ other_translation_plugins: [] });
        const container = await render();

        expect(container.querySelector('#other-translation-plugins')).toBeNull();
        expect(container.textContent).not.toContain('Other translation plugins');
    });

    it('omits the section when the context carries no detection list', async () =>
    {
        setContext({});
        const container = await render();

        expect(container.querySelector('#other-translation-plugins')).toBeNull();
    });

    it('shows the existing guidance when another translation plugin is detected', async () =>
    {
        setContext({ other_translation_plugins: ['WPML'] });
        const container = await render();

        const section = container.querySelector('#other-translation-plugins [role="note"]') as HTMLElement;
        expect(section).not.toBeNull();
        expect(section.textContent).toContain('TranslateWP keeps working when another translation plugin is active');
        expect(section.textContent).toContain('use one translation system per site');
    });
});
