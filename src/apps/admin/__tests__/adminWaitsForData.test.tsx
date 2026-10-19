import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { Admin } from '@/apps/admin/Admin';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@/apps/admin/components/Header', () => ({ Header: () => null }));
jest.mock('@/apps/admin/components/Dashboard/DashboardPage', () => ({ DashboardPage: () => <div data-testid='dashboard-page' /> }));
jest.mock('@/apps/admin/components/Lexicon', () => ({ Lexicon: () => null }));
jest.mock('@/apps/admin/components/SeoTools', () => ({ SeoTools: () => null }));
jest.mock('@/apps/admin/components/SettingsPage', () => ({ SettingsPage: () => null }));
jest.mock('@/apps/admin/components/AiProviders', () => ({ AiProviders: () => null }));

// Settings that never arrive: the shell must not draw a page from the empty store.
jest.mock('@/shared/api/admin', () => ({
    getAdminData: jest.fn(() => new Promise(() => {})),
    updateAdminData: jest.fn(() => Promise.resolve({ success: true })),
    getRetranslationEstimate: jest.fn(() => Promise.resolve(null)),
    getSettingsRevision: jest.fn(() => Promise.resolve({ revision: 1 })),
}));

describe('the admin shell waits for its settings before drawing a page', () =>
{
    it('shows a loading state and no dashboard while the settings are still pending', async () =>
    {
        useAdminSettingsStore.setState({ isInitialized: false, isLoading: true } as never);
        const container = document.createElement('div');
        document.body.appendChild(container);
        const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        await act(async () =>
        {
            createRoot(container).render(
                <QueryClientProvider client={queryClient}>
                    <Admin />
                </QueryClientProvider>
            );
        });
        await act(async () =>
        {
            await new Promise((resolve) => setTimeout(resolve, 0));
        });

        expect(container.querySelector('[role="status"][aria-label="Loading"]')).not.toBeNull();
        expect(container.querySelector('[data-testid="dashboard-page"]')).toBeNull();
        document.body.innerHTML = '';
    });
});

describe('the admin shell says when its settings cannot be loaded', () =>
{
    it('shows an error with a try-again action instead of a spinner when a settings query fails', async () =>
    {
        const api = jest.requireMock('@/shared/api/admin') as { getAdminData: jest.Mock };
        api.getAdminData.mockImplementation((option: string) => option === 'advanced' ? Promise.reject(new Error('boom')) : Promise.resolve({}));
        useAdminSettingsStore.setState({ isInitialized: false, isLoading: true } as never);
        const container = document.createElement('div');
        document.body.appendChild(container);
        const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        await act(async () =>
        {
            createRoot(container).render(
                <QueryClientProvider client={queryClient}>
                    <Admin />
                </QueryClientProvider>
            );
        });
        await act(async () =>
        {
            await new Promise((resolve) => setTimeout(resolve, 0));
        });

        expect(container.querySelector('[role="alert"]')?.textContent).toContain('could not be loaded');
        expect(container.textContent).toContain('Try again');
        expect(container.querySelector('[role="status"][aria-label="Loading"]')).toBeNull();
        expect(container.querySelector('[data-testid="dashboard-page"]')).toBeNull();
        document.body.innerHTML = '';
    });
});
