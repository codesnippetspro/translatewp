import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

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

afterEach(async () =>
{
    await act(async () => { root?.unmount(); });
    root = null;
    document.body.innerHTML = '';
});

describe('Settings, Advanced', () =>
{
    it('says what happens next once the cache is cleared', async () =>
    {
        const container = await render();
        const button = Array.from(container.querySelectorAll('button')).find(each => each.textContent === 'Clear translation cache') as HTMLButtonElement;
        await act(async () => { button.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(container.querySelector('[role="status"]')?.textContent).toBe('Cache cleared. Each page translates again the next time someone opens it.');
    });

    it('shows the other translation plugins advice as a notice', async () =>
    {
        const container = await render();
        const notice = container.querySelector('#other-translation-plugins [role="note"]') as HTMLElement;
        expect(notice).not.toBeNull();
        expect(notice.className).toMatch(/\bborder\b/);
        expect(notice.textContent).toContain('TranslateWP keeps working when another translation plugin is active');
        expect(notice.textContent).toContain('use one translation system per site');
    });
});
