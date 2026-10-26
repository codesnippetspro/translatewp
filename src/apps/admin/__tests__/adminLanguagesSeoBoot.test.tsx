import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { Admin } from '@/apps/admin/Admin';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { updateAdminData } from '@/shared/api/admin';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@/apps/admin/components/Header', () => ({ Header: () => null }));
jest.mock('@/apps/admin/components/Sidebar', () => ({ Sidebar: () => null }));
jest.mock('@/apps/admin/components/Dashboard/DashboardPage', () => ({ DashboardPage: () => null }));
jest.mock('@/apps/admin/components/Lexicon', () => ({ Lexicon: () => null }));
jest.mock('@/apps/admin/components/SeoTools', () => ({ SeoTools: () => null }));
jest.mock('@/apps/admin/components/SettingsPage', () => ({ SettingsPage: () => null }));
jest.mock('@/apps/admin/components/AiProviders', () => ({ AiProviders: () => null }));

const SERVER_SEO_SETTINGS = {
    ai_crawlers: {
        preset_answer_engines: true,
        bots: { GPTBot: { default: 'allow', languages: {} } },
    },
};

jest.mock('@/shared/api/admin', () => ({
    getAdminData: jest.fn((option: string) =>
        Promise.resolve(option === 'languages.seo' ? SERVER_SEO_SETTINGS : {})
    ),
    updateAdminData: jest.fn(() => Promise.resolve({ success: true })),
    getSettingsRevision: jest.fn(() => Promise.reject(new Error('no revision in this test'))),
    getRetranslationEstimate: jest.fn(() => Promise.resolve({ pages: 0, languages: 0 })),
}));

const renderAdmin = async (): Promise<{ unmount: () => void }> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const root = createRoot(container);

    await act(async () =>
    {
        root.render(
            <QueryClientProvider client={queryClient}>
                <Admin />
            </QueryClientProvider>
        );
    });
    // Flush the microtasks the mocked queries and the resulting initializeFromApiData
    // effect need to settle (same fix as Setup connect step blocking's renderSetup).
    await act(async () =>
    {
        await new Promise((resolve) => setTimeout(resolve, 0));
    });

    return { unmount: () => root.unmount() };
};

describe('Admin boot fetches languages.seo', () =>
{
    beforeEach(() =>
    {
        useAdminSettingsStore.setState({ isInitialized: false } as never);
        (updateAdminData as jest.Mock).mockClear();
    });

    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    it('loads the stored ai_crawlers.preset_answer_engines value instead of the default', async () =>
    {
        const { unmount } = await renderAdmin();

        const seo = useAdminSettingsStore.getState().settings['languages.seo'];
        expect(seo.ai_crawlers.preset_answer_engines).toBe(true);
        expect(seo.ai_crawlers.bots).toEqual({ GPTBot: { default: 'allow', languages: {} } });

        act(() => unmount());
    });

    it('a subsequent save sends the fetched preset_answer_engines value, not the default', async () =>
    {
        const { unmount } = await renderAdmin();

        // Unmount once the boot has populated the store: the save itself is exercised
        // against the store directly (as settingsStore.saveFailure.test.ts does), so no
        // mounted subscriber is left to race the async save against.
        act(() => unmount());

        useAdminSettingsStore.getState().setSetting('languages.seo', (previous) => ({
            ...previous,
            sitemaps: !previous.sitemaps,
        }));

        const saved = await useAdminSettingsStore.getState().saveSettings();

        expect(saved).toEqual(['languages.seo']);
        expect(updateAdminData).toHaveBeenCalledWith(
            'languages.seo',
            expect.objectContaining({
                ai_crawlers: expect.objectContaining({ preset_answer_engines: true }),
            })
        );
    });
});
