import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { SETTINGS_TABS, SettingsPage } from '@/apps/admin/components/SettingsPage';
import { DASHBOARD_TABS } from '@/apps/admin/components/Dashboard/DashboardPage';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@/shared/query-options/connect', () => ({
    getConnectStatusQueryOptions: () => ({ queryKey: ['connectStatus'], queryFn: async () => ({ connected: true, pro: { entitled: false } }) }),
}));

const roots: Root[] = [];

const renderSettings = async (hash: string): Promise<HTMLElement> =>
{
    window.history.replaceState(null, '', `/wp-admin/admin.php?page=translatewp-settings${hash}`);
    useAdminSettingsStore.setState({ isInitialized: true, isLoading: false });
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    roots.push(root);
    await act(async () =>
    {
        root.render(<QueryClientProvider client={new QueryClient()}><SettingsPage /></QueryClientProvider>);
    });
    return container;
};

describe('Settings page tabs', () =>
{
    afterEach(async () =>
    {
        await act(async () => { for (const root of roots.splice(0)) root.unmount(); });
        document.body.innerHTML = '';
        window.history.replaceState(null, '', '/wp-admin/admin.php?page=translatewp');
    });

    it('registers the six settings tabs and the two dashboard tabs by their ids', () =>
    {
        expect(SETTINGS_TABS.map(tab => tab.name)).toEqual(['languages', 'translation', 'switcher', 'connection', 'advanced', 'ai-model']);
        expect(DASHBOARD_TABS.map(tab => tab.name)).toEqual(['overview', 'indexing', 'support']);
    });

    it('opens the tab named in the hash', async () =>
    {
        const container = await renderSettings('#advanced');
        expect(container.textContent).toContain('Danger zone');
        expect(container.textContent).not.toContain('Visitor language');
    });

    it('explains other translation plugins on the Advanced tab the coexistence notice links to', async () =>
    {
        const container = await renderSettings('#advanced');
        const section = container.querySelector('#other-translation-plugins');
        expect(section?.textContent).toContain('Other translation plugins');
        expect(section?.textContent).toContain('one translation system per site');
    });

    it('defaults to the Languages tab with the default language control and the visitor language section', async () =>
    {
        const container = await renderSettings('');
        expect(container.textContent).toContain('The language your content is written in');
        expect(container.textContent).toContain('Translation languages');
        expect(container.textContent).toContain('Visitor language');
        expect(container.textContent).toContain('Auto-detect browser language');
    });
});
