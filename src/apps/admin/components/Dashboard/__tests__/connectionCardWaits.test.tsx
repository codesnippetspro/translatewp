import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { Dashboard } from '@/apps/admin/components/Dashboard';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockApi = { getConnectStatus: jest.fn(), getDashboardMetrics: jest.fn(),
    beginConnect: jest.fn(), reconnectSite: jest.fn() };

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockApi.getConnectStatus(...args),
    beginConnect: (...args: unknown[]) => mockApi.beginConnect(...args),
    reconnectSite: (...args: unknown[]) => mockApi.reconnectSite(...args),
    disconnectSite: jest.fn(),
    installPro: jest.fn(),
    activatePro: jest.fn(),
}));

jest.mock('@/shared/api/admin', () => ({
    getDashboardMetrics: (...args: unknown[]) => mockApi.getDashboardMetrics(...args),
    getAdminData: jest.fn(() => Promise.resolve({})),
    updateAdminData: jest.fn(),
    clearSiteCache: jest.fn(),
    resetAllPluginData: jest.fn(),
    getStaleTranslations: jest.fn(() => Promise.resolve({ lexicon: {}, localizer: {} })),
    getJobEvents: jest.fn(() => Promise.resolve({ events: [] })),
    getRetranslationEstimate: jest.fn(() => Promise.resolve({ pages: 0, languages: 0 })),
    applyLexiconNow: jest.fn(),
    applyLocalizerNow: jest.fn(),
}));

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await act(async () =>
    {
        createRoot(container).render(
            <QueryClientProvider client={queryClient}>
                <Dashboard />
            </QueryClientProvider>
        );
    });
    await act(async () =>
    {
        await new Promise((resolve) => setTimeout(resolve, 0));
    });
    return container;
};

describe('the dashboard connection card waits for the connection status', () =>
{
    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    it('shows no connection card while the status is still pending', async () =>
    {
        mockApi.getConnectStatus.mockReturnValue(new Promise(() => {}));
        mockApi.getDashboardMetrics.mockReturnValue(new Promise(() => {}));
        const container = await render();
        expect(container.textContent).not.toContain('Connection');
    });

    it('shows the connection card once the status says the site is not connected', async () =>
    {
        mockApi.getConnectStatus.mockResolvedValue({ connected: false, siteId: null, accountLabel: '', portalSitesUrl: '', portalSiteUrl: '' });
        mockApi.getDashboardMetrics.mockReturnValue(new Promise(() => {}));
        const container = await render();
        expect(container.textContent).toContain('Connection');
    });
});


describe('invalidated dashboard connection', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        mockApi.getConnectStatus.mockResolvedValue({ connected: false, connectionStatus: 'invalidated',
            siteId: 'site-1', accountLabel: '', portalSitesUrl: '', portalSiteUrl: '', addressChange: null });
        mockApi.getDashboardMetrics.mockReturnValue(new Promise(() => {}));
    });
    afterEach(() => { document.body.innerHTML = ''; });

    it('explains invalidated credentials and offers both recovery actions', async () => {
        const container = await render();
        expect(container.textContent).toContain('credentials were invalidated');
        expect(container.textContent).toContain('Reconnect this site');
        expect(container.textContent).toContain('Connect as a new site');
        expect(container.textContent).not.toContain('Not paired');
        expect(container.textContent).not.toContain('Translation keeps running');
        expect([...container.querySelectorAll('button')].some(button => button.textContent === 'Connect site')).toBe(false);
    });

    it.each([
        ['Reconnect this site', 'reconnectSite', 'beginConnect'],
        ['Connect as a new site', 'beginConnect', 'reconnectSite'],
    ] as const)('runs the %s action through its existing API path', async (label, selected, other) => {
        mockApi[selected].mockReturnValue(new Promise(() => {}));
        const container = await render();
        const button = [...container.querySelectorAll('button')].find(element => element.textContent === label);
        expect(button).toBeDefined();
        await act(async () => { button!.click(); });
        expect(mockApi[selected]).toHaveBeenCalledTimes(1);
        expect(mockApi[other]).not.toHaveBeenCalled();
    });
});


describe('the dashboard Pro area before the account is Pro', () =>
{
    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    it('shows the upgrade timeline for an account that has paid but is not Pro yet', async () =>
    {
        mockApi.getConnectStatus.mockResolvedValue({
            connected: true, connectionStatus: 'connected', siteId: 'site-1', accountLabel: 'owner@example.com',
            portalSitesUrl: '', portalSiteUrl: '', addressChange: null,
            pro: {
                plan: 'free', entitled: false, installed: false, active: false, installedVersion: null, latestVersion: '1.0.0',
                packagePending: false, accountUrl: 'https://portal.example.test/account', updateAvailable: false, updateUrl: null,
                upgrade: { stage: 'payment_received', times: { checkoutStarted: '15:40', checkoutCompleted: '15:42', paymentReceived: '15:43', confirmed: null, declined: null } },
                capabilities: { seo: false, custom_style: false, byok: false },
            },
        });
        mockApi.getDashboardMetrics.mockReturnValue(new Promise(() => {}));
        const container = await render();
        expect(container.textContent).toContain('Payment received. Turning on Pro for your account.');
    });
});
