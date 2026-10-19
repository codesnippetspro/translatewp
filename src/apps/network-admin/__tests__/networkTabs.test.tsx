import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { NetworkAdmin } from '@/apps/network-admin/NetworkAdmin';
import { networkAdminData } from '@/apps/network-admin/__fixtures__/networkAdminData';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root | null = null;

const render = async (hash = '', data = networkAdminData): Promise<HTMLElement> =>
{
    window.history.replaceState(null, '', `/wp-admin/network/admin.php?page=translatewp-network-settings${hash}`);
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () =>
    {
        root!.render(<QueryClientProvider client={new QueryClient()}><NetworkAdmin data={data} /></QueryClientProvider>);
    });
    return container;
};

const tabs = (container: HTMLElement) =>
    Array.from(container.querySelectorAll('[role="tab"]')).map(tab => tab.textContent);

const selectedTab = (container: HTMLElement) =>
    container.querySelector('[role="tab"][aria-selected="true"]')?.textContent;

describe('the network page tabs', () =>
{
    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
        window.history.replaceState(null, '', '/');
    });

    it('offers Overview, Subsites and Settings, and no Access control', async () =>
    {
        const container = await render();
        expect(tabs(container)).toEqual(['Overview', 'Sites', 'Settings']);
        expect(container.textContent).not.toContain('Access control');
    });

    it('opens on Overview with the network figures, languages, allowance and feedback, and no connection controls', async () =>
    {
        const container = await render();
        expect(selectedTab(container)).toBe('Overview');
        expect(container.textContent).toContain('Network at a glance');
        expect(container.textContent).toContain('setup complete');
        expect(container.querySelector('[data-language-counts]')).not.toBeNull();
        expect(container.textContent).toContain('Translations quota');
        expect(container.textContent).toContain('Send feedback');
        expect(container.textContent).not.toContain('Connect a subsite');
        expect(container.querySelector('a[href="/site-2/dashboard"]')).toBeNull();
        expect(container.querySelector('[data-scope-group]')).toBeNull();
    });

    it('opens the Sites tab from its link, with each site, its setup state and its links', async () =>
    {
        const container = await render('#subsites');
        expect(selectedTab(container)).toBe('Sites');
        expect(container.querySelector('[data-setup-state="done"]')?.textContent).toContain('Done');
        const pending = await render('#subsites', { ...networkAdminData, subsites: [{ ...networkAdminData.subsites[0], setupEnded: false }] });
        expect(pending.querySelector('a[data-setup-state="complete"]')?.getAttribute('href')).toBe('/site-2/dashboard');
        expect(container.textContent).toContain('Editorial');
        expect(container.textContent).toContain('Connected');
        expect(container.querySelector('a[href="/site-2/dashboard"]')).not.toBeNull();
        expect(container.querySelector('a[href="/site-2/seo"]')).not.toBeNull();
        expect(container.querySelector('a[href="/site-2/lexicon"]')).not.toBeNull();
    });

    it('opens the Settings tab from its link, with the settings scope groups', async () =>
    {
        const container = await render('#settings');
        expect(selectedTab(container)).toBe('Settings');
        expect(container.querySelector('[data-scope-group="switcher_appearance"]')).not.toBeNull();
        expect(container.textContent).toContain('The account connection');
        expect(container.textContent).toContain('Connect a subsite');
    });

    it('moves between tabs and keeps the choice in the address', async () =>
    {
        const container = await render();
        const settings = Array.from(container.querySelectorAll('[role="tab"]')).find(tab => tab.textContent === 'Settings') as HTMLButtonElement;
        await act(async () => { settings.click(); });
        expect(window.location.hash).toBe('#settings');
        expect(selectedTab(container)).toBe('Settings');
    });
});
