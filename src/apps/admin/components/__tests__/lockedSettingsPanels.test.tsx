import type { ReactNode } from 'react';
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

jest.mock('@wordpress/api-fetch', () => jest.fn(() => new Promise(() => {})));

const mockGetConnectStatus = jest.fn();
jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockGetConnectStatus(...args),
    beginConnect: jest.fn(),
    disconnectSite: jest.fn(),
    installPro: jest.fn(),
    activatePro: jest.fn(),
    reconnectSite: jest.fn(),
}));

import { LanguagesSwitcher } from '@/apps/admin/components/Languages/Switcher';
import { SettingsAdvanced } from '@/apps/admin/components/Settings/Advanced';
import { TranslationsSettings } from '@/apps/admin/components/Translations/Settings';
import { TranslationsStyle } from '@/apps/admin/components/Translations/Style';
import { VisitorLanguage } from '@/apps/admin/components/SettingsPage/VisitorLanguage';
import { ConnectionPanel } from '@/shared/components/connection/ConnectionPanel';
import { AddressChangedNotice } from '@/shared/components/connection/AddressChangedNotice';
import { LanguagesCard } from '@/apps/admin/components/Dashboard/cards';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const ALL_LOCKED = {
    account: true, uninstall: true, ai_crawlers: true,
    switcher_appearance: true, seo_technical: true, translation_behaviour: true, visitor_language: true, style_tone: true,
};

let root: Root | null = null;

const setScope = (scope: Record<string, boolean>) =>
{
    (window as unknown as { TranslateWP: unknown }).TranslateWP = { context: { settings_scope: scope, network_settings_url: '' } };
};

const render = async (element: ReactNode): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () =>
    {
        root!.render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{element}</QueryClientProvider>);
    });
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
    return container;
};

const toggle = (container: HTMLElement, label: string) =>
{
    const labelNode = Array.from(container.querySelectorAll('label')).find(node => (node.textContent ?? '').includes(label));
    const id = labelNode?.getAttribute('for');
    return (id ? document.getElementById(id) : labelNode?.querySelector('input')) as HTMLInputElement | null;
};

const connectedStatus = {
    connected: true, connectionStatus: 'connected', siteId: 'site-2', accountLabel: 'owner@example.com',
    registeredUrl: '', addressChange: null, portalSitesUrl: 'https://portal.example.test/sites', portalSiteUrl: '',
};

describe('locked settings render read-only for a subsite admin', () =>
{
    beforeEach(() =>
    {
        useAdminSettingsStore.setState({ isInitialized: false } as never);
        useAdminSettingsStore.getState().initializeFromApiData({
            languagesList: [], switcher: {}, seo: {}, translationsSettings: { translate_all_content: true }, translationsContext: {},
            translationsStyle: {}, setup: {}, advanced: {},
        });
        mockGetConnectStatus.mockResolvedValue(connectedStatus);
    });

    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
        delete (window as unknown as { TranslateWP?: unknown }).TranslateWP;
    });

    it('locks the switcher appearance', async () =>
    {
        setScope(ALL_LOCKED);
        const container = await render(<LanguagesSwitcher />);
        expect(container.textContent).toContain('Managed by your network admin');
        expect(toggle(container, 'Show flag icon')?.matches(':disabled')).toBe(true);
    });

    it('locks the uninstall rule and the reset that disconnects the account', async () =>
    {
        setScope(ALL_LOCKED);
        const container = await render(<SettingsAdvanced />);
        expect(toggle(container, 'Delete all data on uninstall')?.matches(':disabled')).toBe(true);
        const reset = Array.from(container.querySelectorAll('button')).find(button => button.textContent === 'Reset all plugin data');
        expect(reset?.matches(':disabled')).toBe(true);
    });

    it('locks the translation behaviour but keeps the content selection editable', async () =>
    {
        setScope(ALL_LOCKED);
        const container = await render(<TranslationsSettings />);
        expect(toggle(container, 'Preserve branded terms')?.matches(':disabled')).toBe(true);
        expect(toggle(container, 'Translate all public content')?.matches(':disabled')).toBe(false);
    });

    it('locks the visitor language and the style and tone', async () =>
    {
        setScope(ALL_LOCKED);
        const visitor = await render(<VisitorLanguage />);
        expect(toggle(visitor, 'Auto-detect browser language')?.matches(':disabled')).toBe(true);
        act(() => root?.unmount());
        const style = await render(<TranslationsStyle />);
        expect(style.textContent).toContain('Managed by your network admin');
        expect(Array.from(style.querySelectorAll('input, button, select')).every(node => (node as HTMLElement).matches(':disabled'))).toBe(true);
    });

    it('shows the connection read-only instead of buttons the subsite admin cannot use', async () =>
    {
        setScope(ALL_LOCKED);
        const container = await render(<ConnectionPanel />);
        expect(container.textContent).toContain('Managed by your network admin');
        expect(container.textContent).toContain('site-2');
        const labels = Array.from(container.querySelectorAll('button')).map(button => button.textContent);
        expect(labels).not.toContain('Disconnect this site');
        expect(labels).not.toContain('Connect a different account');
    });

    it('locks the Dashboard auto-detect toggle', async () =>
    {
        setScope(ALL_LOCKED);
        const container = await render(<LanguagesCard />);
        expect(container.textContent).toContain('Managed by your network admin');
        expect(toggle(container, 'Auto-detect browser language')?.matches(':disabled')).toBe(true);
    });

    it('explains an invalidated connection without reconnect buttons the subsite admin cannot use', async () =>
    {
        setScope(ALL_LOCKED);
        mockGetConnectStatus.mockResolvedValue({ ...connectedStatus, connected: false, connectionStatus: 'invalidated' });
        const container = await render(<AddressChangedNotice />);
        expect(container.textContent).toContain('no longer recognises');
        expect(container.textContent).toContain('Managed by your network admin');
        const labels = Array.from(container.querySelectorAll('button')).map(button => button.textContent);
        expect(labels).not.toContain('Reconnect this site');
        expect(labels).not.toContain('Connect as a new site');
    });

    it('keeps every control editable when nothing is locked', async () =>
    {
        setScope({});
        const settings = await render(<TranslationsSettings />);
        expect(toggle(settings, 'Preserve branded terms')?.matches(':disabled')).toBe(false);
        act(() => root?.unmount());
        const connection = await render(<ConnectionPanel />);
        expect(Array.from(connection.querySelectorAll('button')).map(button => button.textContent)).toContain('Disconnect this site');
        expect(connection.textContent).not.toContain('Managed by your network admin');
    });

    it('offers no setting that turns translation off for visitors', async () =>
    {
        setScope({});
        const settings = await render(<TranslationsSettings />);
        expect(settings.textContent).not.toContain('Translate the site for visitors');
        expect(settings.textContent).not.toContain('default language only');
        act(() => root?.unmount());
        const advanced = await render(<SettingsAdvanced />);
        expect(advanced.textContent).not.toContain('Translate the site for visitors');
        expect(advanced.textContent).not.toMatch(/pause translation/i);
    });
});
