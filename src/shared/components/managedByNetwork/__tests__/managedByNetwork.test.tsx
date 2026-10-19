import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { ManagedByNetwork } from '@/shared/components/managedByNetwork';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root | null = null;

const setContext = (context: Record<string, unknown>) =>
{
    (window as unknown as { TranslateWP: unknown }).TranslateWP = { context };
};

const render = (): HTMLElement =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    act(() => root!.render(
        <ManagedByNetwork group="switcher_appearance">
            <input type="checkbox" aria-label="Show flag icon" />
        </ManagedByNetwork>
    ));
    return container;
};

describe('ManagedByNetwork', () =>
{
    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
        delete (window as unknown as { TranslateWP?: unknown }).TranslateWP;
    });

    it('leaves an unlocked group editable with no note', () =>
    {
        setContext({ settings_scope: { switcher_appearance: false } });
        const container = render();
        expect(container.textContent).not.toContain('Managed by your network admin');
        expect(container.querySelector('input')!.matches(':disabled')).toBe(false);
    });

    it('shows nothing on a single site', () =>
    {
        setContext({ settings_scope: {} });
        const container = render();
        expect(container.textContent).toBe('');
        expect(container.querySelector('input')!.matches(':disabled')).toBe(false);
    });

    it('renders a locked group read-only with the note and a link for someone who can reach the network page', () =>
    {
        setContext({ settings_scope: { switcher_appearance: true }, network_settings_url: 'https://example.com/wp-admin/network/admin.php?page=translatewp-network-settings' });
        const container = render();
        expect(container.textContent).toContain('Managed by your network admin');
        expect(container.querySelector('input')!.matches(':disabled')).toBe(true);
        expect(container.querySelector('a')?.getAttribute('href')).toBe('https://example.com/wp-admin/network/admin.php?page=translatewp-network-settings');
    });

    it('shows no link to someone who cannot reach the network page', () =>
    {
        setContext({ settings_scope: { switcher_appearance: true }, network_settings_url: '' });
        const container = render();
        expect(container.textContent).toContain('Managed by your network admin');
        expect(container.querySelector('a')).toBeNull();
    });
});
