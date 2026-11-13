import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { useContextScan, type ScanPollState } from '@/shared/hooks/useContextScan';
import { getScanContextStatus, resumeScanFollowup, scanContext } from '@/shared/api/setup';

jest.mock('@/shared/api/setup', () => ({
    getScanContextStatus: jest.fn(), resumeScanFollowup: jest.fn(), scanContext: jest.fn(),
}));

const status = jest.mocked(getScanContextStatus);
const resume = jest.mocked(resumeScanFollowup);
const scan = jest.mocked(scanContext);

describe('stored context scan', () =>
{
    let root: Root;
    let container: HTMLDivElement;
    let state: ScanPollState;
    let startScan: () => Promise<void>;
    const suggestions = [{ term: 'Orbital', description: 'Product name', category: 'Brand' }];
    const Probe = () =>
    {
        ({ state, startScan } = useContextScan({ restoreStored: true }));
        return null;
    };
    const mount = async () => { await act(async () => root.render(<Probe />)); };

    beforeEach(() =>
    {
        (globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
        jest.useFakeTimers();
        jest.resetAllMocks();
        resume.mockResolvedValue({ pending: true });
        scan.mockResolvedValue({ status: 'pending' });
        container = document.createElement('div');
        document.body.appendChild(container);
        root = createRoot(container);
    });
    afterEach(() =>
    {
        act(() => root.unmount());
        container.remove();
        jest.useRealTimers();
    });

    it('restores suggestions with a read-only first request and no model-starting request', async () =>
    {
        status.mockResolvedValue({ status: 'done', context: 'Stored context', lexiconSuggestions: suggestions });
        await mount();
        expect(status).toHaveBeenCalledTimes(1);
        expect(status).toHaveBeenCalledWith({ readOnly: true, runFollowup: false });
        expect(state).toMatchObject({ phase: 'done', restored: true, lexiconSuggestions: suggestions });
        await act(async () => { await jest.advanceTimersByTimeAsync(30_000); });
        expect(status).toHaveBeenCalledTimes(1);
        expect(scan).not.toHaveBeenCalled();
        expect(resume).not.toHaveBeenCalled();
    });

    it('kicks pending background work only after the read-only result and keeps one kick in flight', async () =>
    {
        status.mockResolvedValue({ status: 'done', context: 'Stored context', lexiconSuggestions: suggestions, followupPending: true });
        let finish!: (value: { pending: boolean }) => void;
        resume.mockImplementation(() => new Promise(resolve => { finish = resolve; }));
        await mount();
        expect(resume).not.toHaveBeenCalled();
        await act(async () => { await jest.advanceTimersByTimeAsync(3000); });
        expect(status).toHaveBeenLastCalledWith({ readOnly: false, runFollowup: true });
        expect(resume).toHaveBeenCalledTimes(1);
        await act(async () => { await jest.advanceTimersByTimeAsync(6000); });
        expect(resume).toHaveBeenCalledTimes(1);
        status.mockResolvedValue({ status: 'done', context: 'Stored context', lexiconSuggestions: [...suggestions, { term: 'New', description: 'New term', category: 'Technical' }], followupPending: false });
        await act(async () => { finish({ pending: false }); await jest.advanceTimersByTimeAsync(3000); });
        expect(state).toMatchObject({ phase: 'done', restored: true, lexiconSuggestions: expect.arrayContaining([{ term: 'New', description: 'New term', category: 'Technical' }]) });
        const count = status.mock.calls.length;
        await act(async () => { await jest.advanceTimersByTimeAsync(9000); });
        expect(status).toHaveBeenCalledTimes(count);
        expect(scan).not.toHaveBeenCalled();
    });

    it('ignores a late stored response after a manual scan starts', async () =>
    {
        let restore!: (value: Awaited<ReturnType<typeof getScanContextStatus>>) => void;
        status.mockImplementationOnce(() => new Promise(resolve => { restore = resolve; }));
        await mount();
        await act(async () => { await startScan(); });
        await act(async () => { restore({ status: 'done', context: 'Old result', lexiconSuggestions: suggestions, followupPending: true }); });
        expect(state.phase).toBe('scanning');
        expect(resume).not.toHaveBeenCalled();
        status.mockResolvedValue({ status: 'done', context: 'New scan', lexiconSuggestions: [] });
        await act(async () => { await jest.advanceTimersByTimeAsync(3000); });
        expect(state).toMatchObject({ phase: 'done', restored: false, context: 'New scan' });
    });
});
