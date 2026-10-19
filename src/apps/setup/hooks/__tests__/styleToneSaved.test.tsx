import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { useSteps } from '@/apps/setup/hooks/useSteps';
import { useStepStore } from '@/apps/setup/stores/stepStore';
import { useSetupStore } from '@/apps/setup/stores/setupStore';
import { useConfirmStore } from '@/apps/setup/stores/confirmStore';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockSave = { saveSetupStep: jest.fn() };

jest.mock('@/shared/api/setup', () => ({
    saveSetupStep: (...args: unknown[]) => mockSave.saveSetupStep(...args),
    finishSetup: jest.fn(),
    scanContext: jest.fn(),
}));

jest.mock('@/shared/api/admin', () => ({
    getRetranslationEstimate: jest.fn().mockResolvedValue({ pages: 12, languages: 2 }),
}));

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: jest.fn().mockResolvedValue({ connected: true }),
}));

type Steps = ReturnType<typeof useSteps>;

const renderSteps = async (): Promise<{ renders: Steps[]; root: Root }> =>
{
    const renders: Steps[] = [];
    const Probe = () =>
    {
        renders.push(useSteps());
        return null;
    };
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    queryClient.setQueryData(['connectStatus'], { connected: true });
    const root = createRoot(document.body.appendChild(document.createElement('div')));
    await act(async () =>
    {
        root.render(<QueryClientProvider client={queryClient}><Probe /></QueryClientProvider>);
    });
    return { renders, root };
};

describe('the style and tone step in the wizard order', () =>
{
    beforeEach(() =>
    {
        mockSave.saveSetupStep.mockReset().mockResolvedValue({ success: true });
        useSetupStore.setState({ defaultLanguage: 'en-us', languages: [], siteRead: true, context: 'shop', style: 'legal', tone: 'formal' });
    });

    it('follows Read your site and saves the chosen style and tone before Languages', async () =>
    {
        useStepStore.setState({ step: 'read-site' });
        const { renders, root } = await renderSteps();

        await act(async () => { await renders[renders.length - 1].nextStep(); });
        expect(renders[renders.length - 1].currentStep.id).toBe('style-tone');

        await act(async () => { await renders[renders.length - 1].nextStep(); });
        expect(mockSave.saveSetupStep).toHaveBeenLastCalledWith({ step: 'style-tone', resumeStep: 'languages', style: 'legal', tone: 'formal' });
        expect(renders[renders.length - 1].currentStep.id).toBe('languages');

        await act(async () => { root.unmount(); });
    });
});

describe('a restarted wizard that changes the style or tone', () =>
{
    beforeEach(() =>
    {
        mockSave.saveSetupStep.mockReset().mockResolvedValue({ success: true });
        (window as { setupData?: unknown }).setupData = { defaultLanguage: 'en-us', step: 'done', style: 'neutral', tone: 'friendly' };
        useStepStore.setState({ step: 'style-tone', stepError: null });
    });

    afterEach(() =>
    {
        delete (window as { setupData?: unknown }).setupData;
        useConfirmStore.setState({ request: null });
    });

    it('asks first, and on Cancel saves nothing, stays on the step and returns the saved choice', async () =>
    {
        const request = jest.fn().mockResolvedValue(false);
        useConfirmStore.setState({ request });
        useSetupStore.setState({ siteRead: true, style: 'legal', tone: 'friendly' });
        const { renders, root } = await renderSteps();

        await act(async () => { await renders[renders.length - 1].nextStep(); });

        expect(request).toHaveBeenCalledTimes(1);
        expect((request.mock.calls[0][0] as { message: string }).message).toContain('12 pages in 2 translation languages');
        expect(mockSave.saveSetupStep).not.toHaveBeenCalled();
        expect(renders[renders.length - 1].currentStep.id).toBe('style-tone');
        expect(useSetupStore.getState().style).toBe('neutral');
        expect(useStepStore.getState().stepError).toBeNull();
        await act(async () => { root.unmount(); });
    });

    it('saves after the owner confirms', async () =>
    {
        useConfirmStore.setState({ request: jest.fn().mockResolvedValue(true) });
        useSetupStore.setState({ siteRead: true, style: 'legal', tone: 'friendly' });
        const { renders, root } = await renderSteps();

        await act(async () => { await renders[renders.length - 1].nextStep(); });

        expect(mockSave.saveSetupStep).toHaveBeenCalledWith(expect.objectContaining({ step: 'style-tone', style: 'legal' }));
        expect(renders[renders.length - 1].currentStep.id).toBe('languages');
        await act(async () => { root.unmount(); });
    });

    it('asks nothing when neither changed', async () =>
    {
        const request = jest.fn().mockResolvedValue(false);
        useConfirmStore.setState({ request });
        useSetupStore.setState({ siteRead: true, style: 'neutral', tone: 'friendly' });
        const { renders, root } = await renderSteps();

        await act(async () => { await renders[renders.length - 1].nextStep(); });

        expect(request).not.toHaveBeenCalled();
        expect(renders[renders.length - 1].currentStep.id).toBe('languages');
        await act(async () => { root.unmount(); });
    });
});
