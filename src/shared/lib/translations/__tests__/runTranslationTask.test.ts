import { runTranslationTask } from '@/shared/lib/translations/runTranslationTask';

describe('bounded cancellable translation tasks', () =>
{
    beforeEach(() => { jest.useFakeTimers(); });
    afterEach(() => { jest.useRealTimers(); });

    it('does not start an already cancelled task', async () =>
    {
        const controller = new AbortController();
        controller.abort();
        const run = jest.fn();
        await expect(runTranslationTask({ run, signal: controller.signal, timeoutMs: 20 }))
            .rejects.toMatchObject({ name: 'AbortError' });
        expect(run).not.toHaveBeenCalled();
        expect(jest.getTimerCount()).toBe(0);
    });

    it('releases a held task on abort and ignores its late result', async () =>
    {
        const controller = new AbortController();
        let deliver!: (value: string) => void;
        let child!: AbortSignal;
        const remove = jest.spyOn(controller.signal, 'removeEventListener');
        const task = runTranslationTask({
            signal: controller.signal, timeoutMs: 100,
            run: signal => { child = signal; return new Promise(resolve => { deliver = resolve; }); },
        });
        const rejected = jest.fn();
        void task.catch(rejected);
        controller.abort();
        await Promise.resolve();
        expect(rejected).toHaveBeenCalledWith(expect.objectContaining({ name: 'AbortError' }));
        await expect(task).rejects.toMatchObject({ name: 'AbortError' });
        expect(child.aborted).toBe(true);
        expect(remove).toHaveBeenCalledWith('abort', expect.any(Function));
        expect(jest.getTimerCount()).toBe(0);
        deliver('Late');
        await Promise.resolve();
        await expect(task).rejects.toMatchObject({ name: 'AbortError' });
    });

    it('times out at its bound, aborts the child, and consumes a late rejection', async () =>
    {
        let fail!: (error: Error) => void;
        let child!: AbortSignal;
        const task = runTranslationTask({ timeoutMs: 20, run: signal =>
        {
            child = signal;
            return new Promise((_, reject) => { fail = reject; });
        } });
        const rejected = jest.fn();
        void task.catch(rejected);
        await jest.advanceTimersByTimeAsync(19);
        expect(child.aborted).toBe(false);
        await jest.advanceTimersByTimeAsync(1);
        expect(rejected).toHaveBeenCalledWith(expect.objectContaining({ name: 'TimeoutError' }));
        await expect(task).rejects.toMatchObject({ name: 'TimeoutError' });
        expect(child.aborted).toBe(true);
        fail(new Error('Late failure'));
        await Promise.resolve();
        expect(jest.getTimerCount()).toBe(0);
    });

    it.each(['resolve', 'reject', 'throw'])('cleans up when the provider will %s', async mode =>
    {
        const controller = new AbortController();
        let child!: AbortSignal;
        const task = runTranslationTask({ signal: controller.signal, timeoutMs: 20, run: signal =>
        {
            child = signal;
            if (mode === 'throw') throw new Error('Provider');
            return mode === 'reject' ? Promise.reject(new Error('Provider')) : Promise.resolve('Translated');
        } });
        const settled = jest.fn();
        void task.then(value => settled({ value }), error => settled({ error }));
        await Promise.resolve();
        await Promise.resolve();
        expect(settled).toHaveBeenCalledTimes(1);
        if (mode === 'resolve') await expect(task).resolves.toBe('Translated');
        else await expect(task).rejects.toThrow('Provider');
        expect(child.aborted).toBe(true);
        expect(jest.getTimerCount()).toBe(0);
        controller.abort();
        if (mode === 'resolve') await expect(task).resolves.toBe('Translated');
    });
});
