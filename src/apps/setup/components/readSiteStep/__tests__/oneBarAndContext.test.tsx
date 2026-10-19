import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { ReadSiteStep } from '@/apps/setup/components/readSiteStep';
import { useSetupStore } from '@/apps/setup/stores/setupStore';
import type { ScanPollState } from '@/shared/hooks/useContextScan';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockRunReadSite = jest.fn();
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
        useContextScan: () => ({ state: mockScan.state, startScan: jest.fn() }),
    };
});

const render = async (): Promise<{ container: HTMLElement, root: Root }> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    mountedRoots.push(root);
    await act(async () =>
    {
        root.render(<ReadSiteStep />);
    });
    return { container, root };
};

const setTextareaValue = async (textarea: HTMLTextAreaElement, value: string): Promise<void> =>
{
    await act(async () =>
    {
        const setter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set;
        setter?.call(textarea, value);
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    });
};

const resetStore = async (context = ''): Promise<void> =>
{
    await act(async () =>
    {
        useSetupStore.setState({ siteRead: false, context });
    });
};

describe('the read-site step while both passes run', () =>
{
    beforeEach(async () =>
    {
        mockRunReadSite.mockReset();
        mockScan.state = { phase: 'idle' };
        await resetStore();
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

    it('renders exactly one progress bar while the context scan also runs', async () =>
    {
        let resolveRead: () => void = () => {};
        mockRunReadSite.mockImplementation(async (handlers: {
            onPhase: (phase: 'fetching') => void;
            onProgress: (progress: { processed: number; failed: number; segments: number }) => void;
            onTotal: (total: number) => void;
        }) =>
        {
            handlers.onPhase('fetching');
            handlers.onTotal(8);
            handlers.onProgress({ processed: 2, failed: 0, segments: 5 });
            await new Promise<void>((resolve) => { resolveRead = resolve; });
            return { processed: 2, failed: 0, segments: 5 };
        });

        const { container, root } = await render();
        const readButton = [...container.querySelectorAll('button')].find(button => button.textContent === 'Read my site')!;
        await act(async () => { readButton.click(); });

        mockScan.state = { phase: 'scanning', elapsedSeconds: 3, progress: { stage: 'collecting', sampled: 1, total: 8 } };
        await act(async () =>
        {
            root.render(<ReadSiteStep />);
        });

        expect(container.querySelectorAll('[role="progressbar"]')).toHaveLength(1);

        await act(async () => { resolveRead(); });
    });

    it('renders exactly one progress bar once both passes have finished', async () =>
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

        const { container, root } = await render();
        const readButton = [...container.querySelectorAll('button')].find(button => button.textContent === 'Read my site')!;
        await act(async () => { readButton.click(); });

        mockScan.state = { phase: 'done', context: 'A bakery in Lyon', result: { context: 'A bakery in Lyon' }, applied: true, pages: 8, lexiconSuggestions: [] };
        await act(async () =>
        {
            root.render(<ReadSiteStep />);
        });

        expect(container.querySelectorAll('[role="progressbar"]')).toHaveLength(1);
    });
});

describe('the read-site status line', () =>
{
    beforeEach(async () =>
    {
        mockRunReadSite.mockReset();
        mockScan.state = { phase: 'idle' };
        await resetStore();
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

    const readThenScan = async (state: ScanPollState): Promise<HTMLElement> =>
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
        const { container, root } = await render();
        const readButton = [...container.querySelectorAll('button')].find(button => button.textContent === 'Read my site')!;
        await act(async () => { readButton.click(); });
        mockScan.state = state;
        await act(async () => { root.render(<ReadSiteStep />); });
        return container;
    };

    it('never says nothing is translated yet', async () =>
    {
        const { container } = await render();
        expect(container.textContent).not.toContain('Nothing is translated yet');

        const after = await readThenScan({ phase: 'done', context: 'A bakery', result: { context: 'A bakery' }, applied: true, pages: 8, lexiconSuggestions: [] });
        expect(after.textContent).not.toContain('Nothing is translated yet');
    });

    it('keeps one spinner and says Preparing site context once the pages are read and the proposal still runs', async () =>
    {
        const container = await readThenScan({ phase: 'scanning', elapsedSeconds: 3, progress: { stage: 'analysing', sampled: 7, total: 7 } });

        expect(container.querySelectorAll('.components-spinner')).toHaveLength(1);
        expect(container.querySelectorAll('[role="progressbar"]')).toHaveLength(1);
        const line = container.querySelector('[data-read-status]') as HTMLElement;
        expect(line.textContent).toBe('Preparing site context');
        expect(container.querySelector('[data-read-bar="done"]')).toBeNull();
    });

    it('shows a green bar and no spinner once the read and the proposal have both finished', async () =>
    {
        const container = await readThenScan({ phase: 'done', context: 'A bakery', result: { context: 'A bakery' }, applied: true, pages: 8, lexiconSuggestions: [] });

        expect(container.querySelectorAll('.components-spinner')).toHaveLength(0);
        expect(container.querySelectorAll('[role="progressbar"]')).toHaveLength(1);
        expect(container.querySelector('[data-read-bar="done"] .bg-translatewp-success')).not.toBeNull();
        expect((container.querySelector('[data-read-status]') as HTMLElement).textContent).toBe('Done. 8 pages read, 20 text segments found.');
    });
});

describe('the read-site context box', () =>
{
    beforeEach(async () =>
    {
        mockRunReadSite.mockReset();
        mockScan.state = { phase: 'idle' };
        await resetStore();
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

    it('renders the Write context box bound to the setup store', async () =>
    {
        await resetStore('A bakery in Lyon');
        const { container } = await render();

        const textarea = container.querySelector('textarea');
        expect(textarea).not.toBeNull();
        expect(container.textContent).toContain('Write context');
        expect(textarea?.value).toBe('A bakery in Lyon');

        await setTextareaValue(textarea!, 'A bakery in Berlin');
        expect(useSetupStore.getState().context).toBe('A bakery in Berlin');
    });

    it('fills the box with the context scan proposal when it finishes', async () =>
    {
        mockScan.state = { phase: 'done', context: 'A bakery in Lyon', result: { context: 'A bakery in Lyon' }, applied: true, pages: 8, lexiconSuggestions: [] };
        const { container } = await render();

        expect(useSetupStore.getState().context).toBe('A bakery in Lyon');
        expect(container.querySelector('textarea')?.value).toBe('A bakery in Lyon');
    });
    it('replaces the text in the box with the proposal, so two briefs never stack', async () =>
    {
        await resetStore('We sell bread.');
        mockScan.state = { phase: 'done', context: 'A bakery in Lyon', result: { context: 'A bakery in Lyon' }, applied: true, pages: 8, lexiconSuggestions: [] };
        const { container, root } = await render();

        expect(useSetupStore.getState().context).toBe('A bakery in Lyon');
        expect(container.querySelector('textarea')?.value).toBe('A bakery in Lyon');

        await act(async () => { root.render(<ReadSiteStep />); });
        expect(useSetupStore.getState().context).toBe('A bakery in Lyon');
    });

    it('preselects the style and tone the scan proposes', async () =>
    {
        await act(async () => { useSetupStore.setState({ style: 'neutral', tone: 'friendly' }); });
        mockScan.state = { phase: 'done', context: 'A law firm', result: { context: 'A law firm', style: 'legal', tone: 'formal' }, applied: true, pages: 4, lexiconSuggestions: [] };
        await render();

        expect(useSetupStore.getState().style).toBe('legal');
        expect(useSetupStore.getState().tone).toBe('formal');
    });

    it('uses the new context placeholder', async () =>
    {
        const { container } = await render();

        expect(container.querySelector('textarea')?.getAttribute('placeholder')).toBe('Share anything about your website that would help the AI understand your content, audience, and goals.');
    });
});
