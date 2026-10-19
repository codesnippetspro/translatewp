import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { NetworkAdmin } from '@/apps/network-admin/NetworkAdmin';
import { networkAdminData } from '@/apps/network-admin/__fixtures__/networkAdminData';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const data = networkAdminData;

describe('NetworkAdmin', () => {
    beforeEach(() => { window.location.hash = '#settings'; });
    afterEach(() => { document.body.innerHTML = ''; window.location.hash = ''; });

    it('shows the entitled Pro installation action on the Overview', async () => {
        window.location.hash = '#overview';
        const container = document.createElement('div');
        document.body.appendChild(container);
        await act(async () => {
            createRoot(container).render(
                <QueryClientProvider client={new QueryClient()}>
                    <NetworkAdmin data={data} />
                </QueryClientProvider>
            );
        });

        expect(container.textContent).toContain('Install Pro');
        expect(container.textContent).toContain('Network at a glance');
    });

    it('offers connecting every site as the primary action and one subsite as the secondary one', async () => {
        const container = document.createElement('div');
        document.body.appendChild(container);
        await act(async () => {
            createRoot(container).render(
                <QueryClientProvider client={new QueryClient()}>
                    <NetworkAdmin data={{ ...data, connection: { ...data.connection, connected: false } }} />
                </QueryClientProvider>
            );
        });

        const buttons = Array.from(container.querySelectorAll('button'));
        const connectAll = buttons.find(button => button.textContent === 'Connect all sites');
        const connectOne = buttons.find(button => button.textContent === 'Connect a subsite');
        expect(connectAll?.className).toContain('is-primary');
        expect(connectAll?.closest('form')?.querySelector('input[name="only_hosts"]')).toBeNull();
        expect(connectOne?.className).toContain('is-secondary');
    });

    it('connects only the chosen subsite from the chooser', async () => {
        const container = document.createElement('div');
        document.body.appendChild(container);
        await act(async () => {
            createRoot(container).render(
                <QueryClientProvider client={new QueryClient()}>
                    <NetworkAdmin data={data} />
                </QueryClientProvider>
            );
        });

        const connectOne = Array.from(container.querySelectorAll('button'))
            .find(button => button.textContent === 'Connect a subsite') as HTMLButtonElement;
        await act(async () => { connectOne.click(); });

        const dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(dialog.textContent).toContain('Editorial');
        const submit = Array.from(dialog.querySelectorAll('button')).find(button => button.textContent === 'Connect') as HTMLButtonElement;
        expect(submit.disabled).toBe(true);

        const choice = dialog.querySelector('input[type="radio"][value="editorial.example.com"]') as HTMLInputElement;
        await act(async () => { choice.click(); });

        expect(submit.disabled).toBe(false);
        expect((dialog.querySelector('input[name="only_hosts"]') as HTMLInputElement).value).toBe('editorial.example.com');
        expect((dialog.querySelector('input[name="action"]') as HTMLInputElement).value).toBe('connect');
    });
});
