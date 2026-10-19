import { computeFingerprintHash } from '@/shared/lib/translations/fingerprintPayload';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { TextEncoder } from 'util';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import type { Language } from '@/shared/components/languageTable';
import { DOMExtractor } from '@/shared/lib/translations/DOMExtractor';
import { TranslationCacheClient } from '@/shared/lib/translations/TranslationCacheClient';
import type { TranslateWPAiSettings } from '@/shared/types';
import golden from '../../../../../../../tests/fixtures/translation-fingerprint-golden.json';
import {
    activeLanguageLocales,
    defaultLocale,
    extractFailedPageAgain,
    extractionFailureReason,
    extractRowItems,
    readAdminExtractionConfig,
    runExtractionLoop,
    runIndexSite,
    type ExtractRow,
    type IndexPhase,
    type IndexPushItem,
    type IndexStatus,
} from '../extractionDriver';

jest.mock('@wordpress/api-fetch', () => ({
    __esModule: true,
    default: jest.fn(),
}));

import apiFetch from '@wordpress/api-fetch';

const apiFetchMock = apiFetch as unknown as jest.Mock;

type GoldenVector = {
    id: string;
    settings: TranslateWPAiSettings;
    targetLang: string;
};

const basicFixture = (golden.vectors as GoldenVector[]).find(vector => vector.id === 'basic-proxy-fixture')!;

const ROW: ExtractRow = {
    id: 7,
    url: 'https://example.com/shop/',
    language: 'es',
    objectType: 'page',
    objectId: 42,
};

const ADMIN_LANGUAGES: Language[] = [
    { locale: 'en', slug: 'en', active: true, default: true },
    { locale: 'es', slug: 'es', active: true, default: false },
] as unknown as Language[];

const originalTranslateWP = window.TranslateWP;
const pristineStoreState = useAdminSettingsStore.getState();

// The wp-admin runtime shape: the frontend localizer never runs, so the admin
// script localizes the connection context and lexicon terms on window, and the
// settings store (seeded here) holds languages, extraction options and the
// AI-settings scalars. The store defaults map to the golden basic fixture.
function installAdminShape(languages: Language[] = ADMIN_LANGUAGES): void
{
    Object.defineProperty(window, 'TranslateWP', {
        value: {
            context: {
                home_url: 'https://example.com',
                site_id: 'site-1',
                nonce: 'nonce-1',
                remote_api_base: 'https://api.example.test',
                translation_base_url: 'https://example.com/wp-json/translatewp/v1/translation',
            },
            lexicon_terms: [],
            host: { version: 1 },
        },
        configurable: true,
        writable: true,
    });

    useAdminSettingsStore.setState({
        isInitialized: true,
        isLoading: false,
        persistedSettings: { ...pristineStoreState.persistedSettings, 'languages.list': languages },
        settings: { ...pristineStoreState.settings, 'languages.list': languages },
    });
}

beforeEach(() =>
{
    installAdminShape();
    apiFetchMock.mockReset();
});

afterEach(() =>
{
    useAdminSettingsStore.setState(pristineStoreState, true);
    Object.defineProperty(window, 'TranslateWP', {
        value: originalTranslateWP,
        configurable: true,
        writable: true,
    });
    document.body.innerHTML = '';
});

describe('readAdminExtractionConfig page areas', () =>
{
    it('lists the areas the persisted settings exclude, in the order the runtime uses', () =>
    {
        const state = useAdminSettingsStore.getState();
        useAdminSettingsStore.setState({
            persistedSettings: {
                ...state.persistedSettings,
                'translations.settings': { ...state.persistedSettings['translations.settings'], translate_comments: false, translate_widgets: false },
            },
        });

        expect(readAdminExtractionConfig().extractionOptions.excludedAreas).toEqual(['comments', 'widgets']);
    });

    it('excludes nothing by default', () =>
    {
        expect(readAdminExtractionConfig().extractionOptions.excludedAreas).toEqual([]);
    });
});

describe('readAdminExtractionConfig advanced style controls', () =>
{
    const withStyle = (style: Record<string, unknown>) =>
    {
        const state = useAdminSettingsStore.getState();
        useAdminSettingsStore.setState({
            persistedSettings: { ...state.persistedSettings, 'translations.style': { ...state.persistedSettings['translations.style'], ...style } as never },
        });
    };

    afterEach(() => { delete (window as unknown as { translatewpPro?: unknown }).translatewpPro; });

    it('sends audience, length and cultural adaptation with Pro, and nothing at their defaults', () =>
    {
        (window as unknown as { translatewpPro?: unknown }).translatewpPro = { connected: true, entitled: true, installed: true, active: true, version: '1.0.0', upgradeUrl: '' };
        withStyle({ audience: ' Small business owners ', length: 'shorter', cultural_adaptation: 'adapt' });
        const settings = readAdminExtractionConfig().aiSettings;
        expect(settings.audience).toBe('Small business owners');
        expect(settings.length).toBe('shorter');
        expect(settings.cultural_adaptation).toBe('adapt');

        withStyle({ audience: '', length: 'match', cultural_adaptation: 'keep' });
        const untouched = readAdminExtractionConfig().aiSettings;
        expect('audience' in untouched).toBe(false);
        expect('length' in untouched).toBe(false);
        expect('cultural_adaptation' in untouched).toBe(false);
    });

    it('sends none of them without Pro', () =>
    {
        withStyle({ audience: 'Experts', length: 'longer', cultural_adaptation: 'adapt' });
        const settings = readAdminExtractionConfig().aiSettings;
        expect('audience' in settings).toBe(false);
        expect('length' in settings).toBe(false);
        expect('cultural_adaptation' in settings).toBe(false);
    });
});

describe('extractRowItems', () =>
{
    it('produces the same fingerprints as the visitor pipeline for the same page', async () =>
    {
        const fixtureHtml = readFileSync(
            resolve(__dirname, '../../../../../../../tests/fixtures/elementor-shop-capture.html'),
            'utf8'
        );

        // Visitor pipeline reference: same markup mounted in the live DOM.
        document.body.innerHTML = fixtureHtml;
        const visitorEntries = new DOMExtractor({ noTranslateIds: ['wpadminbar'] }).extract(document.body, undefined);
        const visitorClient = new TranslationCacheClient({
            sourceLang: 'en',
            targetLang: 'es',
            siteId: 'site-1',
            apiBaseUrl: 'https://api.example.test',
            aiSettings: basicFixture.settings,
        });
        const visitorPlan = await visitorClient.prepare(visitorEntries);
        const visitorHashes = visitorPlan.groupsForCache.map(group => group.hash);
        expect(visitorHashes.length).toBeGreaterThan(0);

        const items = await extractRowItems(
            ROW,
            html => new DOMParser().parseFromString(html, 'text/html'),
            async () => `<html><body>${fixtureHtml}</body></html>`,
        );

        expect(items.map(item => item.cache.hash)).toEqual([...new Set(visitorHashes)]);
        expect(items.every(item => item.cache.siteId === 'site-1')).toBe(true);
        expect(items.every(item => item.groupId && item.markerPayload)).toBe(true);
    });

    it('warms the page title in the visitor runtime shape', async () =>
    {
        const items = await extractRowItems(
            ROW,
            html => new DOMParser().parseFromString(html, 'text/html'),
            async () => '<html><head><title> Horario de la tienda </title></head><body><p>Abierto hoy</p></body></html>',
        );
        const titleItem = items.find(item => item.contextKey === 'seo:title');
        expect(titleItem).toBeDefined();
        expect(titleItem?.contextKind).toBe('attribute');
        expect(titleItem?.markerPayload).toBe('\u27EAitem_0001\u27EBHorario de la tienda');

        const expected = await computeFingerprintHash({
            markerPayload: titleItem!.markerPayload,
            sourceLang: 'en',
            targetLang: 'es',
            contextKind: 'attribute',
            contextKey: 'seo:title',
            settings: basicFixture.settings,
        });
        expect(titleItem?.cache.hash).toBe(expected);
    });

    it('adds no title item when the page has no title', async () =>
    {
        const items = await extractRowItems(
            ROW,
            html => new DOMParser().parseFromString(html, 'text/html'),
            async () => '<html><body><p>Abierto hoy</p></body></html>',
        );
        expect(items.some(item => item.contextKey === 'seo:title')).toBe(false);
    });

    it('drops oversize marker payloads instead of pushing them', async () =>
    {
        const hugeText = 'word '.repeat(40_000);
        const items = await extractRowItems(
            ROW,
            html => new DOMParser().parseFromString(html, 'text/html'),
            async () => `<html><body><p>Small text</p><p>${hugeText}</p></body></html>`,
        );

        expect(items.length).toBe(1);
        expect(items[0].markerPayload).toContain('Small text');
    });

    it('throws a typed error when the page fetch fails', async () =>
    {
        globalThis.fetch = jest.fn(async () => ({ ok: false, status: 500 })) as unknown as typeof fetch;

        await expect(extractRowItems(ROW)).rejects.toThrow('page_fetch_500');
        globalThis.fetch = jest.fn(async () => ({ ok: false, status: 404 })) as unknown as typeof fetch;
        await expect(extractRowItems(ROW)).rejects.toThrow('page_fetch_404');
    });

    it('reads the 404 page from its 404 response, since that is the page', async () =>
    {
        globalThis.fetch = jest.fn(async () => ({
            ok: false,
            status: 404,
            redirected: false,
            url: 'https://example.com/translatewp-page-not-found/',
            headers: { get: () => 'text/html' },
            text: async () => '<html><body><main><h1>Page not found</h1><p>Nothing lives at this address.</p></main></body></html>',
        })) as unknown as typeof fetch;

        const items = await extractRowItems({ ...ROW, objectType: 'not_found', objectId: -1, url: 'https://example.com/translatewp-page-not-found/' });
        expect(items.map(item => item.markerPayload).join(' ')).toContain('Nothing lives at this address.');
    });

    it('warms the public rendering instead of administrator-only text', async () =>
    {
        const fetchMock = jest.fn(async (_url, options) => ({
            ok: true,
            status: 200,
            text: async () => options.credentials === 'omit'
                ? '<html><body><p>Hello</p></body></html>'
                : '<html><body><p>Hello <a class="post-edit-link">Edit</a></p></body></html>',
        }));
        globalThis.fetch = fetchMock as unknown as typeof fetch;

        const items = await extractRowItems(ROW);

        expect(items.map(item => item.markerPayload).join('')).not.toContain('Edit');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it.each([401, 403])('falls back to the session when the public page returns %s', async status => {
        const fetchMock = jest.fn().mockResolvedValueOnce({ ok: false, status }).mockResolvedValueOnce({ ok: true, status: 200, text: async () => '<html><body><p>Protected content</p></body></html>' });
        globalThis.fetch = fetchMock as unknown as typeof fetch;
        const items = await extractRowItems(ROW);
        expect(items.map(item => item.markerPayload).join('')).toContain('Protected content');
        expect(fetchMock).toHaveBeenNthCalledWith(2, ROW.url, expect.objectContaining({ credentials: 'same-origin' }));
    });

    it('reports a non-HTML response as page_not_html', async () =>
    {
        globalThis.fetch = jest.fn(async () => ({
            ok: true,
            status: 200,
            redirected: false,
            url: ROW.url,
            headers: { get: () => 'application/pdf' },
            text: async () => '%PDF-1.7',
        })) as unknown as typeof fetch;

        await expect(extractRowItems(ROW)).rejects.toThrow('page_not_html');
    });

    it('reports an off-site redirect as page_redirected', async () =>
    {
        globalThis.fetch = jest.fn(async () => ({
            ok: true,
            status: 200,
            redirected: true,
            url: 'https://login.example.com/sign-in',
            headers: { get: () => 'text/html' },
            text: async () => '<html></html>',
        })) as unknown as typeof fetch;

        await expect(extractRowItems(ROW)).rejects.toThrow('page_redirected');
    });

    it('reports an aborted fetch as page_fetch_timeout', async () =>
    {
        globalThis.fetch = jest.fn(async () =>
        {
            throw new DOMException('aborted', 'AbortError');
        }) as unknown as typeof fetch;

        await expect(extractRowItems(ROW)).rejects.toThrow('page_fetch_timeout');
    });
});

describe('extractionFailureReason', () =>
{
    it('keeps a thrown Error message, truncated', () =>
    {
        expect(extractionFailureReason(new Error('page_fetch_503'))).toBe('page_fetch_503');
        expect(extractionFailureReason(new Error('x'.repeat(400))).length).toBe(200);
    });

    it('maps the too-many-items server rejection to too_many_segments', () =>
    {
        expect(extractionFailureReason({
            code: 'invalid_parameter',
            message: 'Too many items in one submission',
            data: { status: 400 },
        })).toBe('too_many_segments');
    });

    it('keeps a concrete server message from an apiFetch rejection', () =>
    {
        expect(extractionFailureReason({
            code: 'conflict',
            message: 'Row is not claimed for extraction',
            data: { status: 409 },
        })).toBe('Row is not claimed for extraction');
    });

    it('falls back to the HTTP status when a rejection carries no message or code', () =>
    {
        expect(extractionFailureReason({ data: { status: 503 } })).toBe('submit_failed_503');
    });

    it('uses the generic reason for an unrecognised value', () =>
    {
        expect(extractionFailureReason(null)).toBe('extract_failed');
        expect(extractionFailureReason(42)).toBe('extract_failed');
    });
});

describe('runExtractionLoop', () =>
{
    it('claims, extracts, submits, and reports failures per row', async () =>
    {
        const rows: ExtractRow[] = [
            { ...ROW, id: 1, url: 'https://example.com/shop/' },
            { ...ROW, id: 2, url: 'https://example.com/about/' },
        ];
        let claimCall = 0;
        const submitted: Array<{ id: number; items: IndexPushItem[] }> = [];
        const failed: Array<{ id: number; reason: string }> = [];

        apiFetchMock.mockImplementation(async (options: { path: string; data?: Record<string, unknown> }) =>
        {
            if (options.path.endsWith('/claim-extract'))
            {
                claimCall++;
                return { rows: claimCall === 1 ? rows : [] };
            }
            if (options.path.endsWith('/submit-extract'))
            {
                submitted.push(options.data as { id: number; items: IndexPushItem[] });
                return { stored: true };
            }
            if (options.path.endsWith('/extract-failed'))
            {
                failed.push(options.data as { id: number; reason: string });
                return { recorded: true };
            }
            throw new Error(`Unexpected apiFetch path: ${options.path}`);
        });

        const item: IndexPushItem = {
            groupId: 'group-1',
            markerPayload: '⟪item_0001⟫Hello',
            contextKey: 'body>p',
            contextKind: 'text-flow',
            cache: { siteId: 'site-1', hash: 'hash-1' },
        };
        const extract = jest.fn()
            .mockResolvedValueOnce([item])
            .mockRejectedValueOnce(new Error('page_fetch_500'));

        const progress = await runExtractionLoop(() => true, () => {}, extract);

        expect(progress).toEqual(expect.objectContaining({ processed: 1, failed: 1 }));
        expect(submitted).toEqual([{ id: 1, items: [item] }]);
        expect(failed).toEqual([{ id: 2, reason: 'page_fetch_500' }]);
    });

    it('collapses one page claimed for two languages to a single processed page', async () =>
    {
        const rows: ExtractRow[] = [
            { ...ROW, id: 1, language: 'de' },
            { ...ROW, id: 2, language: 'it' },
        ];
        let claimCall = 0;

        apiFetchMock.mockImplementation(async (options: { path: string }) =>
        {
            if (options.path.endsWith('/claim-extract'))
            {
                claimCall++;
                return { rows: claimCall === 1 ? rows : [] };
            }
            if (options.path.endsWith('/submit-extract')) return { stored: true };
            if (options.path.endsWith('/extract-failed')) return { recorded: true };
            throw new Error(`Unexpected apiFetch path: ${options.path}`);
        });

        const item: IndexPushItem = {
            groupId: 'group-1',
            markerPayload: '⟪item_0001⟫Hello',
            contextKey: 'body>p',
            contextKind: 'text-flow',
            cache: { siteId: 'site-1', hash: 'hash-1' },
        };
        const extract = jest.fn()
            .mockRejectedValueOnce(new Error('page_fetch_500'))
            .mockResolvedValueOnce([item]);

        const progress = await runExtractionLoop(() => true, () => {}, extract);

        expect(progress).toEqual(expect.objectContaining({ processed: 1, failed: 0 }));
    });

    it('counts a page failed once when every language for it fails', async () =>
    {
        const rows: ExtractRow[] = [
            { ...ROW, id: 1, language: 'de' },
            { ...ROW, id: 2, language: 'it' },
        ];
        let claimCall = 0;

        apiFetchMock.mockImplementation(async (options: { path: string }) =>
        {
            if (options.path.endsWith('/claim-extract'))
            {
                claimCall++;
                return { rows: claimCall === 1 ? rows : [] };
            }
            if (options.path.endsWith('/extract-failed')) return { recorded: true };
            throw new Error(`Unexpected apiFetch path: ${options.path}`);
        });

        const extract = jest.fn().mockRejectedValue(new Error('page_fetch_500'));

        const progress = await runExtractionLoop(() => true, () => {}, extract);

        expect(progress).toEqual(expect.objectContaining({ processed: 0, failed: 1 }));
    });

    it('submits an empty extraction as a completed page', async () =>
    {
        let claimCall = 0;
        const submitted: Array<{ id: number; items: IndexPushItem[] }> = [];

        apiFetchMock.mockImplementation(async (options: { path: string; data?: Record<string, unknown> }) =>
        {
            if (options.path.endsWith('/claim-extract'))
            {
                claimCall++;
                return { rows: claimCall === 1 ? [ROW] : [] };
            }
            if (options.path.endsWith('/submit-extract'))
            {
                submitted.push(options.data as { id: number; items: IndexPushItem[] });
                return { stored: true };
            }
            throw new Error(`Unexpected apiFetch path: ${options.path}`);
        });

        const progress = await runExtractionLoop(() => true, () => {}, jest.fn().mockResolvedValue([]));

        expect(progress).toEqual(expect.objectContaining({ processed: 1, failed: 0 }));
        expect(submitted).toEqual([{ id: ROW.id, items: [] }]);
    });

    it('stops immediately when the caller cancels', async () =>
    {
        apiFetchMock.mockImplementation(async () => ({ rows: [ROW] }));
        const extract = jest.fn();

        const progress = await runExtractionLoop(() => false, () => {}, extract);

        expect(progress).toEqual(expect.objectContaining({ processed: 0, failed: 0 }));
        expect(extract).not.toHaveBeenCalled();
        expect(apiFetchMock).not.toHaveBeenCalled();
    });

    it('submits a page with more items than the request cap as several batches', async () =>
    {
        const submitted: Array<{ id: number; items: IndexPushItem[]; more?: boolean }> = [];
        let claimCall = 0;
        apiFetchMock.mockImplementation(async (options: { path: string; data?: Record<string, unknown> }) =>
        {
            if (options.path.endsWith('/claim-extract'))
            {
                claimCall++;
                return { rows: claimCall === 1 ? [ROW] : [] };
            }
            if (options.path.endsWith('/submit-extract'))
            {
                submitted.push(options.data as { id: number; items: IndexPushItem[]; more?: boolean });
                return { stored: true };
            }
            throw new Error(`Unexpected apiFetch path: ${options.path}`);
        });

        const items: IndexPushItem[] = Array.from({ length: 250 }, (_unused, index) => ({
            groupId: `group-${index}`,
            markerPayload: `⟪item_${index}⟫Hello`,
            contextKey: 'body>p',
            contextKind: 'text-flow',
            cache: { siteId: 'site-1', hash: `hash-${index}` },
        }));

        const progress = await runExtractionLoop(() => true, () => {}, jest.fn().mockResolvedValue(items));

        expect(progress).toEqual(expect.objectContaining({ processed: 1, failed: 0 }));
        expect(submitted).toHaveLength(2);
        expect(submitted[0].items).toHaveLength(200);
        expect(submitted[0].more).toBe(true);
        expect(submitted[1].items).toHaveLength(50);
        expect(submitted[1].more).toBeUndefined();
        expect(submitted.every(batch => batch.id === ROW.id)).toBe(true);
    });
});

describe('extractFailedPageAgain', () =>
{
    const revivedRow = { id: 9, url: ROW.url, language: 'de', objectType: 'page', objectId: 42 };

    it('revives the row, extracts and submits it, then reports ok', async () =>
    {
        const paths: string[] = [];
        apiFetchMock.mockImplementation(async (options: { path: string }) =>
        {
            paths.push(options.path);
            if (options.path.endsWith('/reextract-page')) return { row: revivedRow };
            if (options.path.endsWith('/submit-extract')) return { stored: true };
            throw new Error(`Unexpected apiFetch path: ${options.path}`);
        });

        const item: IndexPushItem = {
            groupId: 'g',
            markerPayload: '⟪item_0001⟫Hi',
            contextKey: 'body>p',
            contextKind: 'text-flow',
            cache: { siteId: 'site-1', hash: 'h' },
        };

        const result = await extractFailedPageAgain(9, jest.fn().mockResolvedValue([item]));

        expect(result).toEqual({ ok: true });
        expect(paths.some(path => path.endsWith('/reextract-page'))).toBe(true);
        expect(paths.some(path => path.endsWith('/submit-extract'))).toBe(true);
    });

    it('reports the concrete reason when the retried page fails again', async () =>
    {
        const reported: Array<{ id: number; reason: string }> = [];
        apiFetchMock.mockImplementation(async (options: { path: string; data?: Record<string, unknown> }) =>
        {
            if (options.path.endsWith('/reextract-page')) return { row: revivedRow };
            if (options.path.endsWith('/extract-failed'))
            {
                reported.push(options.data as { id: number; reason: string });
                return { recorded: true };
            }
            throw new Error(`Unexpected apiFetch path: ${options.path}`);
        });

        const result = await extractFailedPageAgain(9, jest.fn().mockRejectedValue(new Error('page_fetch_500')));

        expect(result).toEqual({ ok: false, reason: 'page_fetch_500' });
        expect(reported).toEqual([{ id: 9, reason: 'page_fetch_500' }]);
    });

    it('returns not_failed when the row cannot be revived', async () =>
    {
        apiFetchMock.mockImplementation(async (options: { path: string }) =>
        {
            if (options.path.endsWith('/reextract-page')) return { row: null };
            throw new Error(`Unexpected apiFetch path: ${options.path}`);
        });

        const extract = jest.fn();
        expect(await extractFailedPageAgain(9, extract)).toEqual({ ok: false, reason: 'not_failed' });
        expect(extract).not.toHaveBeenCalled();
    });
});

describe('activeLanguageLocales', () =>
{
    it('returns every active locale in list order, source included, from the store', () =>
    {
        expect(activeLanguageLocales()).toEqual(['en', 'es']);
    });

    it('returns an empty list before any language is set up', () =>
    {
        installAdminShape([]);

        expect(() => activeLanguageLocales()).not.toThrow();
        expect(activeLanguageLocales()).toEqual([]);
    });
});

describe('defaultLocale', () =>
{
    it('reads the source locale from the settings store', () =>
    {
        expect(defaultLocale()).toBe('en');
    });

    it('falls back to the first active language when none is marked default', () =>
    {
        installAdminShape([
            { locale: 'de', slug: 'de', active: true, default: false },
            { locale: 'fr', slug: 'fr', active: true, default: false },
        ] as unknown as Language[]);

        expect(defaultLocale()).toBe('de');
    });

    it('returns auto before any language is active', () =>
    {
        installAdminShape([]);

        expect(defaultLocale()).toBe('auto');
    });
});

describe('runIndexSite', () =>
{
    const statusWith = (pendingExtract: number): IndexStatus => ({
        languages: {
            de: {
                total: pendingExtract,
                done: 0,
                dead: 0,
                pending_extract: pendingExtract,
                pending_push: 0,
                complete: false,
                reported: false,
            },
        },
        drainScheduled: true,
        scheduledTasksLate: false,
        lastRun: null,
        failed: [],
        pins: [],
    });

    const item: IndexPushItem = {
        groupId: 'group-1',
        markerPayload: '⟪item_0001⟫Hello',
        contextKey: 'body>p',
        contextKind: 'text-flow',
        cache: { siteId: 'site-1', hash: 'hash-1' },
    };

    it('queues the index, extracts in this tab, then syncs, in that order', async () =>
    {
        const paths: string[] = [];
        let claimCall = 0;
        apiFetchMock.mockImplementation(async (options: { path: string }) =>
        {
            paths.push(options.path);
            if (options.path.endsWith('/enqueue')) return { enqueued: 2, status: statusWith(2) };
            if (options.path.endsWith('/claim-extract'))
            {
                claimCall++;
                return {
                    rows: claimCall === 1
                        ? [
                            { ...ROW, id: 1, url: 'https://example.com/shop/' },
                            { ...ROW, id: 2, url: 'https://example.com/about/' },
                        ]
                        : [],
                };
            }
            if (options.path.endsWith('/submit-extract')) return { stored: true };
            if (options.path.endsWith('/sync-now')) return { status: statusWith(0) };
            throw new Error(`Unexpected apiFetch path: ${options.path}`);
        });

        const phases: IndexPhase[] = [];
        const statuses: IndexStatus[] = [];
        let total = -1;
        let unit = '';
        const extract = jest.fn().mockResolvedValue([item]);

        const progress = await runIndexSite({
            shouldContinue: () => true,
            onStatus: snapshot => statuses.push(snapshot),
            onPhase: current => phases.push(current),
            onProgress: () => {},
            onTotal: (count, countUnit) => { total = count; unit = countUnit; },
            extract,
        });

        expect(phases).toEqual(['fetching', 'translating', 'done']);
        expect(total).toBe(2);
        expect(unit).toBe('pages');
        expect(progress).toEqual(expect.objectContaining({ processed: 2, failed: 0 }));
        expect(extract).toHaveBeenCalledTimes(2);
        expect(statuses).toHaveLength(2);

        const order = (suffix: string) => paths.findIndex(path => path.endsWith(suffix));
        expect(order('/enqueue')).toBeLessThan(order('/claim-extract'));
        expect(order('/claim-extract')).toBeLessThan(order('/sync-now'));
    });

    it('stops extraction cleanly and still syncs what was extracted', async () =>
    {
        const paths: string[] = [];
        let claimCall = 0;
        apiFetchMock.mockImplementation(async (options: { path: string }) =>
        {
            paths.push(options.path);
            if (options.path.endsWith('/enqueue')) return { enqueued: 2, status: statusWith(2) };
            if (options.path.endsWith('/claim-extract'))
            {
                claimCall++;
                return { rows: claimCall === 1 ? [{ ...ROW, id: 1 }, { ...ROW, id: 2 }] : [] };
            }
            if (options.path.endsWith('/submit-extract')) return { stored: true };
            if (options.path.endsWith('/sync-now')) return { status: statusWith(0) };
            throw new Error(`Unexpected apiFetch path: ${options.path}`);
        });

        let keepGoing = true;
        const extract = jest.fn().mockImplementation(async () =>
        {
            keepGoing = false;
            return [item];
        });

        const phases: IndexPhase[] = [];
        const progress = await runIndexSite({
            shouldContinue: () => keepGoing,
            onStatus: () => {},
            onPhase: current => phases.push(current),
            onProgress: () => {},
            onTotal: () => {},
            extract,
        });

        expect(extract).toHaveBeenCalledTimes(1);
        expect(progress).toEqual(expect.objectContaining({ processed: 1, failed: 0 }));
        expect(phases).toEqual(['fetching', 'translating', 'done']);
        expect(paths.some(path => path.endsWith('/sync-now'))).toBe(true);
    });

    const statusWithLanguages = (pendingByLanguage: Record<string, number>): IndexStatus => ({
        languages: Object.fromEntries(
            Object.entries(pendingByLanguage).map(([language, pendingExtract]) => [
                language,
                {
                    total: pendingExtract,
                    done: 0,
                    dead: 0,
                    pending_extract: pendingExtract,
                    pending_push: 0,
                    complete: false,
                    reported: false,
                },
            ])
        ),
        drainScheduled: true,
        scheduledTasksLate: false,
        lastRun: null,
        failed: [],
        pins: [],
    });

    it('collapses the pending count to distinct pages when every target language agrees', async () =>
    {
        apiFetchMock.mockImplementation(async (options: { path: string }) =>
        {
            if (options.path.endsWith('/enqueue'))
            {
                return { enqueued: 81, status: statusWithLanguages({ 'en-us': 27, de: 27, it: 27 }) };
            }
            if (options.path.endsWith('/claim-extract')) return { rows: [] };
            if (options.path.endsWith('/sync-now')) return { status: statusWithLanguages({}) };
            throw new Error(`Unexpected apiFetch path: ${options.path}`);
        });

        let total = -1;
        let unit = '';

        await runIndexSite({
            shouldContinue: () => true,
            onStatus: () => {},
            onPhase: () => {},
            onProgress: () => {},
            onTotal: (count, countUnit) => { total = count; unit = countUnit; },
        });

        expect(total).toBe(27);
        expect(unit).toBe('pages');
    });

    it('falls back to a row count when target languages disagree on what is pending', async () =>
    {
        apiFetchMock.mockImplementation(async (options: { path: string }) =>
        {
            if (options.path.endsWith('/enqueue'))
            {
                return { enqueued: 40, status: statusWithLanguages({ de: 27, it: 13 }) };
            }
            if (options.path.endsWith('/claim-extract')) return { rows: [] };
            if (options.path.endsWith('/sync-now')) return { status: statusWithLanguages({}) };
            throw new Error(`Unexpected apiFetch path: ${options.path}`);
        });

        let total = -1;
        let unit = '';

        await runIndexSite({
            shouldContinue: () => true,
            onStatus: () => {},
            onPhase: () => {},
            onProgress: () => {},
            onTotal: (count, countUnit) => { total = count; unit = countUnit; },
        });

        expect(total).toBe(40);
        expect(unit).toBe('rows');
    });
});
