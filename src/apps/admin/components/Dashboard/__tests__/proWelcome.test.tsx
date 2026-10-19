import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { Dashboard } from '@/apps/admin/components/Dashboard';
import type { ConnectStatus, ProPanelState } from '@/shared/api/connect';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockApi = { getConnectStatus: jest.fn(), installPro: jest.fn(), activatePro: jest.fn(), dismissProWelcome: jest.fn() };

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
    dismissProWelcome: (...args: unknown[]) => mockApi.dismissProWelcome(...args),
    getStaleTranslations: jest.fn(() => Promise.resolve({ lexicon: {}, localizer: {} })),
    getJobEvents: jest.fn(() => Promise.resolve({ events: [] })),
    getRetranslationEstimate: jest.fn(() => Promise.resolve({ segments: 0, tokens: 0 })),
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

const status = (proState: ProPanelState, welcomePending = false): ConnectStatus => ({
    connected: true,
    connectionStatus: 'connected',
    siteId: 's',
    accountLabel: '',
    registeredUrl: 'https://example.com',
    addressChange: null,
    portalSitesUrl: '',
    portalSiteUrl: '',
    pro: proState,
    proWelcomePending: welcomePending,
});

let root: Root | null = null;

const render = async (proState: ProPanelState, welcomePending = false): Promise<HTMLElement> =>
{
    mockApi.getConnectStatus.mockResolvedValue(status(proState, welcomePending));
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

const panel = (container: HTMLElement) => container.querySelector('[data-testid="pro-welcome"]');
const links = (container: HTMLElement) => Array.from(panel(container)?.querySelectorAll('a') ?? []).map(link => [link.textContent, link.getAttribute('href')]);
const activePro = (overrides: Partial<ProPanelState> = {}) => pro({ installed: true, active: true, installedVersion: '1.2.0', ...overrides });
const flush = async () => { await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)); }); };

describe('the Dashboard welcomes a site to Pro once', () =>
{
    beforeEach(() =>
    {
        (window as unknown as { TranslateWP: unknown }).TranslateWP = { context: { home_url: 'https://example.com' } };
    });

    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
        jest.clearAllMocks();
    });

    it('shows the Pro welcome once after activation and hides it after dismiss', async () =>
    {
        mockApi.dismissProWelcome.mockResolvedValue({ success: true });
        const container = await render(activePro(), true);

        expect(panel(container)).not.toBeNull();
        expect(panel(container)?.textContent).toContain('Welcome to Pro');
        expect(container.querySelector('[data-testid="pro-install-card"]')).toBeNull();

        mockApi.getConnectStatus.mockResolvedValue(status(activePro(), false));
        const dismiss = Array.from(panel(container)!.querySelectorAll('button')).find(button => button.getAttribute('aria-label') === 'Dismiss');
        await act(async () => { dismiss!.click(); });
        await flush();

        expect(mockApi.dismissProWelcome).toHaveBeenCalledTimes(1);
        expect(panel(container)).toBeNull();
    });

    it('does not show the welcome again once the site has dismissed it', async () =>
    {
        const container = await render(activePro(), false);
        expect(panel(container)).toBeNull();
    });

    it('shows no welcome on a Free site, nor before the addon is active', async () =>
    {
        let container = await render(pro({ plan: 'free', entitled: false }), true);
        expect(panel(container)).toBeNull();
        act(() => root?.unmount());
        document.body.innerHTML = '';

        container = await render(pro({ installed: true, installedVersion: '1.2.0' }), true);
        expect(panel(container)).toBeNull();
        expect(container.querySelector('[data-testid="pro-install-card"]')).not.toBeNull();
    });

    it('lists what Pro unlocked in order, each with a link', async () =>
    {
        const container = await render(activePro(), true);

        expect(links(container)).toEqual([
            ['SEO Tools', 'admin.php?page=translatewp-seo'],
            ['Custom style and tone', 'admin.php?page=translatewp-settings#translation'],
            ['Lexicon scan', 'admin.php?page=translatewp-lexicon'],
            ['Edit translations and placement on the page', 'https://example.com'],
            ['Your own AI key', 'admin.php?page=translatewp-settings#ai-model'],
        ]);
    });

    it('lists only the controls Pro has on this site', async () =>
    {
        const container = await render(activePro({ capabilities: { seo: true, custom_style: true, byok: false } }), true);

        expect(links(container).map(([label]) => label)).not.toContain('Your own AI key');
        expect(links(container)).toHaveLength(4);
    });
});
