import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { Setup } from '@/apps/setup';
import { useStepStore } from '@/apps/setup/stores/stepStore';
import { useSetupStore } from '@/apps/setup/stores/setupStore';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockGetConnectStatus = jest.fn();

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockGetConnectStatus(...args),
    beginConnect: jest.fn(),
    disconnectSite: jest.fn(),
    installPro: jest.fn(),
    activatePro: jest.fn(),
}));

jest.mock('@/apps/setup/components/header', () => ({ Header: () => null }));

type Status = { connected: boolean; siteId: string | null; accountLabel: string; portalSitesUrl: string; portalSiteUrl: string };

let currentRoot: Root | null = null;

const renderSetup = async (status: Status): Promise<HTMLElement> =>
{
    mockGetConnectStatus.mockResolvedValue(status);
    const container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    queryClient.setQueryData(['connectStatus'], status);
    const root = createRoot(container);
    currentRoot = root;
    await act(async () =>
    {
        root.render(
            <QueryClientProvider client={queryClient}>
                <Setup />
            </QueryClientProvider>
        );
    });
    await act(async () =>
    {
        await new Promise((resolve) => setTimeout(resolve, 0));
    });
    return container;
};

describe('Setup connected-account confirmation', () =>
{
    beforeEach(() =>
    {
        mockGetConnectStatus.mockReset();
        useStepStore.setState({ step: 'connect' });
        useSetupStore.setState({ languages: [] });
    });

    afterEach(async () =>
    {
        if (currentRoot) {
            await act(async () => { currentRoot?.unmount(); });
            currentRoot = null;
        }
        document.body.innerHTML = '';
    });

    it('confirms the connected account label right after connecting', async () =>
    {
        const container = await renderSetup({
            connected: true, siteId: 'site-1', accountLabel: 'ops@translatewp.ai', portalSitesUrl: '', portalSiteUrl: '',
        });

        expect(container.textContent).toContain('Connected as ops@translatewp.ai');
    });

    it('falls back to the connected-account line when no label is stored', async () =>
    {
        const container = await renderSetup({
            connected: true, siteId: 'site-1', accountLabel: '', portalSitesUrl: '', portalSiteUrl: '',
        });

        expect(container.textContent).toContain('This site is connected to your TranslateWP account.');
        expect(container.textContent).not.toContain('Connected as');
    });

    it('shows no confirmation while the site is not connected', async () =>
    {
        const container = await renderSetup({
            connected: false, siteId: null, accountLabel: '', portalSitesUrl: '', portalSiteUrl: '',
        });

        expect(container.textContent).not.toContain('Connected as');
    });
});
