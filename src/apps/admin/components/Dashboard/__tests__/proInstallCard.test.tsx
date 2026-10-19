import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { Dashboard } from '@/apps/admin/components/Dashboard';
import type { ConnectStatus, ProPanelState } from '@/shared/api/connect';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockApi = { getConnectStatus: jest.fn(), installPro: jest.fn(), activatePro: jest.fn() };

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockApi.getConnectStatus(...args),
    beginConnect: jest.fn(),
    reconnectSite: jest.fn(),
    disconnectSite: jest.fn(),
    installPro: (...args: unknown[]) => mockApi.installPro(...args),
    activatePro: (...args: unknown[]) => mockApi.activatePro(...args),
}));

jest.mock('@/shared/api/admin', () => ({
    getDashboardMetrics: jest.fn(() => new Promise(() => {})),
    getAdminData: jest.fn(() => Promise.resolve({})),
    updateAdminData: jest.fn(),
    clearSiteCache: jest.fn(),
    resetAllPluginData: jest.fn(),
    restartSetupWizard: jest.fn(),
    dismissFinishSetup: jest.fn(),
    getStaleTranslations: jest.fn(() => Promise.resolve({ lexicon: {}, localizer: {} })),
    getJobEvents: jest.fn(() => Promise.resolve({ events: [] })),
    getRetranslationEstimate: jest.fn(() => Promise.resolve({ pages: 0, languages: 0 })),
    applyLexiconNow: jest.fn(),
    applyLocalizerNow: jest.fn(),
}));

const pro = (overrides: Partial<ProPanelState>): ProPanelState => ({
    plan: 'pro',
    entitled: true,
    installed: false,
    active: false,
    installedVersion: null,
    latestVersion: '1.2.0',
    packagePending: false,
    upgrade: null,
    accountUrl: '',
    updateAvailable: false,
    updateUrl: null,
    capabilities: { seo: true, custom_style: true, byok: true },
    ...overrides,
});

const status = (proState: ProPanelState): ConnectStatus => ({
    connected: true,
    connectionStatus: 'connected',
    siteId: 's',
    accountLabel: '',
    registeredUrl: 'https://example.com',
    addressChange: null,
    portalSitesUrl: '',
    portalSiteUrl: '',
    pro: proState,
});

let root: Root | null = null;

const render = async (proState: ProPanelState): Promise<HTMLElement> =>
{
    mockApi.getConnectStatus.mockResolvedValue(status(proState));
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await act(async () =>
    {
        root!.render(
            <QueryClientProvider client={queryClient}>
                <Dashboard />
            </QueryClientProvider>
        );
    });
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)); });
    return container;
};

const card = (container: HTMLElement) => container.querySelector('[data-testid="pro-install-card"]');

describe('the Dashboard pushes an entitled site to install Pro', () =>
{
    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
        jest.clearAllMocks();
    });

    it('shows the Install Pro card first on the Dashboard for an entitled site without the addon', async () =>
    {
        const container = await render(pro({}));
        const found = card(container);

        expect(found).not.toBeNull();
        expect(found?.textContent).toContain('Your account includes Pro');
        expect(Array.from(found!.querySelectorAll('button')).map(button => button.textContent)).toContain('Install Pro');

        const body = container.querySelector('[data-testid="pro-install-card"]')?.parentElement;
        expect(body?.firstElementChild).toBe(found);
        expect(container.querySelector('[data-testid="pro-status"]')).toBeNull();
    });

    it('installs Pro with one click from the card', async () =>
    {
        mockApi.installPro.mockReturnValue(new Promise(() => {}));
        const container = await render(pro({}));
        const button = Array.from(card(container)!.querySelectorAll('button')).find(item => item.textContent === 'Install Pro');

        await act(async () => { button!.click(); });

        expect(mockApi.installPro).toHaveBeenCalledTimes(1);
    });

    it('shows Activate Pro when installed but inactive', async () =>
    {
        const container = await render(pro({ installed: true, installedVersion: '1.2.0' }));
        const found = card(container);

        expect(found).not.toBeNull();
        const labels = Array.from(found!.querySelectorAll('button')).map(button => button.textContent);
        expect(labels).toContain('Activate Pro');
        expect(labels).not.toContain('Install Pro');
    });

    it('shows no card when Pro is active or the account is Free', async () =>
    {
        let container = await render(pro({ installed: true, active: true, installedVersion: '1.2.0' }));
        expect(card(container)).toBeNull();
        act(() => root?.unmount());
        document.body.innerHTML = '';

        container = await render(pro({ plan: 'free', entitled: false }));
        expect(card(container)).toBeNull();
    });

    it('shows no card while the payment is still being confirmed', async () =>
    {
        const container = await render(pro({ packagePending: true, latestVersion: null }));
        expect(card(container)).toBeNull();
        expect(container.querySelector('[data-testid="pro-status"]')).not.toBeNull();
    });
});
