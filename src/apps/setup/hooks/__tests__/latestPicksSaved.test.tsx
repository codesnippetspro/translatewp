import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { useSteps } from '@/apps/setup/hooks/useSteps';
import { useStepStore } from '@/apps/setup/stores/stepStore';
import { useSetupStore } from '@/apps/setup/stores/setupStore';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockSave = { saveSetupStep: jest.fn(), finishSetup: jest.fn() };

jest.mock('@/shared/api/setup', () => ({
    saveSetupStep: (...args: unknown[]) => mockSave.saveSetupStep(...args),
    finishSetup: (...args: unknown[]) => mockSave.finishSetup(...args),
    scanContext: jest.fn(),
}));

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: jest.fn().mockResolvedValue({ connected: true }),
}));

type Steps = ReturnType<typeof useSteps>;
type RenderedSteps = { renders: Steps[]; root: Root; container: HTMLElement };

const renderSteps = async (): Promise<RenderedSteps> =>
{
    const renders: Steps[] = [];
    const Probe = () =>
    {
        renders.push(useSteps());
        return null;
    };
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    queryClient.setQueryData(['connectStatus'], { connected: true });
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    await act(async () =>
    {
        root.render(
            <QueryClientProvider client={queryClient}>
                <Probe />
            </QueryClientProvider>
        );
    });
    return { renders, root, container };
};

const unmountSteps = async ({ root, container }: RenderedSteps): Promise<void> =>
{
    await act(async () => { root.unmount(); });
    document.body.removeChild(container);
};

describe('a wizard save carries the picks held when the button is pressed', () =>
{
    beforeEach(() =>
    {
        mockSave.saveSetupStep.mockReset().mockResolvedValue({ success: true });
        mockSave.finishSetup.mockReset().mockResolvedValue({ success: true });
        useSetupStore.setState({ defaultLanguage: 'lv', languages: [], siteRead: true, context: 'shop', autoTranslate: true });
    });

    it('saves languages picked in the same tick as Continue', async () =>
    {
        useStepStore.setState({ step: 'languages' });
        const mounted = await renderSteps();
        const { renders } = mounted;
        const firstRender = renders[0];
        expect(firstRender.currentStep.id).toBe('languages');

        await act(async () =>
        {
            useSetupStore.getState().setLanguages(['de', 'en-us']);
            firstRender.nextStep();
            await new Promise((resolve) => setTimeout(resolve, 0));
        });

        expect(mockSave.saveSetupStep).toHaveBeenCalledTimes(1);
        expect(mockSave.saveSetupStep.mock.calls[0][0]).toMatchObject({ step: 'languages', defaultLanguage: 'lv', languages: ['de', 'en-us'], context: 'shop' });
        await unmountSteps(mounted);
    });

    it('finishes with the languages held when Finish is pressed', async () =>
    {
        useStepStore.setState({ step: 'translation' });
        const mounted = await renderSteps();
        const { renders } = mounted;
        const firstRender = renders[0];
        expect(firstRender.isLastStep).toBe(true);

        await act(async () =>
        {
            useSetupStore.getState().setLanguages(['it']);
            firstRender.nextStep();
            await new Promise((resolve) => setTimeout(resolve, 0));
        });

        expect(mockSave.finishSetup).toHaveBeenCalledTimes(1);
        expect(mockSave.finishSetup.mock.calls[0]).toEqual(['lv', ['it'], true, 'shop']);
        // A finished wizard reloads the page, which the test document cannot do.
        expect(console).toHaveErrored();
        await unmountSteps(mounted);
    });

    it('moves on to Finish only once the step save has landed, and Finish is then usable', async () =>
    {
        let resolveSave: () => void = () => {};
        mockSave.saveSetupStep.mockReturnValue(new Promise<void>((resolve) => { resolveSave = resolve; }));
        useStepStore.setState({ step: 'languages' });
        const mounted = await renderSteps();
        const { renders } = mounted;

        await act(async () =>
        {
            void renders[0].nextStep();
            await new Promise((resolve) => setTimeout(resolve, 0));
        });
        expect(renders.at(-1)!.currentStep.id).toBe('languages');
        expect(renders.at(-1)!.stepSaving).toBe(true);

        await act(async () =>
        {
            resolveSave();
            await new Promise((resolve) => setTimeout(resolve, 0));
        });
        const translation = renders.at(-1)!;
        expect(translation.currentStep.id).toBe('translation');
        expect(translation.finishPending).toBe(false);
        await unmountSteps(mounted);
    });
});

describe('a restarted wizard with a new context asks before finishing', () =>
{
    afterEach(() =>
    {
        delete (window as { setupData?: unknown }).setupData;
    });

    it('does not finish when the cost is declined, and finishes when it is accepted', async () =>
    {
        const { useConfirmStore } = await import('@/apps/setup/stores/confirmStore');
        (window as { setupData?: unknown }).setupData = { defaultLanguage: 'lv', step: 'done', context: 'old context' };
        mockSave.saveSetupStep.mockReset().mockResolvedValue({ success: true });
        mockSave.finishSetup.mockReset().mockResolvedValue({ success: true });
        useSetupStore.setState({ defaultLanguage: 'lv', languages: ['de'], siteRead: true, context: 'new context', autoTranslate: true });
        useStepStore.setState({ step: 'translation' });
        const request = jest.fn().mockResolvedValueOnce(false).mockResolvedValueOnce(true);
        useConfirmStore.setState({ request });

        const mounted = await renderSteps();
        const { renders } = mounted;
        await act(async () =>
        {
            renders[0].nextStep();
            await new Promise((resolve) => setTimeout(resolve, 0));
        });
        expect(request).toHaveBeenCalledTimes(1);
        expect(mockSave.finishSetup).not.toHaveBeenCalled();

        await act(async () =>
        {
            renders[0].nextStep();
            await new Promise((resolve) => setTimeout(resolve, 0));
        });
        expect(request).toHaveBeenCalledTimes(2);
        expect(mockSave.finishSetup).toHaveBeenCalledTimes(1);
        expect(console).toHaveErrored();
        useConfirmStore.setState({ request: null });
        await unmountSteps(mounted);
    });
});
