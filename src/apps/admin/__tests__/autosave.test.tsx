import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { Admin } from '@/apps/admin/Admin';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { updateAdminData } from '@/shared/api/admin';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@/apps/admin/components/Header', () => ({ Header: () => null }));
jest.mock('@/apps/admin/components/Lexicon', () => ({ Lexicon: () => null }));
jest.mock('@/apps/admin/components/SeoTools', () => ({ SeoTools: () => null }));
jest.mock('@/apps/admin/components/SettingsPage', () => ({ SettingsPage: () => null }));
jest.mock('@/apps/admin/components/AiProviders', () => ({ AiProviders: () => null }));
jest.mock('@/apps/admin/components/Dashboard/DashboardPage', () =>
{
    const { useAdminSettingsStore: store } = jest.requireActual('@/apps/admin/stores/settingsStore');
    return {
        DashboardPage: () =>
        {
            const text = store((state: { settings: { 'translations.context': { text_context: string } } }) => state.settings['translations.context'].text_context);
            return <textarea data-testid='context' value={text} readOnly />;
        },
    };
});

jest.mock('@/shared/api/admin', () => ({
    getAdminData: jest.fn(() => Promise.resolve({})),
    updateAdminData: jest.fn(() => Promise.resolve({ success: true, revision: 2 })),
    getSettingsRevision: jest.fn(() => Promise.resolve({ revision: 1 })),
    getRetranslationEstimate: jest.fn(() => Promise.resolve(null)),
}));

const flush = async (ms = 0) =>
{
    await act(async () =>
    {
        jest.advanceTimersByTime(ms);
        for (let i = 0; i < 10; i++) {
            await Promise.resolve();
        }
    });
};

let root: Root;
let container: HTMLDivElement;

const renderAdmin = async () =>
{
    container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    root = createRoot(container);
    await act(async () =>
    {
        root.render(
            <QueryClientProvider client={queryClient}>
                <Admin />
            </QueryClientProvider>
        );
    });
    await flush(10);
    await flush(10);
};

const toggleUninstall = () => act(() =>
{
    useAdminSettingsStore.getState().setSetting('advanced', previous => ({ ...previous, delete_on_uninstall: !previous.delete_on_uninstall }));
});

const buttonNamed = (label: string) =>
    Array.from(document.body.querySelectorAll('button')).find(button => button.textContent === label);

describe('settings save on change', () =>
{
    beforeEach(() =>
    {
        jest.useFakeTimers();
        (updateAdminData as jest.Mock).mockReset();
        (updateAdminData as jest.Mock).mockResolvedValue({ success: true, revision: 2 });
        useAdminSettingsStore.setState({ isInitialized: false, staleSettings: false, saveFailed: false, savedAt: null } as never);
    });

    afterEach(() =>
    {
        act(() => root.unmount());
        document.body.innerHTML = '';
        jest.useRealTimers();
    });

    it('a toggle change saves after the debounce and shows a Saved toast', async () =>
    {
        await renderAdmin();

        toggleUninstall();
        await flush(100);
        expect(updateAdminData).not.toHaveBeenCalled();
        await flush(1000);

        expect(updateAdminData).toHaveBeenCalledWith('advanced', { delete_on_uninstall: true }, 1);
        const toast = document.body.querySelector('[role="status"]');
        expect(toast?.textContent).toContain('Saved');
        expect(toast?.querySelector('svg')).not.toBeNull();
        expect(document.body.textContent).not.toContain('Save changes');
        expect(useAdminSettingsStore.getState().hasChanges).toBe(false);
    });

    it('a text field saves when it loses focus, not while it is being typed in', async () =>
    {
        await renderAdmin();
        (jest.requireMock('@/shared/api/admin') as { getRetranslationEstimate: jest.Mock }).getRetranslationEstimate.mockResolvedValue(null);
        const field = container.querySelector('[data-testid="context"]') as HTMLTextAreaElement;

        field.focus();
        act(() =>
        {
            useAdminSettingsStore.getState().setSetting('translations.context', previous => ({ ...previous, text_context: 'A tea shop.' }));
        });
        await flush(2000);
        expect(updateAdminData).not.toHaveBeenCalled();
        expect(document.body.textContent).not.toContain('Translate the site again');

        act(() => field.blur());
        await flush(1000);
        const confirm = buttonNamed('Save and retranslate');
        expect(confirm).toBeDefined();
        act(() => confirm!.click());
        await flush(10);

        expect(updateAdminData).toHaveBeenCalledWith('translations.context', expect.objectContaining({ text_context: 'A tea shop.' }), 1);
    });

    it('a style change asks first and Maybe later reverts it', async () =>
    {
        await renderAdmin();
        const saved = useAdminSettingsStore.getState().persistedSettings['translations.style'];

        act(() =>
        {
            useAdminSettingsStore.getState().setSetting('translations.style', previous => ({ ...previous, type: 'legal' }));
        });
        await flush(1000);
        expect(document.body.textContent).toContain('Translate the site again');
        act(() => buttonNamed('Maybe later')!.click());
        await flush(1000);

        expect(updateAdminData).not.toHaveBeenCalled();
        expect(useAdminSettingsStore.getState().settings['translations.style']).toEqual(saved);
        expect(useAdminSettingsStore.getState().hasChanges).toBe(false);
    });

    it('a two-tab conflict shows the reload prompt and saves nothing', async () =>
    {
        await renderAdmin();
        (updateAdminData as jest.Mock).mockRejectedValue({ code: 'settings_changed', data: { status: 409, revision: 7 } });

        toggleUninstall();
        await flush(1000);
        expect(document.body.textContent).toContain('changed in another tab');
        expect(buttonNamed('Reload settings')).toBeDefined();

        toggleUninstall();
        toggleUninstall();
        await flush(2000);

        expect(updateAdminData).toHaveBeenCalledTimes(1);
        expect(useAdminSettingsStore.getState().hasChanges).toBe(true);
    });

    it('a failed save keeps the change and says it could not be saved with Try again', async () =>
    {
        await renderAdmin();
        (updateAdminData as jest.Mock).mockRejectedValueOnce(new Error('offline'));

        toggleUninstall();
        await flush(1000);

        expect(document.body.textContent).toContain('could not be saved');
        expect(useAdminSettingsStore.getState().settings['advanced'].delete_on_uninstall).toBe(true);
        const retry = buttonNamed('Try again');
        expect(retry).toBeDefined();

        act(() => retry!.click());
        await flush(10);

        expect(updateAdminData).toHaveBeenCalledTimes(2);
        expect(document.body.querySelector('[role="status"]')?.textContent).toContain('Saved');
    });
});
