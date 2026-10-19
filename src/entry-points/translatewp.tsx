import { createRoot } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';

import { QueryClient } from '@tanstack/react-query';
import { PersistQueryClientProvider } from '@tanstack/react-query-persist-client'
import { createAsyncStoragePersister } from '@tanstack/query-async-storage-persister'

import { TranslateWP } from '@/apps/translatewp';
import { AdminSetupSwitcher } from '@/apps/translatewp/AdminSetupSwitcher';
import { installFrontendHostBridge } from '@/shared/host/frontend';
import { enterEditModeFromUrl } from '@/apps/translatewp/editMode';

import '@/apps/translatewp/style.css';
import { opensEditMode } from '@/apps/translatewp/managerAccess';

const queryClient = new QueryClient();

const persister = createAsyncStoragePersister({
    storage: window.localStorage,
});

const App = () =>
{
    return (
        <PersistQueryClientProvider client={queryClient} persistOptions={{ persister }}>
            <TranslateWP />
        </PersistQueryClientProvider>
    )
}

// The Pro front-end editor attaches through this bridge; it must
// exist before the Pro bundle (which depends on this one) evaluates.
installFrontendHostBridge();

domReady(() =>
{
    // The "Open the site in edit mode" link in Settings > Switcher.
    enterEditModeFromUrl(opensEditMode());

    const container = document.createElement('div');
    container.id = 'translatewp';

    document.body.appendChild(container);

    const root = createRoot(container);
    root.render(
        window.TranslateWP?.context?.setup_notice ? <AdminSetupSwitcher /> : <App />
    );
})