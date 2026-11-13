/**
 * @jest-environment-options {"url":"https://example.com/"}
 */
import { TextEncoder } from 'util';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

import { act } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from 'react-dom/client';

import { TranslateWP } from '@/apps/translatewp';
import { livePlacement } from '@/apps/translatewp/inlinePlacement';
import { frontendHost } from '@/shared/host/frontend';
import { Translator } from '@/shared/lib/translations/Translator';
import { CacheFirstTranslator } from '@/shared/lib/translations/CacheFirstTranslator';
import { CHANGE_SETTLE_MS } from '@/shared/lib/translations/TranslationSession';
import golden from '../../../../tests/fixtures/translation-fingerprint-golden.json';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const goldenSettings = (golden.vectors as Array<{ id: string; settings: unknown }>)
    .find((vector) => vector.id === 'basic-proxy-fixture')!.settings;

const languages = {
    en: { locale: 'en', slug: 'en', active: true, default: true },
    es: { locale: 'es', slug: 'es', active: true, default: false },
};

const renderedRoots: Array<ReturnType<typeof createRoot>> = [];

const installContext = (
    connected: boolean,
    switcherType: string = 'dropdown',
    seo: Record<string, unknown> = {},
    autoTranslate: boolean = false,
    canManage: boolean = true,
    switcherExtra: Record<string, unknown> = {},
) =>
{
    Object.defineProperty(window, 'TranslateWP', {
        value: {
            context: {
                home_url: 'https://example.com',
                domain: 'example.com',
                site_id: 'site-1',
                nonce: 'nonce-1',
                remote_api_base: 'https://api.example.test',
                admin_base_url: 'https://example.com/wp-json/translatewp/v1/admin',
                translation_base_url: 'https://example.com/wp-json/translatewp/v1/translation',
                connected,
            },
            options: {
                languages: { list: languages, switcher: { type: switcherType, ...switcherExtra }, seo },
                auto_translate: autoTranslate,
                extraction_options: { includeAlt: false, includeAttributes: false },
                ai_settings: goldenSettings,
            },
            canManage,
        },
        configurable: true,
        writable: true,
    });
};

const renderApp = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    renderedRoots.push(root);
    await act(async () =>
    {
        root.render(<QueryClientProvider client={new QueryClient()}><TranslateWP /></QueryClientProvider>);
    });
    return container;
};


afterEach(async () =>
{
    for (const root of renderedRoots.splice(0)) await act(async () => { root.unmount(); });
    jest.restoreAllMocks();
    document.body.innerHTML = '';
    document.cookie = 'translatewp_last_choice=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

it('writes Secure on HTTPS session cookies without an expiry', async () =>
{
    installContext(true, 'dropdown', { remember_last_choice: false });
    document.body.innerHTML = '<span data-twp-switcher></span>';
    jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockResolvedValue({
        translated: [], failures: [], totalGroups: 0,
    });
    const cookie = jest.spyOn(Document.prototype, 'cookie', 'set');
    await renderApp();
    await act(async () => { (document.querySelector('.twp-trigger') as HTMLElement).click(); });
    const option = Array.from(document.querySelectorAll('.twp-dropdown-item'))
        .find(element => element.textContent?.includes('Español')) as HTMLElement;
    await act(async () => { option.click(); });
    expect(cookie).toHaveBeenCalledWith('translatewp_last_choice=es; path=/; SameSite=Lax; Secure');
    expect(document.cookie).toContain('translatewp_last_choice=es');
});
