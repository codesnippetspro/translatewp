import { TextEncoder } from 'util';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

import { CacheFirstTranslator } from '@/shared/lib/translations/CacheFirstTranslator';
import { HydrationStore, type HydrationBackend } from '@/shared/lib/translations/hydrationStore';
import { isServiceBlocked, watchServiceBlock } from '@/shared/lib/translations/serviceBlock';
import { TranslationCacheClient } from '@/shared/lib/translations/TranslationCacheClient';
import { TranslationEngine } from '@/shared/lib/translations/TranslationEngine';

const API_BASE = 'https://api.example.test';
const PROXY_BASE = 'https://example.com/wp-json/translatewp/v1/translation';

class EmptyBackend implements HydrationBackend
{
    async getMany(): Promise<Map<string, string>> { return new Map(); }
    async putMany(): Promise<void> {}
    async touch(): Promise<void> {}
    async evictLeastRecentlyUsed(): Promise<void> {}
    async estimateBytes(): Promise<number> { return 0; }
}

const dispatchViolation = (fields: Record<string, string>): void =>
{
    document.dispatchEvent(Object.assign(new Event('securitypolicyviolation'), fields));
};

const violation = (blockedURI: string, disposition: 'enforce' | 'report' = 'enforce'): void =>
{
    dispatchViolation({ blockedURI, disposition, effectiveDirective: 'connect-src' });
};

const loadingElements = (): Element[] => Array.from(document.querySelectorAll('.translatewp-translating, [aria-busy], [data-translatewp-loading-count]'));

type Calls = { lookup: number; miss: number };

const engineFor = (lookupFailure: () => Promise<never>): { engine: TranslationEngine; calls: Calls } =>
{
    const calls: Calls = { lookup: 0, miss: 0 };
    const fetchImpl = (async (url: string) =>
    {
        if (url.startsWith(API_BASE))
        {
            calls.lookup += 1;
            return lookupFailure();
        }
        if (url.endsWith('/miss'))
        {
            calls.miss += 1;
            return { ok: false, status: 400, headers: new Headers(), json: async () => ({}) } as Response;
        }
        throw new Error(`Unexpected fetch: ${url}`);
    }) as unknown as typeof fetch;
    const translator = new CacheFirstTranslator({
        sourceLang: 'en', targetLang: 'es', store: new HydrationStore(new EmptyBackend()), fetchImpl, delay: async () => {},
    });
    translator._createCacheClient = () => new TranslationCacheClient({
        sourceLang: 'en', targetLang: 'es', siteId: 'site-1', apiBaseUrl: API_BASE, aiSettings: {}, sampleOrigin: () => false, fetchImpl,
    });

    return { engine: new TranslationEngine({ sourceLang: 'en', targetLang: 'es', translator }), calls };
};

const originalTranslateWP = window.TranslateWP;
let unwatch: VoidFunction = () => {};
let onBlocked: jest.Mock;

beforeEach(() =>
{
    Object.defineProperty(window, 'TranslateWP', {
        value: {
            context: { site_id: 'site-1', translation_base_url: PROXY_BASE, remote_api_base: `${API_BASE}/`, nonce: 'nonce-1', home_url: 'https://example.com' },
            options: { ai_settings: {} },
        },
        configurable: true,
        writable: true,
    });
    document.body.innerHTML = '<main><p>Hello world</p><p>Second paragraph</p></main>';
    onBlocked = jest.fn();
    unwatch = watchServiceBlock(`${API_BASE}/`, onBlocked);
});

afterEach(() =>
{
    unwatch();
    Object.defineProperty(window, 'TranslateWP', { value: originalTranslateWP, configurable: true, writable: true });
    document.body.innerHTML = '';
});

describe('a security policy that blocks the service', () =>
{
    it('stops the loading state at once, keeps the source text and reports the block once', async () =>
    {
        const { engine, calls } = engineFor(async () =>
        {
            violation(`${API_BASE}/v1/translation/cache/lookup?x=1`);
            throw new TypeError('Failed to fetch');
        });

        const result = await engine.run(document.body);

        expect(result.failures.length).toBeGreaterThan(0);
        expect(result.failures.every(failure => failure.error === 'service_blocked')).toBe(true);
        expect(loadingElements()).toHaveLength(0);
        expect(document.body.textContent).toBe('Hello worldSecond paragraph');
        expect(calls.miss).toBe(0);
        expect(onBlocked).toHaveBeenCalledTimes(1);
        expect(onBlocked).toHaveBeenCalledWith(API_BASE);

        const lookups = calls.lookup;
        await engine.run(document.body);
        expect(calls.lookup).toBe(lookups);
        expect(onBlocked).toHaveBeenCalledTimes(1);
    });

    it('counts the block when the violation event arrives just after the failed request', async () =>
    {
        const { engine, calls } = engineFor(async () =>
        {
            setTimeout(() => violation(`${API_BASE}/v1/translation/cache/lookup`), 0);
            throw new TypeError('Failed to fetch');
        });

        const result = await engine.run(document.body);

        expect(result.failures.every(failure => failure.error === 'service_blocked')).toBe(true);
        expect(calls.miss).toBe(0);
        expect(onBlocked).toHaveBeenCalledTimes(1);
    });

    it('ignores a violation for another origin', async () =>
    {
        const { engine, calls } = engineFor(async () =>
        {
            violation('https://cdn.example.com/script.js');
            throw new TypeError('Failed to fetch');
        });

        const result = await engine.run(document.body);

        expect(isServiceBlocked()).toBe(false);
        expect(onBlocked).not.toHaveBeenCalled();
        expect(result.failures.some(failure => failure.error === 'service_blocked')).toBe(false);
        expect(calls.miss).toBeGreaterThan(0);
        expect(console).toHaveWarned();
    });

    it('ignores a report-only policy, which lets the request through', () =>
    {
        violation(`${API_BASE}/v1/translation/cache/lookup`, 'report');

        expect(isServiceBlocked()).toBe(false);
        expect(onBlocked).not.toHaveBeenCalled();
    });

    it('counts a violation that names only the service origin', () =>
    {
        violation(API_BASE);

        expect(isServiceBlocked()).toBe(true);
        expect(onBlocked).toHaveBeenCalledTimes(1);
    });

    it('counts a default-src violation for a request to the service', () =>
    {
        dispatchViolation({ blockedURI: `${API_BASE}/v1/translation/cache/lookup`, disposition: 'enforce', violatedDirective: 'default-src' });

        expect(isServiceBlocked()).toBe(true);
    });

    it.each([
        ['without a disposition', { blockedURI: `${API_BASE}/v1/x`, effectiveDirective: 'connect-src' }],
        ['for an image from the service origin', { blockedURI: `${API_BASE}/flag.png`, disposition: 'enforce', effectiveDirective: 'img-src' }],
        ['without a directive', { blockedURI: `${API_BASE}/v1/x`, disposition: 'enforce' }],
    ])('ignores a violation %s', (_label, fields) =>
    {
        dispatchViolation(fields);

        expect(isServiceBlocked()).toBe(false);
        expect(onBlocked).not.toHaveBeenCalled();
    });

    it.each(['', 'not a url', 'data:text/plain,x'])('never counts a block when the service origin is %p', async (base) =>
    {
        unwatch();
        unwatch = watchServiceBlock(base, onBlocked);

        dispatchViolation({ blockedURI: 'inline', disposition: 'enforce', effectiveDirective: 'connect-src' });
        dispatchViolation({ blockedURI: 'data:text/plain,y', disposition: 'enforce', effectiveDirective: 'connect-src' });

        expect(isServiceBlocked()).toBe(false);
        expect(onBlocked).not.toHaveBeenCalled();
    });

    it('does not report a plain network error as a block', async () =>
    {
        const { engine, calls } = engineFor(async () =>
        {
            throw new TypeError('Failed to fetch');
        });

        const result = await engine.run(document.body);

        expect(isServiceBlocked()).toBe(false);
        expect(onBlocked).not.toHaveBeenCalled();
        expect(result.failures.some(failure => failure.error === 'service_blocked')).toBe(false);
        expect(calls.miss).toBeGreaterThan(0);
        expect(loadingElements()).toHaveLength(0);
        expect(console).toHaveWarned();
    });
});
