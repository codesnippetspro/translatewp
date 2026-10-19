import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { useContextScan, type ScanPollState } from '@/apps/setup/hooks/useContextScan';
import { getScanContextStatus, scanContext } from '@/shared/api/setup';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@/shared/api/setup', () => ({
    scanContext: jest.fn(),
    getScanContextStatus: jest.fn(),
    finishSetup: jest.fn(),
}));

const mockedScanContext = scanContext as jest.MockedFunction<typeof scanContext>;
const mockedGetStatus = getScanContextStatus as jest.MockedFunction<typeof getScanContextStatus>;

type Harness = {
    state: () => ScanPollState;
    startScan: () => void;
    unmount: () => void;
};

const mountHarness = async (): Promise<Harness> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    let current: ScanPollState = { phase: 'idle' };
    let startScan: () => void = () => {};

    const Probe = () =>
    {
        const { state, startScan: start } = useContextScan();
        current = state;
        startScan = start;
        return null;
    };

    await act(async () =>
    {
        createRoot(container).render(<Probe />);
    });

    return {
        state: () => current,
        startScan: () => { void startScan(); },
        unmount: () => { document.body.removeChild(container); },
    };
};

describe('useContextScan', () =>
{
    beforeEach(() =>
    {
        jest.useFakeTimers();
        mockedScanContext.mockReset();
        mockedGetStatus.mockReset();
    });

    afterEach(() =>
    {
        jest.useRealTimers();
        document.body.innerHTML = '';
    });

    it('polls pending until done and returns the context', async () =>
    {
        mockedScanContext.mockResolvedValue({ status: 'pending' });
        mockedGetStatus
            .mockResolvedValueOnce({ status: 'pending', phase: 'collecting', sampled: 3, total: 8 })
            .mockResolvedValueOnce({ status: 'done', context: 'A site about translation', applied: true });

        const harness = await mountHarness();

        await act(async () =>
        {
            harness.startScan();
        });
        expect(harness.state().phase).toBe('scanning');

        await act(async () =>
        {
            jest.advanceTimersByTime(3000);
        });
        await act(async () =>
        {
            jest.advanceTimersByTime(3000);
        });

        expect(harness.state().phase).toBe('done');
        const done = harness.state() as { context: string; pages: number; applied: boolean };
        expect(done.context).toBe('A site about translation');
        expect(done.pages).toBe(8);
        expect(done.applied).toBe(true);
        harness.unmount();
    });

    it('carries no page count into done when no progress poll reported one', async () =>
    {
        mockedScanContext.mockResolvedValue({ status: 'pending' });
        mockedGetStatus.mockResolvedValueOnce({ status: 'done', context: 'Read in one go', applied: false });

        const harness = await mountHarness();
        await act(async () =>
        {
            harness.startScan();
        });
        await act(async () =>
        {
            jest.advanceTimersByTime(3000);
        });

        const done = harness.state() as { phase: string; pages: number; applied: boolean };
        expect(done.phase).toBe('done');
        expect(done.pages).toBe(0);
        expect(done.applied).toBe(false);
        harness.unmount();
    });

    it('shows the error state when the scan fails', async () =>
    {
        mockedScanContext.mockResolvedValue({ status: 'pending' });
        mockedGetStatus.mockResolvedValue({ status: 'failed', reason: 'context_scan_unavailable' });

        const harness = await mountHarness();

        await act(async () =>
        {
            harness.startScan();
        });
        await act(async () =>
        {
            jest.advanceTimersByTime(3000);
        });

        expect(harness.state().phase).toBe('failed');
        harness.unmount();
    });

    it('shows the error state when polling times out', async () =>
    {
        mockedScanContext.mockResolvedValue({ status: 'pending' });
        mockedGetStatus.mockResolvedValue({ status: 'pending' });

        const harness = await mountHarness();

        await act(async () =>
        {
            harness.startScan();
        });
        await act(async () =>
        {
            await jest.advanceTimersByTimeAsync(300_000);
        });

        expect(harness.state().phase).toBe('failed');
        harness.unmount();
    });

    it('keeps polling while the server reports pending past three minutes', async () =>
    {
        mockedScanContext.mockResolvedValue({ status: 'pending' });
        mockedGetStatus.mockResolvedValue({ status: 'pending', phase: 'analysing', sampled: 7, total: 7, elapsedSeconds: 200 });

        const harness = await mountHarness();

        await act(async () =>
        {
            harness.startScan();
        });
        await act(async () =>
        {
            await jest.advanceTimersByTimeAsync(200_000);
        });

        expect(harness.state().phase).toBe('scanning');
        harness.unmount();
    });

    it('takes the elapsed seconds from the server while the scan is pending', async () =>
    {
        mockedScanContext.mockResolvedValue({ status: 'pending' });
        mockedGetStatus.mockResolvedValue({ status: 'pending', phase: 'analysing', sampled: 7, total: 7, elapsedSeconds: 42 });

        const harness = await mountHarness();

        await act(async () =>
        {
            harness.startScan();
        });
        await act(async () =>
        {
            await jest.advanceTimersByTimeAsync(3000);
        });

        const state = harness.state();
        expect(state.phase).toBe('scanning');
        expect(state.phase === 'scanning' ? state.elapsedSeconds : -1).toBe(42);
        harness.unmount();
    });
});
