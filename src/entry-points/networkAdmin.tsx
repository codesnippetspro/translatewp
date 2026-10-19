import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import type { NetworkAdminData } from '@/apps/network-admin/types';
import { NetworkAdmin } from '@/apps/network-admin/NetworkAdmin';

import '@/apps/network-admin/style.css';

const queryClient = new QueryClient({ defaultOptions: { queries: { retry: 1 } } });

declare global {
    interface Window {
        TranslateWPNetworkAdmin: NetworkAdminData;
    }
}

domReady(() =>
{
    const container = document.getElementById('translatewp-network-admin');
    if (container && window.TranslateWPNetworkAdmin) {
        createRoot(container).render(
            <QueryClientProvider client={queryClient}>
                <NetworkAdmin data={window.TranslateWPNetworkAdmin} />
            </QueryClientProvider>
        );
    }
});
