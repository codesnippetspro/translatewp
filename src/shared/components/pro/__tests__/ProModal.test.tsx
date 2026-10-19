import type { ReactNode } from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { ProModal } from '@/shared/components/pro/ProModal';
import type { ProState } from '@/shared/lib/pro/proState';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@wordpress/components', () => ({
    Modal: ({ children }: { children: ReactNode }) => <div>{children}</div>,
    Button: ({ children, href, onClick }: { children: ReactNode; href?: string; onClick?: () => void }) =>
        href ? <a href={href}>{children}</a> : <button type="button" onClick={onClick}>{children}</button>,
}));
jest.mock('lucide-react', () => ({ Lock: () => null, Check: () => null }));

const mockInstallPro = jest.fn();
const mockActivatePro = jest.fn();
const mockGetConnectStatus = jest.fn();
jest.mock('@/shared/api/connect', () => ({
    installPro: (...args: unknown[]) => mockInstallPro(...args),
    activatePro: (...args: unknown[]) => mockActivatePro(...args),
    getConnectStatus: (...args: unknown[]) => mockGetConnectStatus(...args),
}));

const UPGRADE_URL = 'https://example.test/upgrade';

const base: ProState = {
    connected: true,
    entitled: true,
    installed: false,
    active: false,
    version: '',
    upgradeUrl: UPGRADE_URL,
    upgradeStage: null,
};

const render = (state: ProState): { container: HTMLElement; unmount: () => void } =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    act(() => root.render(<ProModal isOpen onClose={() => {}} feature="Custom styles" state={state} />));

    return { container, unmount: () => act(() => root.unmount()) };
};

const buttonLabels = (container: HTMLElement): string[] =>
    Array.from(container.querySelectorAll('button')).map(button => (button.textContent ?? '').trim());

describe('ProModal', () =>
{
    afterEach(() =>
    {
        document.body.innerHTML = '';
        jest.clearAllMocks();
        delete (window as unknown as { translatewpPro?: unknown }).translatewpPro;
    });

    it('asks the plugin for a fresh account state when opened without one, so an account that bought Pro since the page loaded is offered the install, not an upgrade', async () =>
    {
        (window as unknown as { translatewpPro?: unknown }).translatewpPro = { connected: true, entitled: false, installed: false, active: false, version: '', upgradeUrl: UPGRADE_URL };
        mockGetConnectStatus.mockResolvedValue({ connected: true, pro: { entitled: true, installed: false, active: false, installedVersion: null, upgrade: { stage: null } } });

        const container = document.createElement('div');
        document.body.appendChild(container);
        const root = createRoot(container);
        await act(async () => { root.render(<ProModal isOpen onClose={() => {}} feature="Legal" />); });
        await act(async () => { await Promise.resolve(); });

        expect(mockGetConnectStatus).toHaveBeenCalledWith({ refresh: true });
        expect(container.textContent).toContain('Your account includes Pro');
        expect(buttonLabels(container)).toContain('Install now');
        expect(container.querySelector(`a[href="${UPGRADE_URL}"]`)).toBeNull();
        act(() => root.unmount());
    });

    it('tells an entitled account the purchase is done and offers Install now, never an upgrade link', () =>
    {
        const { container, unmount } = render(base);

        expect(container.textContent).toContain('Your account includes Pro');
        expect(buttonLabels(container)).toContain('Install now');
        expect(container.textContent).not.toContain('Upgrade now');
        expect(container.querySelector(`a[href="${UPGRADE_URL}"]`)).toBeNull();
        unmount();
    });

    it('offers Activate now when the addon is installed but inactive', () =>
    {
        const { container, unmount } = render({ ...base, installed: true, active: false });

        expect(container.textContent).toContain('Your account includes Pro');
        expect(buttonLabels(container)).toContain('Activate now');
        expect(container.textContent).not.toContain('Upgrade now');
        expect(container.querySelector(`a[href="${UPGRADE_URL}"]`)).toBeNull();
        unmount();
    });

    it('keeps Upgrade now to the portal for a Terrestrial account', () =>
    {
        const { container, unmount } = render({ ...base, entitled: false });

        expect(container.querySelector(`a[href="${UPGRADE_URL}"]`)?.textContent).toBe('Upgrade now');
        expect(container.textContent).not.toContain('Your account includes Pro');
        unmount();
    });

    it('paints its header without depending on the app root tokens, since the modal is portaled outside it', () =>
    {
        const { container, unmount } = render({ connected: true, entitled: false, installed: false, active: false, version: '', upgradeUrl: UPGRADE_URL, upgradeStage: null });
        const header = container.querySelector('[style*="linear-gradient"]') as HTMLElement;
        expect(header.getAttribute('style')).not.toContain('--wp-components');
        expect(header.getAttribute('style')).toContain('#0b31a4');
        expect(container.textContent).toContain('Orbital');
        unmount();
    });
});
