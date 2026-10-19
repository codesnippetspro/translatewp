import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

import { QueryClient } from '@tanstack/react-query';
import { PersistQueryClientProvider } from '@tanstack/react-query-persist-client'
import { createAsyncStoragePersister } from '@tanstack/query-async-storage-persister'

import { persistedQueryStorageKey, queryPersistDehydrateOptions } from '@/shared/lib/queryPersistOptions';
import { installRequestTimeout } from '@/shared/lib/requestTimeout';

import { installHostBridge } from '@/shared/host';

import { Admin } from '@/apps/admin/Admin';

import '@/apps/admin/style.css';

installHostBridge();
installRequestTimeout();

// Every request is a same-origin WordPress REST call reachable while this screen
// is open. 'online' mode would pause queries and mutations with no request and
// no error whenever navigator.onLine misreports the wp-admin shell as offline.
const queryClient = new QueryClient({
    defaultOptions: {
        queries: { networkMode: 'always', retry: 1 },
        mutations: { networkMode: 'always' },
    },
})

const persister = createAsyncStoragePersister({
    storage: window.localStorage,
    key: persistedQueryStorageKey(),
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
            <Admin />
        </PersistQueryClientProvider>
    );
}

domReady(() =>
{
    const container = document.getElementById('translatewp-admin');

    if (container) {
        const root = createRoot(container);
        root.render(
            <App />
        );
    }
})