import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { ConnectSiteButton } from '@/shared/components/connection/ConnectionPanel';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockBeginConnect = jest.fn();
const mockFramed = jest.fn();

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: jest.fn().mockResolvedValue({ connected: false }),
    beginConnect: (...args: unknown[]) => mockBeginConnect(...args),
    reconnectSite: jest.fn(),
}));

const mockAssign = jest.fn();

jest.mock('@/shared/lib/connect/framed', () => ({
    isFramed: () => mockFramed(),
    assignLocation: (url: string) => mockAssign(url),
}));

const CONNECT_URL = 'https://app.translatewp.ai/connect?host=playground.wordpress.net&state=one-use';

const render = async (): Promise<{ container: HTMLElement; queryClient: QueryClient }> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await act(async () =>
    {
        createRoot(container).render(
            <QueryClientProvider client={queryClient}>
                <ConnectSiteButton label="Connect your site" />
            </QueryClientProvider>
        );
    });
    return { container, queryClient };
};

const click = async (container: HTMLElement) =>
{
    const button = container.querySelector('button') as HTMLButtonElement;
    await act(async () => { button.click(); });
    await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)); });
};

describe('Connecting from a framed admin (WordPress Playground)', () =>
{
    const assign = mockAssign;
    let open: jest.SpyInstance;

    beforeEach(() =>
    {
        mockBeginConnect.mockReset().mockResolvedValue({ redirectUrl: CONNECT_URL });
        mockFramed.mockReset();
        mockAssign.mockReset();
        open = jest.spyOn(window, 'open');
    });

    afterEach(() =>
    {
        open.mockRestore();
        document.body.innerHTML = '';
    });

    it('keeps the same-tab redirect on an ordinary site', async () =>
    {
        mockFramed.mockReturnValue(false);
        const { container } = await render();

        await click(container);

        expect(open).not.toHaveBeenCalled();
        expect(assign).toHaveBeenCalledWith(CONNECT_URL);
    });

    it('opens sign-in in a new top-level tab during the click and never navigates the frame', async () =>
    {
        mockFramed.mockReturnValue(true);
        const tab = { closed: false, opener: {} as unknown, location: { href: '' } };
        open.mockReturnValue(tab as unknown as Window);
        const { container } = await render();

        await click(container);

        expect(open).toHaveBeenCalledTimes(1);
        expect(open).toHaveBeenCalledWith('', '_blank');
        expect(tab.opener).toBeNull();
        expect(tab.location.href).toBe(CONNECT_URL);
        expect(assign).not.toHaveBeenCalled();
        expect(container.textContent).toContain('Finish connecting in the new tab');
    });

    it('points the tab at sign-in while it is still this page\'s popup, then cuts the opener', async () =>
    {
        mockFramed.mockReturnValue(true);
        const steps: string[] = [];
        let opener: unknown = {};
        const tab = {
            closed: false,
            get opener() { return opener; },
            set opener(value: unknown) { steps.push('sever'); opener = value; },
            location: {
                set href(value: string) { steps.push(null === opener ? 'navigate-after-sever' : 'navigate'); this.value = value; },
                get href() { return (this as { value?: string }).value ?? ''; },
            } as { href: string; value?: string },
        };
        open.mockReturnValue(tab as unknown as Window);
        const { container } = await render();

        await click(container);

        expect(steps).toEqual(['navigate', 'sever']);
        expect(tab.location.href).toBe(CONNECT_URL);
        expect(opener).toBeNull();
    });

    it('closes the blank tab and offers the link when the browser refuses to navigate it', async () =>
    {
        mockFramed.mockReturnValue(true);
        const close = jest.fn();
        const tab = {
            closed: false,
            opener: {},
            close,
            location: { set href(_value: string) { throw new DOMException('The current window does not have permission to navigate the target frame', 'SecurityError'); } },
        };
        open.mockReturnValue(tab as unknown as Window);
        const { container } = await render();

        await click(container);

        expect(close).toHaveBeenCalled();
        const link = container.querySelector('a[target="_blank"]') as HTMLAnchorElement;
        expect(link?.href).toBe(CONNECT_URL);
        expect(container.textContent).not.toContain('Finish connecting in the new tab');
    });

    it('offers a plain link to the same sign-in page when the browser blocks the tab', async () =>
    {
        mockFramed.mockReturnValue(true);
        open.mockReturnValue(null);
        const { container } = await render();

        await click(container);

        const link = container.querySelector('a[target="_blank"]') as HTMLAnchorElement;
        expect(link).not.toBeNull();
        expect(link.href).toBe(CONNECT_URL);
        expect(link.rel).toContain('noopener');
        expect(assign).not.toHaveBeenCalled();
    });

    it('closes the opened tab and shows the error when the connection cannot start', async () =>
    {
        mockFramed.mockReturnValue(true);
        mockBeginConnect.mockRejectedValue(new Error('nope'));
        const tab = { closed: false, opener: {}, location: { href: '' }, close: jest.fn() };
        open.mockReturnValue(tab as unknown as Window);
        const { container } = await render();

        await click(container);

        expect(tab.close).toHaveBeenCalled();
        expect(container.textContent).toContain('Connection could not be started. Try again.');
    });

    it('rechecks the connection after the blocked-tab link is used and the admin comes back', async () =>
    {
        mockFramed.mockReturnValue(true);
        open.mockReturnValue(null);
        const { container, queryClient } = await render();
        const invalidate = jest.spyOn(queryClient, 'invalidateQueries');

        await click(container);
        await act(async () => { document.dispatchEvent(new Event('visibilitychange')); });

        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['connectStatus'] });
    });

    it('rechecks the connection when the admin comes back to this tab', async () =>
    {
        mockFramed.mockReturnValue(true);
        open.mockReturnValue({ closed: false, opener: {}, location: { href: '' } } as unknown as Window);
        const { container, queryClient } = await render();
        const invalidate = jest.spyOn(queryClient, 'invalidateQueries');

        await click(container);
        await act(async () => { document.dispatchEvent(new Event('visibilitychange')); });

        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['connectStatus'] });
    });
});
