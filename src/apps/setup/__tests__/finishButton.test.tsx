import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { Setup } from '@/apps/setup';
import { useSetupStore } from '@/apps/setup/stores/setupStore';
import { useStepStore } from '@/apps/setup/stores/stepStore';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@/apps/setup/components/header', () => ({ Header: () => null }));

const mockApi = {
    getConnectStatus: jest.fn(),
    getSetupTranslationProgress: jest.fn(),
    finishSetup: jest.fn(),
};

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockApi.getConnectStatus(...args),
    beginConnect: jest.fn(),
    disconnectSite: jest.fn(),
    installPro: jest.fn(),
    activatePro: jest.fn(),
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

let root: Root | null = null;

const renderStep = async (step: string): Promise<HTMLElement> =>
{
    mockApi.getConnectStatus.mockResolvedValue({ connected: true, siteId: 'site-1', accountLabel: '', portalSitesUrl: '' });
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
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

    return container;
};

const finishButtons = (container: HTMLElement) =>
    Array.from(container.querySelectorAll('button')).filter(item => item.textContent === 'Finish');

describe('the last setup step finishes from the footer', () =>
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

    it('offers one primary Finish button in the footer that completes setup once', async () =>
    {
        const container = await renderStep('translation');

        const finish = finishButtons(container);
        expect(finish).toHaveLength(1);
        expect(finish[0].closest('footer')).not.toBeNull();
        expect(finish[0].className).toContain('is-primary');
        expect(finish[0].getAttribute('type')).toBe('button');
        expect(finish[0].disabled).toBe(false);
        expect(container.textContent).not.toContain('Open Dashboard');

        finish[0].focus();
        expect(document.activeElement).toBe(finish[0]);

        await act(async () => { finish[0].click(); });

        expect(mockApi.finishSetup).toHaveBeenCalledTimes(1);
        expect(mockApi.finishSetup).toHaveBeenCalledWith('en-us', ['fr'], true, '');
    });

    it.each(['read-site', 'style-tone', 'languages'])('shows Continue and no Finish on the %s step', async (step) =>
    {
        const container = await renderStep(step);

        expect(finishButtons(container)).toHaveLength(0);
        expect(container.querySelector('footer')?.textContent).toContain('Continue');
    });
});
