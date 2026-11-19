import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { Setup } from '@/apps/setup';
import { useSetupStore } from '@/apps/setup/stores/setupStore';
import { useStepStore } from '@/apps/setup/stores/stepStore';
import { ProInstallAction } from '@/shared/components/pro/ProInstallAction';
import { reloadPage } from '@/shared/lib/reloadPage';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@/apps/setup/components/header', () => ({ Header: () => null }));
jest.mock('@/shared/lib/reloadPage', () => ({ reloadPage: jest.fn() }));
jest.mock('@/shared/components/pro/ProInstallAction', () =>
{
    const actual = jest.requireActual('@/shared/components/pro/ProInstallAction');
    return { ProInstallAction: jest.fn(actual.ProInstallAction) };
});

const mockApi = {
    getConnectStatus: jest.fn(),
    getSetupTranslationProgress: jest.fn(),
    installPro: jest.fn(),
    activatePro: jest.fn(),
};

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockApi.getConnectStatus(...args),
    beginConnect: jest.fn(),
    disconnectSite: jest.fn(),
    installPro: (...args: unknown[]) => mockApi.installPro(...args),
    activatePro: (...args: unknown[]) => mockApi.activatePro(...args),
}));

jest.mock('@/shared/api/setupTranslationProgress', () => ({
    getSetupTranslationProgress: (...args: unknown[]) => mockApi.getSetupTranslationProgress(...args),
}));

jest.mock('@/shared/api/setup', () => ({
    saveSetupStep: jest.fn(),
    finishSetup: jest.fn(() => new Promise(() => {})),
    scanContext: jest.fn(() => new Promise(() => {})),
    getScanContextStatus: jest.fn(() => new Promise(() => {})),
    applyScanLexicon: jest.fn(),
}));

const pro = (overrides: Record<string, unknown> = {}) => ({
    plan: 'pro',
    entitled: true,
    installed: false,
    active: false,
    installedVersion: null,
    latestVersion: '1.2.0',
    packagePending: false,
    upgrade: null,
    accountUrl: '',
    updateAvailable: false,
    updateUrl: null,
    capabilities: { seo: true, custom_style: true, byok: true },
    ...overrides,
});

const free = () => pro({ plan: 'free', entitled: false, capabilities: { seo: false, custom_style: false, byok: false } });

let root: Root | null = null;

const settle = async () =>
{
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
};

// The wizard starts on connect, as it does when the owner returns from the portal.
const renderConnected = async (proState: Record<string, unknown> | undefined, step = 'connect'): Promise<HTMLElement> =>
{
    mockApi.getConnectStatus.mockResolvedValue({ connected: true, siteId: 'site-1', accountLabel: 'ops@translatewp.ai', portalSitesUrl: '', pro: proState });
    useStepStore.setState({ step, stepError: null } as never);
    useSetupStore.setState({
        defaultLanguage: 'en-us', languages: ['fr'], autoTranslate: true,
        context: '', isScanning: false, siteRead: false,
    });
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await act(async () => { root!.render(<QueryClientProvider client={client}><Setup /></QueryClientProvider>); });
    await settle();

    return container;
};

const button = (container: HTMLElement, label: string) =>
    Array.from(container.querySelectorAll('button')).find(item => item.textContent === label) as HTMLButtonElement | undefined;

const proButtons = (container: HTMLElement) =>
    Array.from(container.querySelectorAll('button')).map(item => item.textContent ?? '').filter(text => /pro|upgrade/i.test(text));

describe('connecting an Orbital account offers Pro right away', () =>
{
    beforeEach(() =>
    {
        jest.clearAllMocks();
        mockApi.getSetupTranslationProgress.mockReturnValue(new Promise(() => {}));
        delete (window as { translatewpPro?: unknown }).translatewpPro;
    });

    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
        delete (window as { translatewpPro?: unknown }).translatewpPro;
    });

    it('shows Install Pro as soon as the site is connected, busy while it runs, then reloads', async () =>
    {
        let resolveInstall: (value: unknown) => void = () => {};
        mockApi.installPro.mockReturnValue(new Promise(resolve => { resolveInstall = resolve; }));
        const container = await renderConnected(pro());

        expect(useStepStore.getState().step).toBe('connect');
        expect(container.textContent).toContain('Connected as ops@translatewp.ai');
        const install = button(container, 'Install Pro');
        expect(install).toBeDefined();
        expect(install!.getAttribute('aria-label')).toBe('Install Pro');
        expect(install!.disabled).toBe(false);

        await act(async () => { install!.click(); });
        expect(mockApi.installPro).toHaveBeenCalledTimes(1);
        expect(install!.disabled).toBe(true);
        expect(install!.getAttribute('aria-busy')).toBe('true');

        await act(async () => { resolveInstall({ ok: true, code: '', message: '' }); });
        await settle();

        expect(reloadPage).toHaveBeenCalledTimes(1);
    });

    it('says why the install failed and lets the owner try again', async () =>
    {
        mockApi.installPro.mockResolvedValue({ ok: false, code: 'package_unavailable', message: 'The Pro package is not ready yet.' });
        const container = await renderConnected(pro());

        await act(async () => { button(container, 'Install Pro')!.click(); });
        await settle();

        expect(container.querySelector('[role="alert"]')?.textContent).toBe('The Pro package is not ready yet.');
        expect(button(container, 'Install Pro')!.disabled).toBe(false);
        expect(reloadPage).not.toHaveBeenCalled();
    });

    it('shows Activate Pro when Pro is installed but not active', async () =>
    {
        mockApi.activatePro.mockResolvedValue({ ok: true, code: '', message: '' });
        const container = await renderConnected(pro({ installed: true, installedVersion: '1.2.0' }));

        expect(button(container, 'Install Pro')).toBeUndefined();
        await act(async () => { button(container, 'Activate Pro')!.click(); });
        await settle();

        expect(mockApi.activatePro).toHaveBeenCalledTimes(1);
        expect(mockApi.installPro).not.toHaveBeenCalled();
        expect(reloadPage).toHaveBeenCalledTimes(1);
    });

    it('offers nothing to an account without Orbital', async () =>
    {
        const container = await renderConnected(free());

        expect(container.textContent).toContain('Connected as ops@translatewp.ai');
        expect(proButtons(container)).toEqual([]);
    });

    it('offers nothing when Pro is already active', async () =>
    {
        const container = await renderConnected(pro({ installed: true, active: true, installedVersion: '1.2.0' }));

        expect(proButtons(container)).toEqual([]);
    });

    it('uses the same install action the Style and tone step offers, which keeps its own', async () =>
    {
        (window as { translatewpPro?: unknown }).translatewpPro = { connected: true, entitled: true, installed: false, active: false };
        const container = await renderConnected(pro(), 'style-tone');

        const labels = (ProInstallAction as jest.Mock).mock.calls.map(([props]) => props.label);
        expect(labels).toContain('Install Pro');
        expect(labels).toContain('Pro');
        // The connected line and the step's Style and Tone markers, all one action.
        const actions = Array.from(container.querySelectorAll('button[aria-label="Install Pro"]')).map(item => item.textContent);
        expect(actions).toEqual(['Install Pro', 'Pro', 'Pro']);
    });

    it('leaves the last step to its own Install Pro and finish', async () =>
    {
        const container = await renderConnected(pro(), 'translation');

        expect(button(container, 'Install Pro and finish')).toBeDefined();
        expect(button(container, 'Install Pro')).toBeUndefined();
    });
});
