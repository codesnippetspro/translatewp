import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import { useTranslationReadiness } from '@/shared/hooks/useTranslationReadiness';

jest.mock('@wordpress/api-fetch', () => ({ __esModule: true, default: jest.fn() }));
(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

class FakeSource {
    static instances: FakeSource[] = [];
    listeners = new Map<string, (event: MessageEvent) => void>();
    closed = false;
    constructor(readonly url: string) { FakeSource.instances.push(this); }
    addEventListener(type: string, callback: (event: MessageEvent) => void) { this.listeners.set(type, callback); }
    close() { this.closed = true; }
    emit(type: string, data: unknown) { this.listeners.get(type)?.(new MessageEvent(type, { data: JSON.stringify(data) })); }
}

const hash = 'a'.repeat(64);
const result = (ready: boolean) => ({
    cursor: 0, language: 'it', hashes: ready ? [] : [hash],
    subscription: ready ? null : { url: 'https://api.example/events?token=signed', expiresAt: new Date(Date.now() + 60000).toISOString() },
    pages: { pages: [{ title: 'Home', url: 'https://example.com/', languages: { it: { status: ready ? 'indexed' : 'pending' } } }], totals: { rows: 1, segments: 1 } },
    status: { languages: { it: { ready: ready ? 1 : 0 } } },
    translations: { translationsToday: ready ? 1 : 0, languageUsage: [], recentContent: [] },
});

describe('shared live readiness', () => {
    let root: Root;
    let client: QueryClient;
    const originalSource = globalThis.EventSource;
    beforeEach(() => {
        jest.useFakeTimers();
        FakeSource.instances = [];
        globalThis.EventSource = FakeSource as unknown as typeof EventSource;
        (apiFetch as jest.Mock).mockReset().mockResolvedValue(result(false));
        client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        client.setQueryData(['setupTranslationProgress', ['it']], { pages: { pages: [] } });
        client.setQueryData(['dashboardMetrics'], { translationsToday: 0, cacheHitsToday: 12 });
    });
    afterEach(async () => {
        if (root) await act(async () => root.unmount());
        client.clear();
        globalThis.EventSource = originalSource;
        jest.useRealTimers();
    });
    const mount = async () => {
        const Probe = () => { useTranslationReadiness(); return null; };
        root = createRoot(document.createElement('div'));
        await act(async () => { root.render(<QueryClientProvider client={client}><Probe /></QueryClientProvider>); });
    };

    it('reconciles SSE readiness immediately into all three projections', async () => {
        await mount();
        expect(FakeSource.instances).toHaveLength(1);
        (apiFetch as jest.Mock).mockResolvedValue(result(true));
        await act(async () => { FakeSource.instances[0].emit('ready', { hash, payload: '⟪item_0001⟫Ciao' }); });
        expect(client.getQueryData(['indexPages'])).toEqual(result(true).pages);
        expect(client.getQueryData(['setupTranslationProgress', ['it']])).toEqual({ status: result(true).status, pages: result(true).pages });
        expect(client.getQueryData(['dashboardMetrics'])).toMatchObject({ translationsToday: 1, cacheHitsToday: 12 });
    });

    it('uses periodic reconciliation when SSE is unavailable', async () => {
        globalThis.EventSource = undefined as unknown as typeof EventSource;
        await mount();
        (apiFetch as jest.Mock).mockResolvedValue(result(true));
        await act(async () => { jest.advanceTimersByTime(6000); });
        expect(client.getQueryData(['indexPages'])).toEqual(result(true).pages);
    });

    it('closes streams and cancels requests on unmount without cancelling jobs', async () => {
        await mount();
        const signal = (apiFetch as jest.Mock).mock.calls[0][0].signal as AbortSignal;
        await act(async () => root.unmount());
        expect(signal.aborted).toBe(true);
        expect(FakeSource.instances[0].closed).toBe(true);
        await act(async () => { jest.advanceTimersByTime(10000); });
        expect((apiFetch as jest.Mock).mock.calls.map(call => call[0].path)).toEqual(['/translatewp/v1/indexing/readiness']);
    });

    it('keeps the last ready count when a readiness request fails', async () => {
        let seen: ReturnType<typeof useTranslationReadiness>;
        const Probe = () => { seen = useTranslationReadiness(); return null; };
        const confirmed = { ...result(true), status: { languages: { it: { ready: 1, readyTotal: 3, readyComplete: false } } } };
        (apiFetch as jest.Mock).mockResolvedValue(confirmed);
        root = createRoot(document.createElement('div'));
        await act(async () => { root.render(<QueryClientProvider client={client}><Probe /></QueryClientProvider>); });
        (apiFetch as jest.Mock).mockRejectedValue(new Error('network unavailable'));
        await act(async () => { jest.advanceTimersByTime(6000); });
        expect(seen!).toMatchObject({ unconfirmed: true, languages: { it: { ready: 1, readyTotal: 3 } } });
        expect(client.getQueryData(['setupTranslationProgress', ['it']])).toMatchObject({ status: { unconfirmed: true, languages: { it: { ready: 1, readyTotal: 3 } } } });
    });

    it('leaves the page rows the setup figure is counted from as they were shown when a request fails', async () => {
        (apiFetch as jest.Mock).mockResolvedValue(result(true));
        await mount();
        const shown = client.getQueryData<{ pages: unknown }>(['setupTranslationProgress', ['it']])!.pages;
        expect(shown).toMatchObject({ pages: [{ languages: { it: { status: 'indexed' } } }] });

        (apiFetch as jest.Mock).mockRejectedValue(new Error('network unavailable'));
        await act(async () => { jest.advanceTimersByTime(6000); });

        expect(client.getQueryData(['setupTranslationProgress', ['it']])).toMatchObject({ status: { unconfirmed: true }, pages: shown });
    });

    it('withdraws green states when readiness can no longer be checked', async () => {
        (apiFetch as jest.Mock).mockResolvedValue(result(true));
        await mount();
        (apiFetch as jest.Mock).mockRejectedValue(new Error('network unavailable'));
        await act(async () => { jest.advanceTimersByTime(6000); });
        expect(client.getQueryData(['indexPages'])).toMatchObject({ pages: [{ languages: { it: { status: 'pending' } } }] });
        expect(client.getQueryData(['dashboardMetrics'])).toMatchObject({ translationsToday: 0, cacheHitsToday: 12 });
    });
});
