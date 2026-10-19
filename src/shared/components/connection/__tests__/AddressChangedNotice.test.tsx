import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { AddressChangedNotice } from '@/shared/components/connection/AddressChangedNotice';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockGetConnectStatus = jest.fn();
const mockBeginConnect = jest.fn();
const mockReconnectSite = jest.fn();

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockGetConnectStatus(...args),
    beginConnect: (...args: unknown[]) => mockBeginConnect(...args),
    reconnectSite: (...args: unknown[]) => mockReconnectSite(...args),
}));

const CONNECTED_BASE = {
    connected: true,
    siteId: 'site-1',
    accountLabel: 'ops@example.com',
    registeredUrl: 'https://beispiel-alt.example.com',
    portalSitesUrl: '',
    portalSiteUrl: '',
};

const renderNotice = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await act(async () =>
    {
        createRoot(container).render(
            <QueryClientProvider client={queryClient}>
                <AddressChangedNotice />
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

describe('AddressChangedNotice', () =>
{
    beforeEach(() =>
    {
        mockGetConnectStatus.mockReset();
        mockBeginConnect.mockReset();
        mockReconnectSite.mockReset();
    });

    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    it('renders nothing when the address has not changed', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({ ...CONNECTED_BASE, addressChange: null });

        const container = await renderNotice();

        expect(container.querySelector('[data-testid="address-changed-notice"]')).toBeNull();
    });

    it('names the old and new address with the two settle actions', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({
            ...CONNECTED_BASE,
            addressChange: { from: 'https://beispiel-alt.example.com', to: 'https://esempio-nuovo.example.net' },
        });

        const container = await renderNotice();

        expect(container.textContent).toContain('https://beispiel-alt.example.com');
        expect(container.textContent).toContain('https://esempio-nuovo.example.net');
        expect(container.textContent).toContain('Translation keeps running until you choose.');
        expect(findButton(container, 'Reconnect this site')).toBeDefined();
        expect(findButton(container, 'Connect as a new site')).toBeDefined();
    });

    it('reconnects the same site with the claim and follows the redirect', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({
            ...CONNECTED_BASE,
            addressChange: { from: 'https://beispiel-alt.example.com', to: 'https://esempio-nuovo.example.net' },
        });
        mockReconnectSite.mockResolvedValue({ redirectUrl: 'https://portal.example/approve' });

        const container = await renderNotice();

        await act(async () =>
        {
            (findButton(container, 'Reconnect this site') as HTMLButtonElement).click();
        });
        await act(async () =>
        {
            await new Promise((resolve) => setTimeout(resolve, 0));
        });

        expect(mockReconnectSite).toHaveBeenCalled();
        expect(mockBeginConnect).not.toHaveBeenCalled();
        expect(console).toHaveErrored();
    });

    it('connects a new site with a plain connect and follows the redirect', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({
            ...CONNECTED_BASE,
            addressChange: { from: 'https://beispiel-alt.example.com', to: 'https://esempio-nuovo.example.net' },
        });
        mockBeginConnect.mockResolvedValue({ redirectUrl: 'https://portal.example/new-site' });

        const container = await renderNotice();

        await act(async () =>
        {
            (findButton(container, 'Connect as a new site') as HTMLButtonElement).click();
        });
        await act(async () =>
        {
            await new Promise((resolve) => setTimeout(resolve, 0));
        });

        expect(mockBeginConnect).toHaveBeenCalled();
        expect(mockReconnectSite).not.toHaveBeenCalled();
        expect(console).toHaveErrored();
    });

    it('disables both actions while a connect is in flight', async () =>
    {
        mockGetConnectStatus.mockResolvedValue({
            ...CONNECTED_BASE,
            addressChange: { from: 'https://beispiel-alt.example.com', to: 'https://esempio-nuovo.example.net' },
        });
        mockReconnectSite.mockReturnValue(new Promise(() => {}));

        const container = await renderNotice();

        await act(async () =>
        {
            (findButton(container, 'Reconnect this site') as HTMLButtonElement).click();
        });
        await act(async () =>
        {
            await new Promise((resolve) => setTimeout(resolve, 0));
        });

        expect((findButton(container, 'Reconnect this site') as HTMLButtonElement).disabled).toBe(true);
        expect((findButton(container, 'Connect as a new site') as HTMLButtonElement).disabled).toBe(true);
    });
    it('prioritizes invalidated credentials over an address change', async () => {
        mockGetConnectStatus.mockResolvedValue({ ...CONNECTED_BASE, connected: false,
            connectionStatus: 'invalidated', addressChange: { from: 'https://a.example', to: 'https://b.example' } });
        const container = await renderNotice();
        expect(container.textContent).toContain('The service no longer recognises');
        expect(container.textContent).not.toContain('Translation keeps running');
        expect(container.textContent).not.toContain('https://a.example');
        expect(findButton(container, 'Reconnect this site')).toBeDefined();
        expect(findButton(container, 'Connect as a new site')).toBeDefined();
    });

    it('shows an actionable error when invalidated recovery fails', async () => {
        mockGetConnectStatus.mockResolvedValue({ ...CONNECTED_BASE, connected: false,
            connectionStatus: 'invalidated', addressChange: null });
        mockReconnectSite.mockRejectedValue(new Error('Unavailable'));
        const container = await renderNotice();
        await act(async () => { findButton(container, 'Reconnect this site')!.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(container.querySelector('[role="alert"]')?.textContent).toContain('Connection could not be started');
        expect(findButton(container, 'Reconnect this site')!.disabled).toBe(false);
        expect(findButton(container, 'Connect as a new site')!.disabled).toBe(false);
    });

});
