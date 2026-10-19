import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { Setup } from '@/apps/setup';
import { useSetupStore } from '@/apps/setup/stores/setupStore';
import { useStepStore } from '@/apps/setup/stores/stepStore';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@/shared/components/connection/ConnectionPanel', () => ({ ConnectionPanel: () => <div>panel</div>, ConnectSiteButton: () => null }));

const mockApi = {
    getConnectStatus: jest.fn(),
    getSetupTranslationProgress: jest.fn(),
    finishSetup: jest.fn(),
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
    finishSetup: (...args: unknown[]) => mockApi.finishSetup(...args),
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

let root: Root | null = null;

const settle = async () =>
{
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
};

const renderLastStep = async (proState: Record<string, unknown> | undefined): Promise<HTMLElement> =>
{
    mockApi.getConnectStatus.mockResolvedValue({ connected: true, siteId: 'site-1', accountLabel: '', portalSitesUrl: '', pro: proState });
    useStepStore.setState({ step: 'translation', stepError: null } as never);
    useSetupStore.setState({
        defaultLanguage: 'en-us', languages: ['fr'], autoTranslate: true,
        context: '', isScanning: false, siteRead: true,
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

const footerButtons = (container: HTMLElement) =>
    Array.from(container.querySelectorAll('button')).map(item => item.textContent);

describe('the last setup step pushes an entitled site to install Pro', () =>
{
    beforeEach(() =>
    {
        jest.clearAllMocks();
        mockApi.getSetupTranslationProgress.mockReturnValue(new Promise(() => {}));
        mockApi.finishSetup.mockReturnValue(new Promise(() => {}));
    });

    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
    });

    it('the last step offers Install Pro and finish as the primary action for an entitled site without the addon', async () =>
    {
        mockApi.installPro.mockResolvedValue({ ok: true, code: '', message: '' });
        const container = await renderLastStep(pro());

        const primary = button(container, 'Install Pro and finish');
        expect(primary).toBeDefined();
        expect(primary!.className).toContain('is-primary');
        expect(button(container, 'Open Dashboard')).toBeUndefined();
        expect(button(container, 'Finish without Pro')).toBeDefined();
        expect(container.textContent).toContain('Your account includes Pro');

        await act(async () => { primary!.click(); });
        await settle();

        expect(mockApi.installPro).toHaveBeenCalledTimes(1);
        expect(mockApi.finishSetup).toHaveBeenCalledTimes(1);
    });

    it('offers Activate Pro and finish when the addon is installed but inactive', async () =>
    {
        mockApi.activatePro.mockResolvedValue({ ok: true, code: '', message: '' });
        const container = await renderLastStep(pro({ installed: true, installedVersion: '1.2.0' }));

        await act(async () => { button(container, 'Activate Pro and finish')!.click(); });
        await settle();

        expect(mockApi.activatePro).toHaveBeenCalledTimes(1);
        expect(mockApi.installPro).not.toHaveBeenCalled();
        expect(mockApi.finishSetup).toHaveBeenCalledTimes(1);
    });

    it('a failed install keeps Finish without Pro available', async () =>
    {
        mockApi.installPro.mockResolvedValue({ ok: false, code: 'package_unavailable', message: 'The Pro package is not ready yet.' });
        const container = await renderLastStep(pro());

        await act(async () => { button(container, 'Install Pro and finish')!.click(); });
        await settle();

        expect(mockApi.finishSetup).not.toHaveBeenCalled();
        expect(container.querySelector('[role="alert"]')?.textContent).toBe('The Pro package is not ready yet.');
        const without = button(container, 'Finish without Pro');
        expect(without?.disabled).toBe(false);

        await act(async () => { without!.click(); });
        await settle();

        expect(mockApi.finishSetup).toHaveBeenCalledTimes(1);
    });

    it('keeps the plain Finish for a Free account and for an active addon', async () =>
    {
        let container = await renderLastStep(undefined);
        expect(footerButtons(container)).toContain('Open Dashboard');
        expect(footerButtons(container)).not.toContain('Install Pro and finish');
        act(() => root?.unmount());
        document.body.innerHTML = '';

        container = await renderLastStep(pro({ installed: true, active: true, installedVersion: '1.2.0' }));
        expect(footerButtons(container)).toContain('Open Dashboard');
        expect(footerButtons(container)).not.toContain('Finish without Pro');
    });
});
