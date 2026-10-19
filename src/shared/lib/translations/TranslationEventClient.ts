export type TranslationSubscription = {
    url: string;
    expiresAt: string;
};

export type EventSourceLike = {
    addEventListener(type: string, listener: (event: MessageEvent) => void): void;
    close(): void;
};

export type TranslationEventClientTimers = {
    now: () => number;
    setTimeout(callback: () => void, milliseconds: number): unknown;
    clearTimeout(handle: unknown): void;
};

export type TranslationDeliveryRequest = {
    subscription: TranslationSubscription;
    hashes: string[];
    /**
     * Every text the subscription covers. Parts of a page that reported their
     * misses together share one subscription, so a stream also carries the
     * events of the others: those are passed over, not treated as a fault.
     */
    covered?: string[];
    signal?: AbortSignal;
    onReady: (hash: string, payload: string) => void;
    onFailed: (hash: string, code: string) => void;
};

export type TranslationEventClientOptions = {
    eventSourceFactory?: ((url: string) => EventSourceLike) | null;
    timers?: TranslationEventClientTimers;
};

const OPEN_TIMEOUT_MS = 5000;
const MAX_RECONNECTS = 2;

const defaultTimers: TranslationEventClientTimers = {
    now: Date.now,
    setTimeout: (callback, milliseconds) => setTimeout(callback, milliseconds),
    clearTimeout: handle => clearTimeout(handle as ReturnType<typeof setTimeout>),
};

export class TranslationEventClient
{
    readonly #eventSourceFactory: ((url: string) => EventSourceLike) | null;
    readonly #timers: TranslationEventClientTimers;

    constructor({ eventSourceFactory, timers = defaultTimers }: TranslationEventClientOptions = {})
    {
        this.#eventSourceFactory = eventSourceFactory === undefined
            ? (typeof EventSource === 'undefined' ? null : url => new EventSource(url))
            : eventSourceFactory;
        this.#timers = timers;
    }

    deliver({ subscription, hashes, covered, signal, onReady, onFailed }: TranslationDeliveryRequest): Promise<'complete' | 'fallback'>
    {
        if (!this.#eventSourceFactory) return Promise.resolve('fallback');
        if (signal?.aborted) return Promise.reject(this.#abortError());

        const expiresAt = Date.parse(subscription.expiresAt);
        if (!Number.isFinite(expiresAt) || expiresAt <= this.#timers.now()) return Promise.resolve('fallback');

        return new Promise((resolve, reject) =>
        {
            const remaining = new Set(hashes);
            const others = new Set((covered ?? []).filter(hash => !remaining.has(hash)));
            let source: EventSourceLike | null = null;
            let openTimeout: unknown;
            let expiryTimeout: unknown;
            let reconnects = 0;
            let settled = false;

            const cleanupSource = (): void =>
            {
                if (openTimeout !== undefined) this.#timers.clearTimeout(openTimeout);
                openTimeout = undefined;
                source?.close();
                source = null;
            };
            const cleanup = (): void =>
            {
                cleanupSource();
                if (expiryTimeout !== undefined) this.#timers.clearTimeout(expiryTimeout);
                signal?.removeEventListener('abort', abort);
            };
            const settle = (outcome: 'complete' | 'fallback'): void =>
            {
                if (settled) return;
                settled = true;
                cleanup();
                resolve(outcome);
            };
            const failProtocol = (): void => settle('fallback');
            const parse = (event: MessageEvent): Record<string, unknown> | null =>
            {
                try
                {
                    const value = JSON.parse(String(event.data));
                    return value && typeof value === 'object' && !Array.isArray(value)
                        ? value as Record<string, unknown>
                        : null;
                }
                catch
                {
                    return null;
                }
            };
            const acceptTerminal = (
                event: MessageEvent,
                valueKey: 'payload' | 'code',
                accept: (hash: string, value: string) => void,
            ): void =>
            {
                const body = parse(event);
                const hash = body?.hash;
                const value = body?.[valueKey];
                if (typeof hash === 'string' && typeof value === 'string' && others.has(hash)) return;
                if (typeof hash !== 'string' || typeof value !== 'string' || !remaining.delete(hash))
                {
                    failProtocol();
                    return;
                }
                try
                {
                    accept(hash, value);
                }
                catch
                {
                    failProtocol();
                    return;
                }
                // Sharing a stream, this part is done when its own texts are in.
                if (others.size > 0 && remaining.size === 0) settle('complete');
            };
            const connect = (): void =>
            {
                if (settled) return;
                try
                {
                    source = this.#eventSourceFactory!(subscription.url);
                    source.addEventListener('open', () =>
                    {
                        if (openTimeout !== undefined) this.#timers.clearTimeout(openTimeout);
                        openTimeout = undefined;
                    });
                    source.addEventListener('ready', event => acceptTerminal(event, 'payload', onReady));
                    source.addEventListener('failed', event => acceptTerminal(event, 'code', onFailed));
                    source.addEventListener('complete', () => settle(remaining.size === 0 ? 'complete' : 'fallback'));
                    source.addEventListener('fallback', () => settle('fallback'));
                    source.addEventListener('error', reconnect);
                    openTimeout = this.#timers.setTimeout(reconnect, OPEN_TIMEOUT_MS);
                }
                catch
                {
                    reconnect();
                }
            };
            const reconnect = (): void =>
            {
                if (settled) return;
                cleanupSource();
                if (reconnects >= MAX_RECONNECTS)
                {
                    settle('fallback');
                    return;
                }
                reconnects += 1;
                connect();
            };
            const abort = (): void =>
            {
                if (settled) return;
                settled = true;
                cleanup();
                reject(this.#abortError());
            };

            signal?.addEventListener('abort', abort, { once: true });
            connect();
            expiryTimeout = this.#timers.setTimeout(
                () => settle('fallback'),
                Math.max(1, expiresAt - this.#timers.now()),
            );
        });
    }

    #abortError(): DOMException
    {
        return new DOMException('[Translator] Translation aborted.', 'AbortError');
    }
}

export default TranslationEventClient;
