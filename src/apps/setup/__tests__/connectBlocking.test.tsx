import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { Setup } from '@/apps/setup';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockGetConnectStatus = jest.fn();

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockGetConnectStatus(...args),
    beginConnect: jest.fn(),
    disconnectSite: jest.fn(),
}));

const renderSetup = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await act(async () =>
    {
        createRoot(container).render(
            <QueryClientProvider client={queryClient}>
                <Setup />
            </QueryClientProvider>
        );
    });
    // Setup mounts three observers on the same connectStatus query (useSteps,
    // ConnectionPanel, ConnectionStatusText). A single microtask flush inside
    // the act() above isn't always enough to settle the mocked query and its
    // resulting re-render under full-suite load, which made this file flaky
    // (see ConnectionPanelRerun.test.tsx's renderPanel for the same fix).
    await act(async () =>
    {
        await new Promise((resolve) => setTimeout(resolve, 0));
    });
    return container;
};

describe('Setup connect step blocking', () =>
{
    beforeEach(() =>
    {
        mockGetConnectStatus.mockReset();
    });

    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    it('shows Connect your site as the only first-step action', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({ connected: false, siteId: null, accountLabel: '', portalSitesUrl: '' });

        const container = await renderSetup();

        const buttons = Array.from(container.querySelectorAll('button'));
        expect(buttons.map(button => button.textContent).filter(Boolean)).toEqual(['Connect your site']);
    });

    it('keeps continue disabled on read-site until the site is read', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({ connected: true, siteId: 'site-1', accountLabel: 'ops@translatewp.ai', portalSitesUrl: '' });

        const container = await renderSetup();

        const continueButton = Array.from(container.querySelectorAll('button'))
            .find((button) => button.textContent === 'Continue');
        expect(continueButton).toBeDefined();
        expect((continueButton as HTMLButtonElement).disabled).toBe(true);
    });
});
