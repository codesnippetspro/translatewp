import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { runBackgroundStep, type BackgroundStep } from '@/shared/api/admin';
import { useBackgroundStep } from '@/shared/hooks/useBackgroundStep';

jest.mock('@/shared/api/admin', () => ({ runBackgroundStep: jest.fn() }));
(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const answer = (state: BackgroundStep['state'], pending = 1): BackgroundStep => ({ state, pending: { read: pending, push: 0, refill: 0, visitor: 0 }, v: 1 });

describe('Indexing background fallback', () => {
    let root: Root;
    let latest: ReturnType<typeof useBackgroundStep>;
    const Probe = ({ paused = false }: { paused?: boolean }) => { latest = useBackgroundStep(paused); return null; };
    beforeEach(() => { jest.useFakeTimers(); (runBackgroundStep as jest.Mock).mockReset().mockResolvedValue(answer('more')); root = createRoot(document.createElement('div')); });
    afterEach(async () => { await act(async () => root.unmount()); jest.useRealTimers(); });
    const mount = async (paused = false) => { await act(async () => root.render(<Probe paused={paused} />)); };

    it('runs one bounded request at a time and retries no faster than a minute', async () => {
        let finish!: (value: BackgroundStep) => void;
        (runBackgroundStep as jest.Mock).mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
        await mount();
        await act(async () => jest.advanceTimersByTime(180000));
        expect(runBackgroundStep).toHaveBeenCalledTimes(1);
        await act(async () => finish(answer('more')));
        await act(async () => jest.advanceTimersByTime(59999));
        expect(runBackgroundStep).toHaveBeenCalledTimes(1);
        await act(async () => jest.advanceTimersByTime(1));
        expect(runBackgroundStep).toHaveBeenCalledTimes(2);
    });

    it('guards leaving while work waits and releases the guard when done', async () => {
        await mount();
        const before = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(before);
        expect(before.defaultPrevented).toBe(true);
        expect(latest!.pending).toBe(true);
        (runBackgroundStep as jest.Mock).mockResolvedValue(answer('done', 0));
        await act(async () => jest.advanceTimersByTime(60000));
        const after = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(after);
        expect(after.defaultPrevented).toBe(false);
        expect(latest!.pending).toBe(false);
    });

    it('stops the loop while paused and resumes only when pause is cleared', async () => {
        (runBackgroundStep as jest.Mock).mockResolvedValue(answer('paused'));
        await mount();
        await act(async () => jest.advanceTimersByTime(300000));
        expect(runBackgroundStep).toHaveBeenCalledTimes(1);
        expect(latest!.pending).toBe(false);
        await mount(true);
        await act(async () => jest.advanceTimersByTime(300000));
        expect(runBackgroundStep).toHaveBeenCalledTimes(1);
        (runBackgroundStep as jest.Mock).mockResolvedValue(answer('more'));
        await mount(false);
        expect(runBackgroundStep).toHaveBeenCalledTimes(2);
    });

    it('releases the leave guard when another driver pauses the site', async () => {
        await mount();
        expect(latest!.pending).toBe(true);
        await mount(true);
        expect(latest!.step?.state).toBe('paused');
        expect(latest!.pending).toBe(false);
        await act(async () => jest.advanceTimersByTime(300000));
        expect(runBackgroundStep).toHaveBeenCalledTimes(1);
    });

    it('aborts on unmount and does not schedule another request', async () => {
        await mount();
        const signal = (runBackgroundStep as jest.Mock).mock.calls[0][0] as AbortSignal;
        await act(async () => root.unmount());
        expect(signal.aborted).toBe(true);
        await act(async () => jest.advanceTimersByTime(300000));
        expect(runBackgroundStep).toHaveBeenCalledTimes(1);
    });
});
