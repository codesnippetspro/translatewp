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

// The header logo is an inline SVG unrelated to this flow; isolating it keeps
// the wizard's step-state assertions free of its rendering noise.
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

// The selected tab is whichever tab element carries the active colour token.
const TAB_LABELS = ['Connect', 'Read your site', 'Style and tone', 'Languages', 'Translation'];
const selectedTab = (container: HTMLElement): string | null =>
{
    const els = Array.from(container.querySelectorAll('button, span'));
    const active = els.find(
        (el) => TAB_LABELS.includes((el.textContent ?? '').trim())
            && (el.className ?? '').includes('text-translatewp-primary')
    );
    return active ? (active.textContent ?? '').trim().toLowerCase() : null;
};

describe('Setup on return from the portal', () =>
{
    beforeEach(() =>
    {
        mockGetConnectStatus.mockReset();
        // A fresh module load parks the wizard on connect; the return must move
        // it forward on its own once the site reports connected.
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

    const connected: Status = {
        connected: true, siteId: 'site-1', accountLabel: 'ops@translatewp.ai', portalSitesUrl: '', portalSiteUrl: '',
    };
    const disconnected: Status = {
        connected: false, siteId: null, accountLabel: '', portalSitesUrl: '', portalSiteUrl: '',
    };

    it('advances to the read-site step and keeps the selected tab and rendered step in one state', async () =>
    {
        const container = await renderSetup(connected);

        // Read-site is the first step after connecting.
        expect(container.textContent).toContain('Read your site');
        expect(container.textContent).not.toContain('Connect your site');
        expect(selectedTab(container)).toBe('read your site');
    });

    it('stays on connect while the site is not connected', async () =>
    {
        const container = await renderSetup(disconnected);

        expect(container.textContent).toContain('Connect your site');
        expect(container.textContent).not.toContain('Add languages');
        expect(selectedTab(container)).toBe('connect');
    });
});
