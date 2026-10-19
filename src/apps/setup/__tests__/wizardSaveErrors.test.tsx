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
    saveSetupStep: jest.fn(),
    finishSetup: jest.fn(),
};

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockApi.getConnectStatus(...args),
    beginConnect: jest.fn(),
    disconnectSite: jest.fn(),
}));

jest.mock('@/shared/api/setupTranslationProgress', () => ({
    getSetupTranslationProgress: (...args: unknown[]) => mockApi.getSetupTranslationProgress(...args),
}));

jest.mock('@/shared/api/setup', () => ({
    saveSetupStep: (...args: unknown[]) => mockApi.saveSetupStep(...args),
    finishSetup: (...args: unknown[]) => mockApi.finishSetup(...args),
    scanContext: jest.fn(() => new Promise(() => {})),
    getScanContextStatus: jest.fn(() => new Promise(() => {})),
    applyScanLexicon: jest.fn(),
}));

let root: Root | null = null;

const settle = async () =>
{
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
};

const renderWizard = async (step: string): Promise<HTMLElement> =>
{
    useStepStore.setState({ step, stepError: null } as never);
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

const primary = (container: HTMLElement, label: string) =>
    Array.from(container.querySelectorAll('button')).find(button => button.textContent === label) as HTMLButtonElement | undefined;

describe('the wizard never swallows a save error', () =>
{
    beforeEach(() =>
    {
        jest.clearAllMocks();
        mockApi.getConnectStatus.mockResolvedValue({ connected: true, siteId: 'site-1', accountLabel: '', portalSitesUrl: '' });
        mockApi.getSetupTranslationProgress.mockReturnValue(new Promise(() => {}));
    });

    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
    });

    it('stays on the languages step with the plain message when the save is refused, then moves on once it saves', async () =>
    {
        mockApi.saveSetupStep.mockRejectedValueOnce({ code: 'rest_invalid_param', message: 'The languages could not be saved.' });
        const container = await renderWizard('languages');

        await act(async () => { primary(container, 'Continue')!.click(); });
        await settle();

        expect(useStepStore.getState().step).toBe('languages');
        expect(container.querySelector('[role="alert"]')?.textContent).toBe('The languages could not be saved.');

        mockApi.saveSetupStep.mockResolvedValueOnce({ success: true, step: 'languages' });
        await act(async () => { primary(container, 'Continue')!.click(); });
        await settle();

        expect(useStepStore.getState().step).toBe('translation');
        expect(container.querySelector('[role="alert"]')).toBeNull();
    });

    it('shows a plain message for a failure without one', async () =>
    {
        mockApi.saveSetupStep.mockRejectedValueOnce(new TypeError('Failed to fetch'));
        const container = await renderWizard('languages');

        await act(async () => { primary(container, 'Continue')!.click(); });
        await settle();

        expect(useStepStore.getState().step).toBe('languages');
        expect(container.querySelector('[role="alert"]')?.textContent).toBe('Your choices could not be saved. Try again.');
    });

    it('shows why Finish failed and keeps Finish usable', async () =>
    {
        mockApi.finishSetup.mockRejectedValueOnce({ code: 'invalid_default_language', message: 'A default language is required' });
        const container = await renderWizard('translation');

        await act(async () => { primary(container, 'Open Dashboard')!.click(); });
        await settle();

        expect(container.querySelector('[role="alert"]')?.textContent).toBe('A default language is required');
        expect(primary(container, 'Open Dashboard')?.disabled).toBe(false);
    });
});
