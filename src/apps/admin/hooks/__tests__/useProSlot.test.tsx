import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { addFilter, removeFilter } from '@wordpress/hooks';

import { useProSlot } from '@/apps/admin/hooks/useProSlot';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@/shared/query-options/connect', () => ({
    getConnectStatusQueryOptions: () => ({ queryKey: ['connectStatus'], queryFn: async () => ({ pro: { entitled: true } }) }),
}));

const Probe = () =>
{
    const Slot = useProSlot('lateSlot');
    return <div data-testid="out">{Slot ? <Slot /> : 'gate'}</div>;
};

describe('useProSlot', () =>
{
    afterEach(() =>
    {
        removeFilter('translatewp.slot.lateSlot', 'test');
        document.body.innerHTML = '';
    });

    it('picks up a panel registered after the first render', async () =>
    {
        const container = document.createElement('div');
        document.body.appendChild(container);
        const client = new QueryClient();
        await act(async () =>
        {
            createRoot(container).render(<QueryClientProvider client={client}><Probe /></QueryClientProvider>);
        });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(container.textContent).toContain('gate');

        await act(async () =>
        {
            addFilter('translatewp.slot.lateSlot', 'test', () => () => <span>panel here</span>);
        });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
        expect(container.textContent).toContain('panel here');
    });
});
