import {
    TranslationEventClient,
    type EventSourceLike,
    type TranslationEventClientTimers,
} from '@/shared/lib/translations/TranslationEventClient';

class FakeEventSource implements EventSourceLike
{
    listeners = new Map<string, Set<(event: MessageEvent) => void>>();
    closed = false;

    addEventListener(type: string, listener: (event: MessageEvent) => void): void
    {
        const listeners = this.listeners.get(type) ?? new Set();
        listeners.add(listener);
        this.listeners.set(type, listeners);
    }

    close(): void
    {
        this.closed = true;
    }

    emit(type: string, data: unknown = {}): void
    {
        const event = { data: typeof data === 'string' ? data : JSON.stringify(data) } as MessageEvent;
        for (const listener of this.listeners.get(type) ?? []) listener(event);
    }
}

function timerHarness(now = Date.parse('2026-09-16T10:00:00.000Z'))
{
    const callbacks = new Map<number, { callback: () => void; milliseconds: number }>();
    let next = 0;
    const timers: TranslationEventClientTimers = {
        now: () => now,
        setTimeout: (callback, milliseconds) => { const id = ++next; callbacks.set(id, { callback, milliseconds }); return id; },
        clearTimeout: handle => { callbacks.delete(Number(handle)); },
    };
    return { timers, callbacks };
}

const subscription = {
    url: 'https://api.example.test/v1/translation/cache/events?token=signed',
    expiresAt: '2026-09-16T10:05:00.000Z',
};

describe('TranslationEventClient', () =>
{
    it('delivers bound ready and failed events and completes', async () =>
    {
        const source = new FakeEventSource();
        const ready = jest.fn();
        const failed = jest.fn();
        const { timers } = timerHarness();
        const client = new TranslationEventClient({ eventSourceFactory: () => source, timers });
        const delivery = client.deliver({
            subscription,
            hashes: ['hash-a', 'hash-b'],
            onReady: ready,
            onFailed: failed,
        });

        source.emit('open');
        source.emit('ready', { hash: 'hash-a', payload: 'Hola' });
        source.emit('failed', { hash: 'hash-b', code: 'translation_failed' });
        source.emit('complete', { delivered: 1, failed: 1 });

        await expect(delivery).resolves.toBe('complete');
        expect(ready).toHaveBeenCalledWith('hash-a', 'Hola');
        expect(failed).toHaveBeenCalledWith('hash-b', 'translation_failed');
        expect(source.closed).toBe(true);
    });

    it('falls back for malformed or foreign events', async () =>
    {
        const source = new FakeEventSource();
        const { timers } = timerHarness();
        const client = new TranslationEventClient({ eventSourceFactory: () => source, timers });
        const delivery = client.deliver({
            subscription,
            hashes: ['hash-a'],
            onReady: jest.fn(),
            onFailed: jest.fn(),
        });

        source.emit('open');
        source.emit('ready', { hash: 'foreign', payload: 'secret' });

        await expect(delivery).resolves.toBe('fallback');
    });

    it('ignores events for texts the same subscription covers for another part of the page', async () =>
    {
        // The page title and the body report their misses together and share
        // one subscription; each listens for its own texts only.
        const source = new FakeEventSource();
        const { timers } = timerHarness();
        const ready = jest.fn();
        const client = new TranslationEventClient({ eventSourceFactory: () => source, timers });
        const delivery = client.deliver({
            subscription,
            hashes: ['hash-title'],
            covered: ['hash-title', 'hash-body', 'hash-other'],
            onReady: ready,
            onFailed: jest.fn(),
        });

        source.emit('open');
        source.emit('ready', { hash: 'hash-body', payload: 'Cuerpo' });
        source.emit('failed', { hash: 'hash-other', code: 'translation_failed' });
        expect(source.closed).toBe(false);
        source.emit('ready', { hash: 'hash-title', payload: 'Titulo' });

        // Its own texts are all in: it finishes without waiting for the rest.
        await expect(delivery).resolves.toBe('complete');
        expect(ready.mock.calls).toEqual([['hash-title', 'Titulo']]);
        expect(source.closed).toBe(true);
    });

    it('still falls back for an event the subscription does not cover', async () =>
    {
        const source = new FakeEventSource();
        const { timers } = timerHarness();
        const client = new TranslationEventClient({ eventSourceFactory: () => source, timers });
        const delivery = client.deliver({
            subscription,
            hashes: ['hash-title'],
            covered: ['hash-title', 'hash-body'],
            onReady: jest.fn(),
            onFailed: jest.fn(),
        });

        source.emit('open');
        source.emit('ready', { hash: 'foreign', payload: 'secret' });

        await expect(delivery).resolves.toBe('fallback');
    });

    it('falls back after the open timeout and two reconnect attempts', async () =>
    {
        const sources: FakeEventSource[] = [];
        const { timers, callbacks } = timerHarness();
        const client = new TranslationEventClient({
            eventSourceFactory: () => {
                const source = new FakeEventSource();
                sources.push(source);
                return source;
            },
            timers,
        });
        const delivery = client.deliver({
            subscription,
            hashes: ['hash-a'],
            onReady: jest.fn(),
            onFailed: jest.fn(),
        });

        for (let attempt = 0; attempt < 3; attempt++)
        {
            const openTimeout = [...callbacks.values()].find(timer => timer.milliseconds === 5000)?.callback;
            expect(openTimeout).toBeDefined();
            openTimeout?.();
        }

        await expect(delivery).resolves.toBe('fallback');
        expect(sources).toHaveLength(3);
        expect(sources.every(source => source.closed)).toBe(true);
    });

    it('reconnects after an error and accepts replay on the next stream', async () =>
    {
        const sources: FakeEventSource[] = [];
        const { timers } = timerHarness();
        const client = new TranslationEventClient({
            eventSourceFactory: () => {
                const source = new FakeEventSource();
                sources.push(source);
                return source;
            },
            timers,
        });
        const ready = jest.fn();
        const delivery = client.deliver({ subscription, hashes: ['hash-a'], onReady: ready, onFailed: jest.fn() });

        sources[0].emit('error');
        sources[1].emit('open');
        sources[1].emit('ready', { hash: 'hash-a', payload: 'Hola' });
        sources[1].emit('complete');

        await expect(delivery).resolves.toBe('complete');
        expect(ready).toHaveBeenCalledWith('hash-a', 'Hola');
    });

    it('falls back on explicit fallback and subscription expiry', async () =>
    {
        const explicitSource = new FakeEventSource();
        const explicitHarness = timerHarness();
        const explicitClient = new TranslationEventClient({ eventSourceFactory: () => explicitSource, timers: explicitHarness.timers });
        const explicit = explicitClient.deliver({ subscription, hashes: ['hash-a'], onReady: jest.fn(), onFailed: jest.fn() });
        explicitSource.emit('open');
        explicitSource.emit('fallback', { reason: 'cache_unavailable' });
        await expect(explicit).resolves.toBe('fallback');

        const expirySource = new FakeEventSource();
        const expiryHarness = timerHarness();
        const expiryClient = new TranslationEventClient({ eventSourceFactory: () => expirySource, timers: expiryHarness.timers });
        const expiry = expiryClient.deliver({ subscription, hashes: ['hash-a'], onReady: jest.fn(), onFailed: jest.fn() });
        const expiryCallback = [...expiryHarness.callbacks.values()].find(timer => timer.milliseconds > 5000)?.callback;
        expiryCallback?.();
        await expect(expiry).resolves.toBe('fallback');
    });

    it('closes and rejects on abort', async () =>
    {
        const source = new FakeEventSource();
        const controller = new AbortController();
        const { timers } = timerHarness();
        const client = new TranslationEventClient({ eventSourceFactory: () => source, timers });
        const delivery = client.deliver({
            subscription,
            hashes: ['hash-a'],
            signal: controller.signal,
            onReady: jest.fn(),
            onFailed: jest.fn(),
        });

        controller.abort();

        await expect(delivery).rejects.toMatchObject({ name: 'AbortError' });
        expect(source.closed).toBe(true);
    });

    it('falls back when EventSource is unavailable', async () =>
    {
        const client = new TranslationEventClient({ eventSourceFactory: null });

        await expect(client.deliver({
            subscription,
            hashes: ['hash-a'],
            onReady: jest.fn(),
            onFailed: jest.fn(),
        })).resolves.toBe('fallback');
    });
});
