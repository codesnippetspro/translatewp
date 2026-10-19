import { requestTimeoutMiddleware } from '@/shared/lib/requestTimeout';

describe('request timeout', () =>
{
    beforeEach(() => { jest.useFakeTimers(); });
    afterEach(() => { jest.useRealTimers(); });

    it('a request that answers in time passes through untouched', async () =>
    {
        const next = jest.fn(() => Promise.resolve({ ok: true }));

        await expect(requestTimeoutMiddleware({ path: '/x' }, next, 1000)).resolves.toEqual({ ok: true });
        expect((next.mock.calls[0][0] as { signal: AbortSignal }).signal.aborted).toBe(false);
    });

    it('a request that never answers is abandoned when the limit passes', async () =>
    {
        let signal: AbortSignal | undefined;
        const next = jest.fn((options: { signal?: AbortSignal }) => new Promise((_resolve, reject) =>
        {
            signal = options.signal;
            options.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
        }));

        const pending = requestTimeoutMiddleware({ path: '/x' }, next, 1000);
        jest.advanceTimersByTime(999);
        expect(signal?.aborted).toBe(false);
        jest.advanceTimersByTime(1);
        expect(signal?.aborted).toBe(true);
        await expect(pending).rejects.toMatchObject({ name: 'AbortError' });
    });

    it('a caller abort is passed on to the request', async () =>
    {
        const caller = new AbortController();
        let signal: AbortSignal | undefined;
        const next = jest.fn((options: { signal?: AbortSignal }) => new Promise((_resolve, reject) =>
        {
            signal = options.signal;
            options.signal?.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
        }));

        const pending = requestTimeoutMiddleware({ path: '/x', signal: caller.signal }, next, 1000);
        caller.abort();
        expect(signal?.aborted).toBe(true);
        await expect(pending).rejects.toMatchObject({ name: 'AbortError' });
    });
});
