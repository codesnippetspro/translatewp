import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { Setup } from '@/apps/setup';
import { useStepStore } from '@/apps/setup/stores/stepStore';
import { useSetupStore } from '@/apps/setup/stores/setupStore';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockGetConnectStatus = jest.fn();

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockGetConnectStatus(...args),
    beginConnect: jest.fn(),
    disconnectSite: jest.fn(),
    installPro: jest.fn(),
    activatePro: jest.fn(),
}));

jest.mock('@/apps/setup/components/header', () => ({ Header: () => null }));

type Status = { connected: boolean; siteId: string | null; accountLabel: string; portalSitesUrl: string; portalSiteUrl: string };

let currentRoot: Root | null = null;

const renderSetup = async (status: Status): Promise<HTMLElement> =>
{
    mockGetConnectStatus.mockResolvedValue(status);
    const container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    queryClient.setQueryData(['connectStatus'], status);
    const root = createRoot(container);
    currentRoot = root;
    await act(async () =>
    {
        root.render(
            <QueryClientProvider client={queryClient}>
                <Setup />
            </QueryClientProvider>
        );
    });
    await act(async () =>
    {
        await new Promise((resolve) => setTimeout(resolve, 0));
    });
    return container;
};

const stepState = (container: HTMLElement, step: string): string | null =>
    container.querySelector(`[data-step="${step}"]`)?.getAttribute('data-state') ?? null;

describe('Setup step progress', () =>
{
    beforeEach(() =>
    {
        mockGetConnectStatus.mockReset();
        useStepStore.setState({ step: 'connect' });
        useSetupStore.setState({ languages: [] });
    });

    afterEach(async () =>
    {
        if (currentRoot) {
            await act(async () => { currentRoot?.unmount(); });
            currentRoot = null;
        }
        document.body.innerHTML = '';
    });

    it('shows the current step number and marks the completed connect step once connected', async () =>
    {
        const container = await renderSetup({
            connected: true, siteId: 'site-1', accountLabel: 'ops@translatewp.ai', portalSitesUrl: '', portalSiteUrl: '',
        });

        expect(container.textContent).toContain('Step 2 of 5');
        expect(stepState(container, 'connect')).toBe('completed');
        expect(stepState(container, 'read-site')).toBe('current');
        expect(stepState(container, 'languages')).toBe('upcoming');
        expect(stepState(container, 'translation')).toBe('upcoming');
    });

    // The Read your site step cannot be left until the site was read.
    it('keeps Continue off on the read step until the site has been read', async () =>
    {
        useStepStore.setState({ step: 'read-site' });
        useSetupStore.setState({ languages: [], siteRead: false });
        const container = await renderSetup({
            connected: true, siteId: 'site-1', accountLabel: 'ops@translatewp.ai', portalSitesUrl: '', portalSiteUrl: '',
        });
        const continueButton = Array.from(container.querySelectorAll('button')).find(button => button.textContent === 'Continue') as HTMLButtonElement;
        expect(stepState(container, 'read-site')).toBe('current');
        expect(continueButton.disabled).toBe(true);

        await act(async () => { useSetupStore.setState({ siteRead: true }); });
        await act(async () => { await new Promise((resolve) => setTimeout(resolve, 0)); });
        const enabledButton = Array.from(container.querySelectorAll('button')).find(button => button.textContent === 'Continue') as HTMLButtonElement;
        expect(enabledButton.disabled).toBe(false);
    });

    it('shows five steps in order with their names', async () =>
    {
        const container = await renderSetup({
            connected: false, siteId: null, accountLabel: '', portalSitesUrl: '', portalSiteUrl: '',
        });

        const steps = [...container.querySelectorAll('[data-step]')];
        expect(steps.map(step => step.getAttribute('data-step'))).toEqual(['connect', 'read-site', 'style-tone', 'languages', 'translation']);
        expect(steps.map(step => step.textContent)).toEqual(['Connect', 'Read your site', 'Style and tone', 'Languages', 'Translation']);
    });

    it('links the terms and the privacy policy from the Connect step', async () =>
    {
        const container = await renderSetup({
            connected: false, siteId: null, accountLabel: '', portalSitesUrl: '', portalSiteUrl: '',
        });

        const hrefs = [...container.querySelectorAll('.setup-step a')].map(link => link.getAttribute('href'));
        expect(hrefs).toContain('https://translatewp.ai/terms');
        expect(hrefs).toContain('https://translatewp.ai/privacy');
        for (const link of container.querySelectorAll('.setup-step a[target="_blank"]')) {
            expect(link.getAttribute('rel')).toBe('noopener noreferrer');
        }
    });

    it('starts at step one with no completed steps while not connected', async () =>
    {
        const container = await renderSetup({
            connected: false, siteId: null, accountLabel: '', portalSitesUrl: '', portalSiteUrl: '',
        });

        expect(container.textContent).toContain('Step 1 of 5');
        expect(stepState(container, 'connect')).toBe('current');
        expect(stepState(container, 'read-site')).toBe('upcoming');
        expect(stepState(container, 'languages')).toBe('upcoming');
    });
});
