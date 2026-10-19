import { TextEncoder } from 'util';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { TranslateWP } from '@/apps/translatewp';
import { CacheFirstTranslator } from '@/shared/lib/translations/CacheFirstTranslator';
import golden from '../../../../tests/fixtures/translation-fingerprint-golden.json';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const goldenSettings = (golden.vectors as Array<{ id: string; settings: unknown }>)
    .find((vector) => vector.id === 'basic-proxy-fixture')!.settings;

const languages = {
    en: { locale: 'en', slug: 'en', active: true, default: true },
    es: { locale: 'es', slug: 'es', active: true, default: false },
};

const renderedRoots: Array<ReturnType<typeof createRoot>> = [];

const installContext = (connected: boolean, switcherType: string = 'dropdown') =>
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
                languages: { list: languages, switcher: { type: switcherType } },
                auto_translate: false,
                extraction_options: { includeAlt: false, includeAttributes: false },
                ai_settings: goldenSettings,
            },
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
        root.render(<TranslateWP />);
    });
    return container;
};

describe('TranslateWP connection gate', () =>
{
    beforeEach(() =>
    {
        jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockResolvedValue({
            translated: [],
            failures: [],
            totalGroups: 0,
        });
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
        await act(async () =>
        {
            await Promise.resolve();
            await Promise.resolve();
        });
        jest.clearAllTimers();
        jest.clearAllMocks();
        document.body.innerHTML = '';
        delete (window as { TranslateWP?: unknown }).TranslateWP;
    });

    it('renders nothing when the site is not connected', async () =>
    {
        installContext(false);

        const container = await renderApp();

        expect(container.innerHTML).toBe('');
    });

    it('renders the switcher when connected', async () =>
    {
        installContext(true);

        const container = await renderApp();

        expect(container.querySelector('.twp-container')).not.toBeNull();
    });

    it('falls back to the dropdown for a persisted pro switcher type', async () =>
    {
        installContext(true, 'flat_list');

        const container = await renderApp();

        expect(container.querySelector('.twp-container')).not.toBeNull();
        expect(container.querySelector('.twp-trigger')).not.toBeNull();
    });

    it('never enters the translation path when disconnected', async () =>
    {
        installContext(false);
        document.body.innerHTML = '<main><p>Hello world</p></main>';
        window.history.pushState({}, '', '/?lang=es');
        const originalFetch = window.fetch;
        const fetchMock = jest.fn(async () =>
        {
            throw new Error('network must not be touched');
        });
        Object.defineProperty(window, 'fetch', { value: fetchMock, configurable: true, writable: true });

        const container = await renderApp();
        await act(async () =>
        {
            await new Promise((resolve) => setTimeout(resolve, 500));
        });

        expect(container.innerHTML).toBe('');
        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.querySelector('.translatewp-translating')).toBeNull();
        Object.defineProperty(window, 'fetch', { value: originalFetch, configurable: true, writable: true });
        window.history.pushState({}, '', '/');
    });
});
