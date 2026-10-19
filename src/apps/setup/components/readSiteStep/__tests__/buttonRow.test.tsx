import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { ReadSiteStep } from '@/apps/setup/components/readSiteStep';
import { useSetupStore } from '@/apps/setup/stores/setupStore';
import type { ScanPollState } from '@/shared/hooks/useContextScan';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockRunReadSite = jest.fn();
const mockStartScan = jest.fn();
const mockScan: { state: ScanPollState } = { state: { phase: 'idle' } };
const mountedRoots: Root[] = [];

jest.mock('@/apps/admin/components/Translations/Indexing/extractionDriver', () => ({
    extractRowItems: jest.fn(),
    runReadSite: (...args: unknown[]) => mockRunReadSite(...args),
}));

jest.mock('@/shared/hooks/useContextScan', () => {
    const actual = jest.requireActual('@/shared/hooks/useContextScan');
    return {
        ...actual,
        useContextScan: () => ({ state: mockScan.state, startScan: mockStartScan }),
    };
});

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    mountedRoots.push(root);
    await act(async () =>
    {
        root.render(<ReadSiteStep />);
    });
    return container;
};

describe('the read-site step button row', () =>
{
    beforeEach(async () =>
    {
        mockRunReadSite.mockReset();
        mockStartScan.mockReset();
        mockScan.state = { phase: 'idle' };
        await act(async () =>
        {
            useSetupStore.setState({ siteRead: false, context: '' });
        });
    });

    afterEach(async () =>
    {
        await act(async () =>
        {
            for (const root of mountedRoots.splice(0)) {
                root.unmount();
            }
        });
        document.body.innerHTML = '';
    });

    it('renders only the site-reading action inside the card', async () =>
    {
        const container = await render();

        const scanButton = [...container.querySelectorAll('button')].find(button => button.textContent === 'Read my site');
        expect(scanButton).toBeDefined();
        expect([...container.querySelectorAll('button')]).toHaveLength(1);
    });

    it('aligns the scan row to the start of the card', async () =>
    {
        const container = await render();

        const scanButton = [...container.querySelectorAll('button')].find(button => button.textContent === 'Read my site')!;
        const row = scanButton.parentElement!;
        expect(row.className).toContain('md:justify-start');
        expect(row.className).not.toContain('md:justify-end');
    });

    it('keeps the button label consistent after the site has been read', async () =>
    {
        await act(async () =>
        {
            useSetupStore.setState({ siteRead: true });
        });
        const container = await render();

        const button = [...container.querySelectorAll('button')].find(candidate => candidate.textContent === 'Read my site')!;
        expect(button).toBeDefined();
        // Once the site is read, Continue is the one primary action; the read button steps back.
        expect(button.className).toContain('is-secondary');
        expect(button.className).not.toContain('is-primary');
    });

    it('is the primary action before the site has been read', async () =>
    {
        const container = await render();
        const button = [...container.querySelectorAll('button')].find(candidate => candidate.textContent === 'Read my site')!;
        expect(button.className).toContain('is-primary');
    });

    it('shows the pages completed by the current read rather than a stale status count', async () =>
    {
        let resolveRead: () => void = () => {};
        mockRunReadSite.mockImplementation(async (handlers: {
            onPhase: (phase: 'fetching') => void;
            onProgress: (progress: { processed: number; failed: number; segments: number }) => void;
            onStatus: (status: { scan: { pages_total: number; pages_done: number; current_page: null } }) => void;
            onTotal: (total: number) => void;
        }) =>
        {
            handlers.onPhase('fetching');
            handlers.onTotal(8);
            handlers.onStatus({ scan: { pages_total: 8, pages_done: 1, current_page: null } });
            handlers.onProgress({ processed: 4, failed: 0, segments: 10 });
            await new Promise<void>((resolve) => { resolveRead = resolve; });
            return { processed: 4, failed: 0, segments: 10 };
        });

        const container = await render();
        const readButton = [...container.querySelectorAll('button')].find(button => button.textContent === 'Read my site')!;
        await act(async () => { readButton.click(); });

        expect(container.querySelector('[role="progressbar"]')).not.toBeNull();

        await act(async () =>
        {
            resolveRead();
        });
    });
    const finishedRead = (): void =>
    {
        mockRunReadSite.mockImplementation(async (handlers: {
            onPhase: (phase: 'fetching' | 'done') => void;
            onProgress: (progress: { processed: number; failed: number; segments: number }) => void;
            onTotal: (total: number) => void;
        }) =>
        {
            handlers.onPhase('fetching');
            handlers.onTotal(8);
            handlers.onProgress({ processed: 8, failed: 0, segments: 20 });
            handlers.onPhase('done');
            return { processed: 8, failed: 0, segments: 20 };
        });
    };

    const renderAfterRead = async (state: ScanPollState): Promise<HTMLElement> =>
    {
        finishedRead();
        const container = document.createElement('div');
        document.body.appendChild(container);
        const root = createRoot(container);
        mountedRoots.push(root);
        await act(async () => { root.render(<ReadSiteStep />); });
        const readButton = [...container.querySelectorAll('button')].find(button => button.textContent === 'Read my site')!;
        await act(async () => { readButton.click(); });
        mockScan.state = state;
        await act(async () => { root.render(<ReadSiteStep />); });
        return container;
    };

    it('shows a check, not a spinner, once the pages are read and the proposal is done', async () =>
    {
        const container = await renderAfterRead({ phase: 'done', context: 'A bakery', result: { context: 'A bakery' }, applied: true, pages: 8, lexiconSuggestions: [] });

        const line = container.querySelector('[data-read-status]')!.parentElement!;
        expect(line.textContent).toContain('Done.');
        expect(line.querySelector('.components-spinner')).toBeNull();
        expect(line.querySelector('svg')).not.toBeNull();
    });

    it('labels the button Scan again after the context scan fails', async () =>
    {
        const container = await renderAfterRead({ phase: 'failed', message: 'x' });

        const buttons = [...container.querySelectorAll('button')];
        expect(buttons).toHaveLength(1);
        expect(buttons[0].textContent).toBe('Scan again');
    });

    it('re-runs only the context scan when Scan again is clicked after a successful read', async () =>
    {
        const container = await renderAfterRead({ phase: 'failed', message: 'x' });
        expect(mockRunReadSite).toHaveBeenCalledTimes(1);
        expect(mockStartScan).toHaveBeenCalledTimes(1);

        const button = container.querySelector('button')!;
        await act(async () => { button.click(); });

        expect(mockRunReadSite).toHaveBeenCalledTimes(1);
        expect(mockStartScan).toHaveBeenCalledTimes(2);
    });
});
