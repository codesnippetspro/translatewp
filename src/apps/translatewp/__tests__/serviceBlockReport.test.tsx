import { TextEncoder } from 'util';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

import apiFetch from '@wordpress/api-fetch';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { TranslateWP } from '@/apps/translatewp';
import golden from '../../../../tests/fixtures/translation-fingerprint-golden.json';

jest.mock('@wordpress/api-fetch', () => ({ __esModule: true, default: jest.fn(() => Promise.resolve({ recorded: true })) }));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const API_BASE = 'https://api.example.test';
const goldenSettings = (golden.vectors as Array<{ id: string; settings: unknown }>)
    .find((vector) => vector.id === 'basic-proxy-fixture')!.settings;
const languages = {
    en: { locale: 'en', slug: 'en', active: true, default: true },
    es: { locale: 'es', slug: 'es', active: true, default: false },
};
const renderedRoots: Array<ReturnType<typeof createRoot>> = [];
const originalFetch = globalThis.fetch;
let serviceRequests = 0;

const installContext = (administers: boolean): void =>
{
    Object.defineProperty(window, 'TranslateWP', {
        value: {
            context: {
                home_url: 'http://localhost',
                domain: 'localhost',
                site_id: 'site-1',
                nonce: 'nonce-1',
                remote_api_base: API_BASE,
                admin_base_url: 'http://localhost/wp-json/translatewp/v1/admin',
                translation_base_url: 'http://localhost/wp-json/translatewp/v1/translation',
                connected: true,
                can_administer: administers,
            },
            options: {
                languages: { list: languages, switcher: { type: 'dropdown' }, seo: {} },
                auto_translate: false,
                extraction_options: { includeAlt: false, includeAttributes: false },
                ai_settings: goldenSettings,
            },
        },
        configurable: true,
        writable: true,
    });
};

const settle = async (ms = 0): Promise<void> =>
{
    await act(async () =>
    {
        await new Promise(resolve => setTimeout(resolve, ms));
    });
};

const visitBlockedPage = async (administers: boolean): Promise<void> =>
{
    installContext(administers);
    document.body.innerHTML = '<span data-twp-switcher></span><p>Shop the collection</p>';
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    renderedRoots.push(root);
    await act(async () =>
    {
        root.render(<TranslateWP />);
    });
    await act(async () =>
    {
        (document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement).click();
    });
    const spanish = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
        .find((item) => item.textContent?.includes('Español')) as HTMLButtonElement;
    act(() =>
    {
        spanish.click();
    });
    await settle(50);
    await settle(50);
};

beforeEach(() =>
{
    serviceRequests = 0;
    globalThis.fetch = jest.fn(async (input: RequestInfo | URL) =>
    {
        const url = String(input);
        if (!url.startsWith(API_BASE)) throw new Error(`Unexpected fetch: ${url}`);
        serviceRequests += 1;
        document.dispatchEvent(Object.assign(new Event('securitypolicyviolation'), { blockedURI: url, disposition: 'enforce', effectiveDirective: 'connect-src' }));
        throw new TypeError('Failed to fetch');
    }) as unknown as typeof fetch;
});

afterEach(async () =>
{
    for (const root of renderedRoots.splice(0))
    {
        act(() =>
        {
            root.unmount();
        });
    }
    await settle();
    globalThis.fetch = originalFetch;
    jest.clearAllMocks();
    document.body.innerHTML = '';
    document.cookie = 'translatewp_last_choice=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    window.history.replaceState(null, '', '/');
    delete (window as { TranslateWP?: unknown }).TranslateWP;
});

describe('a page whose security policy blocks the service', () =>
{
    it('settles the switch on the source text and reports the block once for an administrator', async () =>
    {
        await visitBlockedPage(true);

        const trigger = document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement;
        expect(trigger.hasAttribute('aria-busy')).toBe(false);
        expect(document.querySelector('p')?.textContent).toBe('Shop the collection');
        expect(document.querySelector('[aria-busy],[data-translatewp-loading-count],.translatewp-translating')).toBeNull();
        expect(serviceRequests).toBeGreaterThan(0);
        expect(apiFetch).toHaveBeenCalledTimes(1);
        expect(apiFetch).toHaveBeenCalledWith(expect.objectContaining({
            path: '/translatewp/v1/admin/service-blocked',
            method: 'POST',
            headers: { 'X-WP-Nonce': 'nonce-1' },
            data: { origin: API_BASE, path: '/es/' },
        }));
        expect(console).toHaveErrored();
        expect(console).toHaveWarned();
    });

    it('never reports for a visitor', async () =>
    {
        await visitBlockedPage(false);

        expect(document.querySelector('p')?.textContent).toBe('Shop the collection');
        expect(serviceRequests).toBeGreaterThan(0);
        expect(apiFetch).not.toHaveBeenCalled();
        expect(console).toHaveErrored();
        expect(console).toHaveWarned();
    });
});
