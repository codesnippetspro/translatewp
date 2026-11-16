import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { ConnectReturnError } from '@/shared/components/connection/ConnectReturnError';
import { beginConnect } from '@/shared/api/connect';

jest.mock('@/shared/api/connect', () => ({ beginConnect: jest.fn(() => new Promise(() => {})) }));
(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
let root: Root;
let client: QueryClient;

const mount = async (code: string | null, message = 'Connection could not be completed.') =>
{
    Object.defineProperty(window, 'TranslateWP', { configurable: true, value: {
        connectReturnError: code ? { code, message, portalSitesUrl: 'https://app.translatewp.ai/sites',
            supportUrl: 'https://translatewp.ai/support/' } : null,
    } });
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    client = new QueryClient();
    await act(async () => { root.render(<QueryClientProvider client={client}><ConnectReturnError connected /></QueryClientProvider>); });
    return container;
};

afterEach(async () =>
{
    await act(async () => { root.unmount(); });
    client.clear();
    jest.clearAllMocks();
    document.body.innerHTML = '';
    delete (window as { TranslateWP?: unknown }).TranslateWP;
});

it.each(['site_conflict', 'address_taken'])('announces %s once with account and support actions', async code =>
{
    document.body.innerHTML = '<div id="translatewp-connect-return-notice">Connection could not be completed.</div>';
    const container = await mount(code);
    expect(document.querySelector('#translatewp-connect-return-notice')).toBeNull();
    const alert = container.querySelector('[role="alert"]')!;
    expect(alert.textContent).toContain('Connection could not be completed.');
    expect(alert.querySelector('a[href="https://app.translatewp.ai/sites"]')?.textContent).toBe('Open your sites list');
    expect(alert.querySelector('a[href="https://translatewp.ai/support/"]')?.textContent).toBe('Contact support');
    expect(alert.querySelector('button')).toBeNull();
});

it.each(['remote_error', 'invalid_state', 'access_denied', 'invalid_request', 'validation_error',
    'invalid_connect_code', 'rate_limited', 'connect_unavailable'])(
    'offers retry for %s and clears the old message before the next request returns', async code =>
    {
        const container = await mount(code);
        const retry = container.querySelector('button')!;
        expect(retry.textContent).toBe('Try again');
        await act(async () => { retry.click(); });
        expect(beginConnect).toHaveBeenCalledTimes(1);
        expect(container.querySelector('[role="alert"]')).toBeNull();
    },
);

it('keeps support-only errors as plain text with no injected markup', async () =>
{
    const message = '<img src=x onerror="alert(1)"> Permission denied.';
    const container = await mount('permission_denied', message);
    expect(container.querySelector('[data-connect-error-message]')?.textContent).toBe(message);
    expect(container.querySelector('img')).toBeNull();
    expect(container.querySelector('button')).toBeNull();
    expect(container.querySelector('a')?.textContent).toBe('Contact support');
});

it('retains the existing success notice without creating an error alert', async () =>
{
    document.body.innerHTML = '<div id="translatewp-connect-return-notice" class="notice-success">Connected</div>';
    const container = await mount(null);
    expect(document.querySelector('.notice-success')?.textContent).toBe('Connected');
    expect(container.querySelector('[role="alert"]')).toBeNull();
});
