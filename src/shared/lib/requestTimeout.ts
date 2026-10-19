import apiFetch from '@wordpress/api-fetch';

export const REQUEST_TIMEOUT_MS = 20000;

type Options = Parameters<typeof apiFetch>[0] & { signal?: AbortSignal };
type Next = (options: Options) => Promise<unknown>;

/**
 * A REST request that has not answered within the limit is abandoned and
 * fails like any other failed request, so the screen can say so and offer a
 * retry instead of waiting on it. A caller's own signal keeps working.
 */
export const requestTimeoutMiddleware = (options: Options, next: Next, timeoutMs: number = REQUEST_TIMEOUT_MS): Promise<unknown> =>
{
    const controller = new AbortController();
    const abort = () => controller.abort();
    const timer = window.setTimeout(abort, timeoutMs);

    if (options.signal) {
        if (options.signal.aborted) {
            abort();
        } else {
            options.signal.addEventListener('abort', abort, { once: true });
        }
    }

    return next({ ...options, signal: controller.signal }).finally(() =>
    {
        window.clearTimeout(timer);
        options.signal?.removeEventListener('abort', abort);
    });
};

export const installRequestTimeout = (timeoutMs: number = REQUEST_TIMEOUT_MS): void =>
{
    apiFetch.use((options, next) => requestTimeoutMiddleware(options as Options, next as Next, timeoutMs) as ReturnType<typeof next>);
};
