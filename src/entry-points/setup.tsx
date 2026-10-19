import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

import { QueryClient } from '@tanstack/react-query';
import { PersistQueryClientProvider } from '@tanstack/react-query-persist-client'
import { createAsyncStoragePersister } from '@tanstack/query-async-storage-persister'

import { queryPersistDehydrateOptions } from '@/shared/lib/queryPersistOptions';
import { installRequestTimeout } from '@/shared/lib/requestTimeout';

import { Setup } from '@/apps/setup';

import '@/apps/setup/style.css';

/**
 * Query Client
 */

installRequestTimeout();

const queryClient = new QueryClient({ defaultOptions: { queries: { retry: 1 } } })

const persister = createAsyncStoragePersister({
    storage: window.localStorage,
})

const App = () =>
{
    return (
        <PersistQueryClientProvider
            client={queryClient}
            persistOptions={{
                persister,
                dehydrateOptions: queryPersistDehydrateOptions,
            }}
        >
            <Setup />
        </PersistQueryClientProvider>
    )
}

domReady(() =>
{
    const container = document.getElementById('translatewp-setup');

    if (container) {
        const root = createRoot(container);
        root.render(
            <App />
        );
    }
});