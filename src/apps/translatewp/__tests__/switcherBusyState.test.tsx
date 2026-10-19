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
    it: { locale: 'it', slug: 'it', active: true, default: false },
};

const renderedRoots: Array<ReturnType<typeof createRoot>> = [];

const installContext = () =>
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
                connected: true,
            },
            options: {
                languages: { list: languages, switcher: { type: 'dropdown' }, seo: {} },
                auto_translate: false,
                extraction_options: { includeAlt: false, includeAttributes: false },
                ai_settings: goldenSettings,
            },
            canManage: true,
        },
        configurable: true,
        writable: true,
    });
};

const renderApp = async (): Promise<void> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    renderedRoots.push(root);
    await act(async () =>
    {
        root.render(<TranslateWP />);
    });
};

const trigger = (): HTMLButtonElement => document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement;

const pick = async (label: string): Promise<void> =>
{
    await act(async () =>
    {
        trigger().click();
    });
    const option = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
        .find((item) => item.textContent?.includes(label)) as HTMLButtonElement;
    act(() =>
    {
        option.click();
    });
    await act(async () =>
    {
        await Promise.resolve();
        await Promise.resolve();
    });
};

const settle = async (): Promise<void> =>
{
    await act(async () =>
    {
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();
    });
};

const isBusy = (): boolean =>
    trigger().getAttribute('data-translating') === 'true' && trigger().getAttribute('aria-busy') === 'true';

// The trigger reads as busy from the language choice until that switch
// settles: on resolve, on rejection, and when a later choice supersedes it.
describe('switcher busy state', () =>
{
    let pending: Array<{ resolve: (value: { translated: never[]; failures: never[]; totalGroups: number }) => void; reject: (error: Error) => void }> = [];

    beforeEach(() =>
    {
        pending = [];
        jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockImplementation(() => new Promise((resolve, reject) =>
        {
            pending.push({ resolve, reject });
        }));
        installContext();
        document.body.innerHTML = '<span data-twp-switcher></span><p>Shop the collection</p>';
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
        jest.clearAllMocks();
        document.body.innerHTML = '';
        document.cookie = 'translatewp_last_choice=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
        window.history.replaceState(null, '', '/');
        delete (window as { TranslateWP?: unknown }).TranslateWP;
    });

    it('is not busy before any choice', async () =>
    {
        await renderApp();
        expect(isBusy()).toBe(false);
        expect(trigger().hasAttribute('aria-busy')).toBe(false);
    });

    it('reads busy while the switch is in flight and settles when it resolves', async () =>
    {
        await renderApp();
        await pick('Español');
        expect(isBusy()).toBe(true);

        await act(async () =>
        {
            pending.at(-1)!.resolve({ translated: [], failures: [], totalGroups: 1 });
        });
        await settle();
        expect(isBusy()).toBe(false);
    });

    it('settles when the switch is rejected', async () =>
    {
        await renderApp();
        await pick('Español');
        expect(isBusy()).toBe(true);

        await act(async () =>
        {
            pending.at(-1)!.reject(new Error('provider down'));
        });
        await settle();
        expect(isBusy()).toBe(false);
    });

    it('stays busy across a superseding choice and settles with the choice that wins', async () =>
    {
        await renderApp();
        await pick('Español');
        expect(isBusy()).toBe(true);

        await pick('Italiano');
        expect(isBusy()).toBe(true);

        // The first switch settling must not clear the flag: the second one owns it.
        await act(async () =>
        {
            pending[0]!.resolve({ translated: [], failures: [], totalGroups: 1 });
        });
        await settle();
        expect(isBusy()).toBe(true);

        await act(async () =>
        {
            pending.at(-1)!.resolve({ translated: [], failures: [], totalGroups: 1 });
        });
        await settle();
        expect(isBusy()).toBe(false);
    });

    it('settles when the visitor returns to the default language', async () =>
    {
        await renderApp();
        await pick('Español');
        expect(isBusy()).toBe(true);

        await pick('English');
        await settle();
        expect(isBusy()).toBe(false);
    });
});
