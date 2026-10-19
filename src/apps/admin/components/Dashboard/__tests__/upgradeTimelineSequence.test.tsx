import type { ReactNode } from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@wordpress/components', () => ({
    ...jest.requireActual('@wordpress/components'),
    Modal: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));
jest.mock('@/shared/components/Icon', () => ({ Icon: () => null }));

const mockApi = { getConnectStatus: jest.fn() };

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockApi.getConnectStatus(...args),
    beginConnect: jest.fn(),
    reconnectSite: jest.fn(),
    disconnectSite: jest.fn(),
    installPro: jest.fn(),
    activatePro: jest.fn(),
}));

jest.mock('@/shared/api/admin', () => ({
    getDashboardMetrics: jest.fn(() => new Promise(() => {})),
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

import { Dashboard } from '@/apps/admin/components/Dashboard';
import { Header } from '@/apps/admin/components/Header';

type Step = 'none' | 'checkout_started' | 'checkout_completed' | 'payment_received' | 'confirmed' | 'declined';

const status = (step: Step) => ({
    connected: true, connectionStatus: 'connected', siteId: 'site-1', accountLabel: 'owner@example.com',
    portalSitesUrl: '', portalSiteUrl: 'https://app.example.com/sites/site-1', addressChange: null,
    pro: {
        plan: step === 'confirmed' ? 'pro' : 'free',
        entitled: step === 'confirmed',
        installed: false, active: false, installedVersion: null, latestVersion: '1.0.0',
        packagePending: false, accountUrl: 'https://portal.example.test/account',
        upgrade: step === 'none' ? null : {
            stage: step,
            times: { checkoutStarted: '3:40 pm', checkoutCompleted: null, paymentReceived: null, confirmed: null, declined: null },
        },
        updateAvailable: false, updateUrl: null,
        capabilities: { seo: step === 'confirmed', custom_style: step === 'confirmed', byok: step === 'confirmed' },
    },
});

const settle = async () =>
{
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
};

const mount = async (queryClient: QueryClient) =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    await act(async () =>
    {
        root.render(
            <QueryClientProvider client={queryClient}>
                <Header />
                <Dashboard />
            </QueryClientProvider>
        );
    });
    await settle();

    return { container, root };
};

const moveTo = async (queryClient: QueryClient, step: Step) =>
{
    mockApi.getConnectStatus.mockResolvedValue(status(step));
    await act(async () => { await queryClient.invalidateQueries({ queryKey: ['connectStatus'] }); });
    await settle();
};

const LINES = {
    checkout_started: 'Finish checkout in the TranslateWP portal. This page updates on its own.',
    checkout_completed: 'Checkout completed. Waiting for the payment to arrive.',
    payment_received: 'Payment received. Turning on Pro for your account.',
    declined: 'Your upgrade was not confirmed. Open your account to check the payment or try again.',
};

describe('the admin follows an upgrade on one mounted page', () =>
{
    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    it('walks from nothing through each step to Install Pro, the header following along', async () =>
    {
        const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        mockApi.getConnectStatus.mockResolvedValue(status('none'));
        const { container, root } = await mount(queryClient);
        const dashboardNode = container.lastElementChild;

        expect(container.querySelector('ol[aria-label="Upgrade progress"]')).toBeNull();
        expect(container.textContent).toContain('Upgrade to PRO');

        for (const step of ['checkout_started', 'checkout_completed', 'payment_received'] as const) {
            await moveTo(queryClient, step);
            expect(container.textContent).toContain(LINES[step]);
            expect(container.textContent).not.toContain('Upgrade to PRO');
            expect(container.textContent).not.toContain('Install Pro');
        }

        await moveTo(queryClient, 'confirmed');
        expect(container.querySelector('li[aria-current="step"]')).toBeNull();
        expect(container.textContent).toContain('Install Pro');
        expect(container.textContent).not.toContain(LINES.payment_received);
        expect(container.textContent).not.toContain('Upgrade to PRO');
        expect(container.lastElementChild).toBe(dashboardNode);

        act(() => root.unmount());
    });

    it('walks from nothing through checkout to a declined upgrade', async () =>
    {
        const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        mockApi.getConnectStatus.mockResolvedValue(status('none'));
        const { container, root } = await mount(queryClient);
        const dashboardNode = container.lastElementChild;

        await moveTo(queryClient, 'checkout_started');
        expect(container.textContent).toContain(LINES.checkout_started);

        await moveTo(queryClient, 'declined');
        expect(container.textContent).toContain(LINES.declined);
        expect(container.textContent).toContain('Upgrade declined');
        expect(container.textContent).not.toContain(LINES.checkout_started);
        expect(container.textContent).toContain('Upgrade to PRO');
        expect(container.lastElementChild).toBe(dashboardNode);

        act(() => root.unmount());
    });
});
