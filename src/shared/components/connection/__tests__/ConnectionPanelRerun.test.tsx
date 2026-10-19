import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { ConnectionPanel } from '@/shared/components/connection/ConnectionPanel';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockGetConnectStatus = jest.fn();
const mockBeginConnect = jest.fn();
const mockDisconnectSite = jest.fn();
const mockInstallPro = jest.fn();
const mockActivatePro = jest.fn();

jest.mock('@/shared/lib/reloadPage', () => ({ reloadPage: jest.fn() }));
jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockGetConnectStatus(...args),
    beginConnect: (...args: unknown[]) => mockBeginConnect(...args),
    disconnectSite: (...args: unknown[]) => mockDisconnectSite(...args),
    installPro: (...args: unknown[]) => mockInstallPro(...args),
    activatePro: (...args: unknown[]) => mockActivatePro(...args),
}));

const renderPanel = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await act(async () =>
    {
        createRoot(container).render(
            <QueryClientProvider client={queryClient}>
                <ConnectionPanel />
            </QueryClientProvider>
        );
    });
    await act(async () =>
    {
        await new Promise((resolve) => setTimeout(resolve, 0));
    });
    return container;
};

const findButton = (container: HTMLElement, label: string): HTMLButtonElement | undefined =>
    Array.from(container.querySelectorAll('button'))
        .find((button) => (button.textContent ?? '').trim() === label) as HTMLButtonElement | undefined;

describe('ConnectionPanel connected re-run', () =>
{
    beforeEach(() =>
    {
        mockGetConnectStatus.mockReset();
        mockBeginConnect.mockReset();
        mockDisconnectSite.mockReset();
    });

    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    it('shows exactly one different-account action that disconnects then begins a fresh connect', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({ connected: true, siteId: 'site-1', accountLabel: 'ops@translatewp.ai', portalSitesUrl: '' });
        mockDisconnectSite.mockResolvedValue({ success: true });
        mockBeginConnect.mockResolvedValue({ redirectUrl: 'https://portal.example/approve' });

        const container = await renderPanel();

        const differentAccountButtons = Array.from(container.querySelectorAll('button'))
            .filter((button) => (button.textContent ?? '').trim() === 'Connect a different account');
        expect(differentAccountButtons).toHaveLength(1);

        await act(async () =>
        {
            (differentAccountButtons[0] as HTMLButtonElement).click();
        });

        expect(document.body.textContent).toContain('Connect a different account?');
        expect(mockDisconnectSite).not.toHaveBeenCalled();

        await act(async () =>
        {
            (findButton(document.body, 'Continue') as HTMLButtonElement).click();
        });

        expect(mockDisconnectSite).toHaveBeenCalled();
        expect(mockBeginConnect).toHaveBeenCalled();
        expect(console).toHaveErrored();
    });

    it('keeps the plain disconnect action separate from the different-account action', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({ connected: true, siteId: 'site-1', accountLabel: 'ops@translatewp.ai', portalSitesUrl: '' });

        const container = await renderPanel();

        const disconnectButton = findButton(container, 'Disconnect this site');
        expect(disconnectButton).toBeDefined();

        await act(async () =>
        {
            (disconnectButton as HTMLButtonElement).click();
        });

        expect(document.body.textContent).toContain('Disconnect this site?');
        expect(mockDisconnectSite).not.toHaveBeenCalled();

        await act(async () =>
        {
            (findButton(document.body, 'Disconnect') as HTMLButtonElement).click();
        });

        expect(mockDisconnectSite).toHaveBeenCalled();
        expect(mockBeginConnect).not.toHaveBeenCalled();
    });

    it('a cancelled disconnect dialog leaves the connection alone', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({ connected: true, siteId: 'site-1', accountLabel: 'ops@translatewp.ai', portalSitesUrl: '' });

        const container = await renderPanel();

        await act(async () =>
        {
            (findButton(container, 'Disconnect this site') as HTMLButtonElement).click();
        });
        await act(async () =>
        {
            (findButton(document.body, 'Cancel') as HTMLButtonElement).click();
        });

        expect(mockDisconnectSite).not.toHaveBeenCalled();
        expect(document.body.textContent).not.toContain('Disconnect this site?');
    });

    it('installs Pro from the panel and shows the outcome in place', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({
            connected: true, siteId: 'site-1', accountLabel: 'ops@translatewp.ai', portalSitesUrl: '',
            pro: { plan: 'pro', entitled: true, installed: false, active: false, installedVersion: null, latestVersion: '1.2.0', packagePending: false, accountUrl: 'https://portal.example.test/account', upgrade: null, updateAvailable: false, updateUrl: null },
        });
        mockInstallPro.mockResolvedValue({ ok: true, code: 'installed', message: 'TranslateWP Pro is installed and active.' });

        const container = await renderPanel();
        const installButton = findButton(container, 'Install Pro');
        expect(installButton).toBeDefined();

        await act(async () =>
        {
            (installButton as HTMLButtonElement).click();
        });
        await act(async () =>
        {
            await new Promise((resolve) => setTimeout(resolve, 0));
        });

        expect(mockInstallPro).toHaveBeenCalled();
        expect(container.textContent).toContain('TranslateWP Pro is installed and active.');
    });

    it('shows the pending package text without an install action', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({
            connected: true, siteId: 'site-1', accountLabel: 'ops@translatewp.ai', portalSitesUrl: '',
            pro: { plan: 'pro', entitled: true, installed: false, active: false, installedVersion: null, latestVersion: null, packagePending: true, upgrade: null, updateAvailable: false, updateUrl: null },
        });

        const container = await renderPanel();
        expect(findButton(container, 'Install Pro')).toBeUndefined();
        expect(container.textContent).toContain('Payment received. We are confirming it');
    });

    it('offers the native updater when a newer Pro version is available', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({
            connected: true, siteId: 'site-1', accountLabel: 'ops@translatewp.ai', portalSitesUrl: '',
            pro: {
                plan: 'pro', entitled: true, installed: true, active: true, installedVersion: '0.1.0', latestVersion: '0.1.1',
                packagePending: false, accountUrl: 'https://portal.example.test/account', upgrade: null, updateAvailable: true, updateUrl: 'https://site.example/wp-admin/update.php?action=upgrade-plugin&plugin=translatewp-pro%2Ftranslatewp-pro.php&_wpnonce=abc',
            },
        });

        const container = await renderPanel();
        expect(container.textContent).toContain('Version 0.1.1 is available');
        const link = Array.from(container.querySelectorAll('a')).find((a) => (a.textContent ?? '').trim() === 'Update Pro');
        expect(link).toBeDefined();
        expect(link?.getAttribute('href')).toContain('update.php?action=upgrade-plugin');
        expect(findButton(container, 'Install Pro')).toBeUndefined();
    });

    it('hides the Pro block when nothing needs attention and only actionable states are wanted', async () =>
    {
        const { ProStatus } = await import('@/shared/components/connection/ConnectionPanel');
        const settled = { plan: 'pro', entitled: true, installed: true, active: true, installedVersion: '0.1.2', latestVersion: '0.1.2', packagePending: false, accountUrl: 'https://portal.example.test/account', upgrade: null, updateAvailable: false, updateUrl: null };
        const container = document.createElement('div');
        document.body.appendChild(container);
        const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        await act(async () =>
        {
            createRoot(container).render(
                <QueryClientProvider client={queryClient}>
                    <ProStatus pro={settled} onlyWhenActionable />
                    <ProStatus pro={{ ...settled, active: false }} onlyWhenActionable />
                </QueryClientProvider>
            );
        });
        expect(container.querySelectorAll('[data-testid="pro-status"]')).toHaveLength(1);
        expect(container.textContent).toContain('installed but not active');
    });

    it('activates an installed but inactive Pro from the panel', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({
            connected: true, siteId: 'site-1', accountLabel: 'ops@translatewp.ai', portalSitesUrl: '',
            pro: { plan: 'pro', entitled: true, installed: true, active: false, installedVersion: '0.1.2', latestVersion: '0.1.2', packagePending: false, accountUrl: 'https://portal.example.test/account', upgrade: null, updateAvailable: false, updateUrl: null },
        });
        mockActivatePro.mockResolvedValue({ ok: true, code: 'activated', message: 'TranslateWP Pro is installed and active.' });

        const container = await renderPanel();
        const activateButton = findButton(container, 'Activate Pro');
        expect(activateButton).toBeDefined();
        await act(async () =>
        {
            (activateButton as HTMLButtonElement).click();
        });
        await act(async () =>
        {
            await new Promise((resolve) => setTimeout(resolve, 0));
        });
        expect(mockActivatePro).toHaveBeenCalled();
        expect(container.textContent).toContain('TranslateWP Pro is installed and active.');
    });

    it('prefers the activate action over an available update when the addon is inactive', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({
            connected: true, siteId: 'site-1', accountLabel: 'ops@translatewp.ai', portalSitesUrl: '',
            pro: { plan: 'pro', entitled: true, installed: true, active: false, installedVersion: '0.1.2', latestVersion: '0.1.3', packagePending: false, accountUrl: 'https://portal.example.test/account', upgrade: null, updateAvailable: true, updateUrl: 'https://site.example/wp-admin/update.php?action=upgrade-plugin&_wpnonce=abc' },
        });

        const container = await renderPanel();
        expect(findButton(container, 'Activate Pro')).toBeDefined();
        expect(container.textContent).not.toContain('Update Pro');
    });

    it('renders nothing about Pro for a free account', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({
            connected: true, siteId: 'site-1', accountLabel: 'ops@translatewp.ai', portalSitesUrl: '',
            pro: { plan: 'free', entitled: false, installed: false, active: false, installedVersion: null, latestVersion: '1.2.0', packagePending: false, accountUrl: 'https://portal.example.test/account', upgrade: null, updateAvailable: false, updateUrl: null },
        });

        const container = await renderPanel();
        expect(container.querySelector('[data-testid="pro-status"]')).toBeNull();
    });
});
