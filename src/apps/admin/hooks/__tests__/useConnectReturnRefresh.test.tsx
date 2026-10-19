import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { useConnectReturnRefresh } from '@/apps/admin/hooks/useConnectReturnRefresh';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const Probe = () =>
{
    useConnectReturnRefresh();
    return null;
};

const renderWithClient = async (client: QueryClient): Promise<Root> =>
{
    const container = document.createElement('div');
    const root = createRoot(container);
    await act(async () =>
    {
        root.render(
            <QueryClientProvider client={client}>
                <Probe />
            </QueryClientProvider>
        );
    });
    return root;
};

describe('useConnectReturnRefresh', () =>
{
    afterEach(() =>
    {
        window.history.replaceState(null, '', '/');
    });

    it('refreshes the connect status when the connect-return notice is present', async () =>
    {
        window.history.replaceState(null, '', '/wp-admin/admin.php?page=translatewp&translatewp_connect_notice=connected');
        const client = new QueryClient();
        const invalidate = jest.spyOn(client, 'invalidateQueries');

        const root = await renderWithClient(client);

        expect(invalidate).toHaveBeenCalledWith({ queryKey: ['connectStatus'], refetchType: 'all' });

        await act(async () =>
        {
            root.unmount();
        });
    });

    it('leaves the cache alone without the connect-return notice', async () =>
    {
        window.history.replaceState(null, '', '/wp-admin/admin.php?page=translatewp');
        const client = new QueryClient();
        const invalidate = jest.spyOn(client, 'invalidateQueries');

        const root = await renderWithClient(client);

        expect(invalidate).not.toHaveBeenCalled();

        await act(async () =>
        {
            root.unmount();
        });
    });
});
