import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { NetworkAdmin } from '@/apps/network-admin/NetworkAdmin';
import { networkAdminData } from '@/apps/network-admin/__fixtures__/networkAdminData';
import type { NetworkAdminData } from '@/apps/network-admin/types';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root | null = null;

const render = async (data: NetworkAdminData, hash = '#settings'): Promise<HTMLElement> =>
{
    window.location.hash = hash;
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () =>
    {
        root!.render(<QueryClientProvider client={new QueryClient()}><NetworkAdmin data={data} /></QueryClientProvider>);
    });
    return container;
};

const withOutcome: NetworkAdminData = {
    ...networkAdminData,
    connection: {
        ...networkAdminData.connection,
        outcome: [
            { host: 'editorial.example.com', name: 'Editorial', ok: true, reconnected: false, reason: '', retry: false },
            { host: 'news.example.com', name: 'News', ok: true, reconnected: true, reason: '', retry: false },
            { host: 'shop.example.com', name: 'Shop', ok: false, reconnected: false, reason: 'The connection code expired or was already used.', retry: true },
            { host: 'evil.example.com', name: 'evil.example.com', ok: false, reconnected: false, reason: 'This address is not a subsite of this network, so it was not connected.', retry: false },
        ],
    },
};

describe('the network connect run outcome', () =>
{
    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
        window.history.replaceState(null, '', '/');
    });

    it('names each subsite it connected', async () =>
    {
        const container = await render(withOutcome);
        const notice = container.querySelector('[data-connect-outcome]') as HTMLElement;
        expect(notice.textContent).toContain('Editorial: connected');
        expect(notice.textContent).toContain('News: reconnected, translations kept');
    });

    it('says why a subsite failed and offers to try that subsite again', async () =>
    {
        const container = await render(withOutcome);
        const notice = container.querySelector('[data-connect-outcome]') as HTMLElement;
        expect(notice.textContent).toContain('Shop: not connected. The connection code expired or was already used.');
        const retry = Array.from(notice.querySelectorAll('button')).find(button => button.textContent === 'Try again for Shop') as HTMLButtonElement;
        const form = retry.closest('form') as HTMLFormElement;
        expect((form.querySelector('input[name="only_hosts"]') as HTMLInputElement).value).toBe('shop.example.com');
        expect((form.querySelector('input[name="action"]') as HTMLInputElement).value).toBe('connect');
        expect(notice.textContent).toContain('This address is not a subsite of this network');
        expect(Array.from(notice.querySelectorAll('button')).map(button => button.textContent)).not.toContain('Try again for evil.example.com');
    });

    it('shows no notice when there is no run to report, and never a Dismiss results action', async () =>
    {
        const container = await render(networkAdminData, '#subsites');
        expect(container.querySelector('[data-connect-outcome]')).toBeNull();
        expect(container.textContent).not.toContain('Dismiss results');
        const withRun = await render(withOutcome);
        expect(withRun.textContent).not.toContain('Dismiss results');
    });

    it('shows each subsite connection state in the subsites table', async () =>
    {
        window.history.replaceState(null, '', '/wp-admin/network/admin.php?page=translatewp-network-settings#subsites');
        const container = await render(networkAdminData, '#subsites');
        expect(container.textContent).toContain('Connected');
    });
});
