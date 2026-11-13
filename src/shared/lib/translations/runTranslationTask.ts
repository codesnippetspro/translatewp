export const TRANSLATION_IO_TIMEOUT_MS = 20000;

export type TranslationTaskOptions<T> = {
    run: (signal: AbortSignal) => Promise<T>;
    signal?: AbortSignal;
    timeoutMs: number;
};

export const runTranslationTask = <T>({ run, signal, timeoutMs }: TranslationTaskOptions<T>): Promise<T> =>
{
    if (signal?.aborted) return Promise.reject(new DOMException('Translation aborted.', 'AbortError'));
    const controller = new AbortController();
    return new Promise<T>((resolve, reject) =>
    {
        let settled = false;
        const cleanup = () =>
        {
            clearTimeout(timer);
            signal?.removeEventListener('abort', abort);
            controller.abort();
        };
        const fail = (error: unknown) =>
        {
            if (settled) return;
            settled = true;
            cleanup();
            reject(error);
        };
        const abort = () => fail(new DOMException('Translation aborted.', 'AbortError'));
        const timer = setTimeout(
            () => fail(new DOMException('Translation timed out.', 'TimeoutError')), timeoutMs,
        );
        signal?.addEventListener('abort', abort, { once: true });
        let task: Promise<T>;
        try
        {
            task = run(controller.signal);
        }
        catch (error)
        {
            fail(error);
            return;
        }
        task.then(value =>
        {
            if (settled) return;
            settled = true;
            cleanup();
            resolve(value);
        }, fail);
    });
};
