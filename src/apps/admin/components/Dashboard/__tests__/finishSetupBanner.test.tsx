jest.mock('@/shared/lib/reloadPage', () => ({ reloadPage: jest.fn() }));
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { FinishSetupBanner } from '@/apps/admin/components/Dashboard/FinishSetupBanner';
import type { ConnectStatus } from '@/shared/api/connect';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockApi = {
    getConnectStatus: jest.fn(),
    restartSetupWizard: jest.fn(),
    dismissFinishSetup: jest.fn(),
};

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockApi.getConnectStatus(...args),
}));

jest.mock('@/shared/api/admin', () => ({
    restartSetupWizard: (...args: unknown[]) => mockApi.restartSetupWizard(...args),
    dismissFinishSetup: (...args: unknown[]) => mockApi.dismissFinishSetup(...args),
}));

const status = (overrides: Partial<ConnectStatus>): ConnectStatus => ({
    connected: true,
    connectionStatus: 'connected',
    siteId: 's',
    accountLabel: '',
    registeredUrl: 'https://example.com',
    addressChange: null,
    portalSitesUrl: '',
    portalSiteUrl: '',
    ...overrides,
});

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await act(async () =>
    {
        createRoot(container).render(
            <QueryClientProvider client={queryClient}>
                <FinishSetupBanner />
            </QueryClientProvider>
        );
    });
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)); });
    return container;
};

const click = async (button: Element | null): Promise<void> =>
{
    await act(async () =>
    {
        button?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));
    });
};

describe('the Finish setting up banner', () =>
{
    afterEach(() =>
    {
        document.body.innerHTML = '';
        jest.clearAllMocks();
    });

    it('shows nothing when setup is finished', async () =>
    {
        mockApi.getConnectStatus.mockResolvedValue(status({ setupUnfinished: false }));
        expect((await render()).textContent).toBe('');
    });

    it('shows the banner and re-enters setup on the primary action', async () =>
    {
        mockApi.getConnectStatus.mockResolvedValue(status({ setupUnfinished: true }));
        mockApi.restartSetupWizard.mockResolvedValue({ success: true });
        const container = await render();
        expect(container.textContent).toContain('Finish setting up');

        const buttons = Array.from(container.querySelectorAll('button'));
        const primary = buttons.find((button) => button.textContent === 'Finish setting up') ?? null;
        await click(primary);
        expect(mockApi.restartSetupWizard).toHaveBeenCalledTimes(1);
    });

    it('dismisses for the current user on the secondary action', async () =>
    {
        mockApi.getConnectStatus.mockResolvedValue(status({ setupUnfinished: true }));
        mockApi.dismissFinishSetup.mockResolvedValue({ success: true });
        const container = await render();

        const buttons = Array.from(container.querySelectorAll('button'));
        const secondary = buttons.find((button) => button.textContent === 'Dismiss for now') ?? null;
        await click(secondary);
        expect(mockApi.dismissFinishSetup).toHaveBeenCalledTimes(1);
    });
});
