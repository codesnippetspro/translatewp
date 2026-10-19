import type { ReactNode } from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@wordpress/components', () => ({
    Modal: ({ children }: { children: ReactNode }) => <div>{children}</div>,
    Button: ({ children }: { children: ReactNode }) => <button type="button">{children}</button>,
}));
jest.mock('lucide-react', () => ({ Lock: () => null, Check: () => null }));
jest.mock('@/shared/components/Icon', () => ({ Icon: () => null }));

const mockGetConnectStatus = jest.fn();
const mockInstallPro = jest.fn();
const mockActivatePro = jest.fn();
const mockReloadPage = jest.fn();
jest.mock('@/shared/lib/reloadPage', () => ({ reloadPage: () => mockReloadPage() }));
jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockGetConnectStatus(...args),
    installPro: (...args: unknown[]) => mockInstallPro(...args),
    activatePro: (...args: unknown[]) => mockActivatePro(...args),
}));

import { Header } from '@/apps/admin/components/Header';

const setProState = (value: unknown) =>
{
    Object.defineProperty(window, 'translatewpPro', { value, writable: true, configurable: true });
};

const renderHeader = (queryClient: QueryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })) =>
{
    const container = document.createElement('div');
    const root = createRoot(container);
    act(() => root.render(<QueryClientProvider client={queryClient}><Header /></QueryClientProvider>));

    return { container, root };
};

// Wait for the connect-status query to settle and repaint, instead of a single
// fixed tick that races the query resolution under a loaded suite.
const waitFor = async (predicate: () => boolean, timeout = 2000): Promise<void> =>
{
    const start = Date.now();
    for (;;)
    {
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        if (predicate())
        {
            return;
        }
        if (Date.now() - start > timeout)
        {
            throw new Error('waitFor: condition not met within timeout');
        }
    }
};

const connectStatus = (entitled: boolean, stage: string | null = null) => ({
    connected: true,
    siteId: 'site-1',
    accountLabel: 'owner@example.com',
    registeredUrl: '',
    addressChange: null,
    portalSitesUrl: '',
    portalSiteUrl: '',
    pro: {
        plan: entitled ? 'pro' : 'free', entitled, installed: true, active: true, installedVersion: '1.0.0', latestVersion: '1.0.0',
        packagePending: false, accountUrl: 'https://portal.example.test/account',
        upgrade: stage === null ? null : { stage, times: { checkoutStarted: '15:40', checkoutCompleted: null, paymentReceived: null, confirmed: null, declined: null } }, updateAvailable: false, updateUrl: null,
        capabilities: { seo: entitled, custom_style: entitled, byok: entitled },
    },
});

describe('admin header upsell', () =>
{
    beforeEach(() => mockGetConnectStatus.mockReturnValue(new Promise(() => {})));
    afterEach(() => setProState(undefined));

    it('swaps Upgrade to PRO for the PRO badge when the live status turns Pro, without a reload', async () =>
    {
        setProState({ connected: true, entitled: false, installed: true, active: true });
        const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        mockGetConnectStatus.mockResolvedValue(connectStatus(false));
        const { container, root } = renderHeader(queryClient);
        await waitFor(() => (container.textContent ?? '').includes('Upgrade to PRO'));
        expect(container.textContent).toContain('Upgrade to PRO');

        mockGetConnectStatus.mockResolvedValue(connectStatus(true));
        await act(async () => { await queryClient.invalidateQueries({ queryKey: ['connectStatus'] }); });
        await waitFor(() => !(container.textContent ?? '').includes('Upgrade to PRO'));

        expect(container.textContent).not.toContain('Upgrade to PRO');
        const chip = container.querySelector('a[data-plan-chip]') as HTMLAnchorElement;
        expect(chip.textContent).toBe("You're on the Orbital plan");
        expect(chip.href).toBe('https://portal.example.test/account');
        expect(chip.target).toBe('_blank');
        act(() => root.unmount());
    });

    it.each(['checkout_started', 'checkout_completed', 'payment_received'])('hides Upgrade to PRO while the upgrade is at %s', async (stage) =>
    {
        setProState({ connected: true, entitled: false, installed: false });
        mockGetConnectStatus.mockResolvedValue(connectStatus(false, stage));
        const { container, root } = renderHeader();
        await waitFor(() => !(container.textContent ?? '').includes('Upgrade to PRO'));

        expect(container.textContent).not.toContain('Upgrade to PRO');
        act(() => root.unmount());
    });

    it('offers Upgrade to PRO again once the upgrade was declined', async () =>
    {
        setProState({ connected: true, entitled: false, installed: false });
        mockGetConnectStatus.mockResolvedValue(connectStatus(false, 'declined'));
        const { container, root } = renderHeader();
        await waitFor(() => (container.textContent ?? '').includes('Upgrade to PRO'));

        expect(container.textContent).toContain('Upgrade to PRO');
        act(() => root.unmount());
    });

    it('shows the upgrade upsell for a free site', () =>
    {
        setProState({ connected: true, entitled: false, installed: false });
        const { container, root } = renderHeader();

        expect(container.textContent).toContain('Upgrade to PRO');
        act(() => root.unmount());
    });

    it('hides the upsell and shows the pro state once entitled and installed', () =>
    {
        setProState({ connected: true, entitled: true, installed: true, active: true });
        const { container, root } = renderHeader();

        expect(container.textContent).not.toContain('Upgrade to PRO');
        const chip = container.querySelector('a[data-plan-chip]') as HTMLAnchorElement;
        expect(chip.textContent).toBe("You're on the Orbital plan");
        expect(chip.href).toBe('https://app.translatewp.ai/');
        expect(chip.target).toBe('_blank');
        act(() => root.unmount());
    });

    it('offers Install Pro for an entitled site that has not installed the addon', () =>
    {
        setProState({ connected: true, entitled: true, installed: false });
        const { container, root } = renderHeader();

        expect(container.textContent).toContain('Install Pro');
        expect(container.textContent).not.toContain('Upgrade to PRO');
        act(() => root.unmount());
    });

    it('shows Activate Pro and no PRO badge when the addon is installed but inactive', () =>
    {
        setProState({ connected: true, entitled: true, installed: true, active: false });
        const { container, root } = renderHeader();

        expect(container.textContent).toContain('Activate Pro');
        expect(container.textContent).not.toContain('Upgrade to PRO');
        const badge = Array.from(container.querySelectorAll('span')).find(span => ['Pro', 'Orbital'].includes((span.textContent ?? '').trim()));
        expect(badge).toBeUndefined();
        act(() => root.unmount());
    });

    it('installs Pro from the header and reloads the page', async () =>
    {
        mockReloadPage.mockClear();
        mockInstallPro.mockResolvedValue({ ok: true, message: 'Installed.' });
        setProState({ connected: true, entitled: true, installed: false });
        const { container, root } = renderHeader();

        const button = Array.from(container.querySelectorAll('button')).find(b => (b.textContent ?? '').trim() === 'Install Pro');
        await act(async () =>
        {
            button?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
            await new Promise(resolve => setTimeout(resolve, 0));
        });

        expect(mockInstallPro).toHaveBeenCalledTimes(1);
        expect(mockReloadPage).toHaveBeenCalledTimes(1);
        act(() => root.unmount());
    });
});
