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

const installAdminBar = () =>
{
    const bar = document.createElement('div');
    bar.id = 'wpadminbar';
    document.body.appendChild(bar);
};

describe('TranslateWP switcher surfaces', () =>
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
        document.cookie = 'translatewp_last_choice=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
        window.history.replaceState(null, '', '/');
        delete (window as { TranslateWP?: unknown }).TranslateWP;
    });

    it('mounts an inline switcher into every data-twp-switcher placeholder', async () =>
    {
        installContext(true);
        document.body.innerHTML = `
            <nav><span data-twp-switcher class="translatewp-no-translate"></span></nav>
            <aside><span data-twp-switcher class="translatewp-no-translate"></span></aside>
        `;

        await renderApp();

        const placeholders = document.querySelectorAll('[data-twp-switcher]');
        expect(placeholders).toHaveLength(2);
        expect(placeholders[0].querySelector('.twp-inline')).not.toBeNull();
        expect(placeholders[1].querySelector('.twp-inline')).not.toBeNull();
        expect(document.querySelector('.twp-container')).toBeNull();
    });

    it.each([
        ['no addon value', undefined],
        ['an addon answering false', false],
        ['an addon answering true', true],
    ])('mounts one switcher into a saved button toggle with %s', async (_label, addonValue) =>
    {
        installContext(true);
        if (addonValue !== undefined)
        {
            (window.TranslateWP.context as { inline_placements?: boolean }).inline_placements = addonValue;
        }
        document.body.innerHTML = '<div class="wp-block-button" data-twp-switcher="true"><a class="wp-block-button__link">Language</a></div>';

        await renderApp();

        expect(document.querySelectorAll('.wp-block-button .twp-inline')).toHaveLength(1);
        expect(document.querySelector('.twp-container')).toBeNull();
    });

    it('moves the switcher to a placement the editor just saved, and back to floating, without a reload', async () =>
    {
        livePlacement.reset();
        installContext(true);
        document.body.innerHTML = '<header><p class="site-title">Market Street Bakery</p></header><main><p>Hello</p></main>';
        await renderApp();
        expect(document.querySelector('.twp-container')).not.toBeNull();
        expect(document.querySelector('.twp-placed')).toBeNull();

        await act(async () => { frontendHost.placement.apply({ kind: 'anchor', selector: 'body > header > p', position: 'after' }); });
        const placed = document.querySelector('.twp-placed');
        expect(placed?.previousElementSibling?.className).toBe('site-title');
        expect(placed?.querySelector('.twp-inline')).not.toBeNull();
        expect(document.querySelector('.twp-container')).toBeNull();

        await act(async () => { frontendHost.placement.apply(null); });
        expect(document.querySelector('.twp-placed')).toBeNull();
        expect(document.querySelector('.twp-container')).not.toBeNull();
        livePlacement.reset();
    });

    it('renders inline switchers without a floating duplicate', async () =>
    {
        installContext(true);
        document.body.innerHTML = '<span data-twp-switcher></span>';
        jest.spyOn(Translator.prototype, 'translateBatch').mockResolvedValueOnce({
            translated: [],
            failures: [{ groupId: 'group-1', error: 'translation_pending' }],
            totalGroups: 1,
        });

        await renderApp();

        const inlineTrigger = document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement;
        expect(inlineTrigger).not.toBeNull();

        await act(async () =>
        {
            inlineTrigger.click();
        });

        const inlineOptions = document.querySelectorAll('.twp-inline .twp-dropdown-item');
        expect(inlineOptions.length).toBe(2);
        expect(document.querySelector('.twp-container')).toBeNull();

        const esOption = Array.from(inlineOptions)
            .find((option) => option.textContent?.includes('Español')) as HTMLButtonElement;
        expect(esOption).toBeDefined();

        act(() =>
        {
            esOption.click();
        });

        expect(document.cookie).toContain('translatewp_last_choice=es');

        await act(async () => {});

        await act(async () =>
        {
            inlineTrigger.click();
        });

        const selectedInline = document.querySelector('.twp-inline .twp-dropdown-item[data-selected="true"]');
        expect(selectedInline?.textContent).toContain('Español');
    });

    it('keeps clean URLs by default and updates the document language on selection and reset', async () =>
    {
        installContext(true);
        document.body.innerHTML = '<span data-twp-switcher></span>';
        const pushState = jest.spyOn(window.history, 'pushState');
        jest.spyOn(Translator.prototype, 'translateBatch').mockResolvedValue({
            translated: [],
            failures: [{ groupId: 'group-1', error: 'translation_pending' }],
            totalGroups: 1,
        });

        await renderApp();

        const trigger = document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement;
        await act(async () =>
        {
            trigger.click();
        });
        const spanish = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
            .find((option) => option.textContent?.includes('Español')) as HTMLButtonElement;
        act(() =>
        {
            spanish.click();
        });

        expect(window.location.search).toBe('');
        expect(document.documentElement.lang).toBe('es');
        expect(pushState).not.toHaveBeenCalled();

        await act(async () =>
        {
            trigger.click();
        });
        const english = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
            .find((option) => option.textContent?.includes('English')) as HTMLButtonElement;
        act(() =>
        {
            english.click();
        });

        expect(window.location.search).toBe('');
        expect(document.documentElement.lang).toBe('en');
        expect(pushState).not.toHaveBeenCalled();
    });

    it('follows the address on Back and Forward without adding a history entry', async () =>
    {
        installContext(true);
        document.body.innerHTML = '<span data-twp-switcher></span>';
        jest.spyOn(Translator.prototype, 'translateBatch').mockResolvedValue({ translated: [], failures: [], totalGroups: 0 });
        const pushState = jest.spyOn(window.history, 'pushState');
        await renderApp();

        // A visitor switched to Spanish, which pushed /es/; Back moves the
        // address to the English page.
        const trigger = document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement;
        await act(async () => { trigger.click(); });
        const spanish = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
            .find((option) => option.textContent?.includes('Español')) as HTMLButtonElement;
        await act(async () => { spanish.click(); });
        expect(document.documentElement.lang).toBe('es');
        pushState.mockClear();

        window.history.replaceState(null, '', '/');
        await act(async () => { window.dispatchEvent(new PopStateEvent('popstate')); });
        expect(document.documentElement.lang).toBe('en');
        expect(pushState).not.toHaveBeenCalled();

        window.history.replaceState(null, '', '/es/');
        await act(async () => { window.dispatchEvent(new PopStateEvent('popstate')); });
        expect(document.documentElement.lang).toBe('es');
        expect(pushState).not.toHaveBeenCalled();
    });

    it('keeps clean URLs for cookie and browser automatic language selection', async () =>
    {
        const pushState = jest.spyOn(window.history, 'pushState');
        const translateBatch = jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockResolvedValue({
            translated: [],
            failures: [],
            totalGroups: 0,
        });
        document.cookie = 'translatewp_last_choice=es; path=/';
        installContext(true);

        await renderApp();
        await act(async () => {});

        expect(translateBatch).toHaveBeenCalled();
        expect(document.documentElement.lang).toBe('es');
        expect(pushState).not.toHaveBeenCalled();
    });

    it('keeps clean URLs for browser automatic language selection', async () =>
    {
        const pushState = jest.spyOn(window.history, 'pushState');
        const translateBatch = jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockResolvedValue({
            translated: [],
            failures: [],
            totalGroups: 0,
        });
        const originalLanguages = navigator.languages;
        Object.defineProperty(navigator, 'languages', { value: ['es-ES'], configurable: true });
        installContext(true, 'dropdown', {}, true);

        try
        {
            await renderApp();
            await act(async () => {});

            expect(translateBatch).toHaveBeenCalled();
            expect(document.documentElement.lang).toBe('es');
            expect(pushState).not.toHaveBeenCalled();
        }
        finally
        {
            Object.defineProperty(navigator, 'languages', { value: originalLanguages, configurable: true });
        }
    });

    const pendingBatch = () => jest.spyOn(Translator.prototype, 'translateBatch').mockResolvedValue({
        translated: [],
        failures: [{ groupId: 'group-1', error: 'translation_pending' }],
        totalGroups: 1,
    });

    const chooseLanguage = async (name: string) =>
    {
        const trigger = document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement;
        await act(async () => { trigger.click(); });
        const option = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
            .find((item) => item.textContent?.includes(name)) as HTMLButtonElement;
        act(() => { option.click(); });
    };

    it('moves the address into the language folder when the visitor switches, as a new history entry', async () =>
    {
        installContext(true, 'dropdown');
        window.TranslateWP.context.home_url = window.location.origin;
        document.body.innerHTML = '<span data-twp-switcher></span>';
        window.history.replaceState(null, '', '/par-mums/?ref=9');
        pendingBatch();
        const pushSpy = jest.spyOn(window.history, 'pushState');

        try
        {
            await renderApp();
            await chooseLanguage('Español');

            expect(window.location.pathname).toBe('/es/par-mums/');
            expect(window.location.search).toBe('?ref=9');
            expect(document.documentElement.lang).toBe('es');
            expect(pushSpy).toHaveBeenCalledTimes(1);
        }
        finally
        {
            pushSpy.mockRestore();
        }
    });

    it('translates an address in a language folder into that language and leaves the address alone', async () =>
    {
        installContext(true, 'dropdown');
        window.TranslateWP.context.home_url = window.location.origin;
        (window.TranslateWP.context as { requested_locale?: string }).requested_locale = 'es';
        document.body.innerHTML = '<span data-twp-switcher></span>';
        window.history.replaceState(null, '', '/es/par-mums/');
        const replaceSpy = jest.spyOn(window.history, 'replaceState');
        const pushSpy = jest.spyOn(window.history, 'pushState');

        try
        {
            await renderApp();

            expect(document.documentElement.lang).toBe('es');
            expect(window.location.pathname).toBe('/es/par-mums/');
            expect(pushSpy).not.toHaveBeenCalled();
            expect(replaceSpy).not.toHaveBeenCalled();
        }
        finally
        {
            replaceSpy.mockRestore();
            pushSpy.mockRestore();
        }
    });

    it('returns to the plain address when the visitor switches back to the default language', async () =>
    {
        installContext(true, 'dropdown');
        window.TranslateWP.context.home_url = window.location.origin;
        (window.TranslateWP.context as { requested_locale?: string }).requested_locale = 'es';
        document.body.innerHTML = '<span data-twp-switcher></span>';
        window.history.replaceState(null, '', '/es/par-mums/');

        await renderApp();
        await chooseLanguage('English');

        expect(window.location.pathname).toBe('/par-mums/');
        expect(document.documentElement.lang).toBe('en');
    });

    it('moves a plain address into the remembered language folder without a new history entry', async () =>
    {
        installContext(true, 'dropdown', { remember_last_choice: true });
        window.TranslateWP.context.home_url = window.location.origin;
        document.cookie = 'translatewp_last_choice=es; path=/';
        document.body.innerHTML = '<span data-twp-switcher></span>';
        window.history.replaceState(null, '', '/par-mums/');
        const pushSpy = jest.spyOn(window.history, 'pushState');

        try
        {
            await renderApp();

            expect(document.documentElement.lang).toBe('es');
            expect(window.location.pathname).toBe('/es/par-mums/');
            expect(pushSpy).not.toHaveBeenCalled();
        }
        finally
        {
            pushSpy.mockRestore();
        }
    });

    it('still honours an old lang link and moves it into the folder form', async () =>
    {
        installContext(true, 'dropdown');
        window.TranslateWP.context.home_url = window.location.origin;
        document.body.innerHTML = '<span data-twp-switcher></span>';
        window.history.replaceState(null, '', '/par-mums/?lang=es&ref=9');

        await renderApp();

        expect(document.documentElement.lang).toBe('es');
        expect(window.location.pathname).toBe('/es/par-mums/');
        expect(window.location.search).toBe('?ref=9');
    });

    it('keeps the pending notice through the first translation and dismisses it on the last', async () =>
    {
        installContext(true);
        document.body.innerHTML = '<main><p>First group</p><p>Last group</p></main><span data-twp-switcher></span>';
        installAdminBar();
        const resolveTranslations: Array<(result: { translated: []; failures: []; totalGroups: number }) => void> = [];
        let applyFirstGroup: () => void = () => {};
        const timers: Array<{ callback: () => void; delay: number }> = [];
        const setTimeout = jest.spyOn(window, 'setTimeout').mockImplementation(((callback: TimerHandler, delay?: number) =>
        {
            timers.push({ callback: callback as () => void, delay: delay ?? 0 });
            return 1;
        }) as typeof window.setTimeout);
        jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockImplementation((entries, options) => new Promise(resolve =>
        {
            resolveTranslations.push(resolve);
            applyFirstGroup = () => options.onEntryTranslated?.(entries[0]);
        }));

        try
        {
            await renderApp();

            const trigger = document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement;
            await act(async () =>
            {
                trigger.click();
            });
            const spanish = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
                .find((option) => option.textContent?.includes('Español')) as HTMLButtonElement;
            act(() =>
            {
                spanish.click();
            });
            await act(async () =>
            {
                await Promise.resolve();
                await Promise.resolve();
            });
            expect(resolveTranslations).not.toHaveLength(0);
            act(() =>
            {
                timers.filter((timer) => timer.delay === 8000).at(-1)!.callback();
            });

            expect(CacheFirstTranslator.prototype.translateBatch).toHaveBeenCalled();
            expect(document.querySelector('[role="status"]')?.textContent).toContain('Translating this page for you');

            act(() =>
            {
                applyFirstGroup();
            });
            expect(document.querySelector('[role="status"]')?.textContent).toContain('Translating this page for you');

            await act(async () =>
            {
                resolveTranslations.forEach(resolve => resolve({ translated: [], failures: [], totalGroups: 0 }));
                await Promise.resolve();
                await Promise.resolve();
            });

            expect(document.querySelector('[role="status"]')).toBeNull();
        }
        finally
        {
            setTimeout.mockRestore();
        }
    });

    it('dismisses the pending notice when the visitor resets to the default language', async () =>
    {
        installContext(true);
        document.body.innerHTML = '<span data-twp-switcher></span>';
        installAdminBar();
        const timers: Array<{ callback: () => void; delay: number }> = [];
        const setTimeout = jest.spyOn(window, 'setTimeout').mockImplementation(((callback: TimerHandler, delay?: number) =>
        {
            timers.push({ callback: callback as () => void, delay: delay ?? 0 });
            return 1;
        }) as typeof window.setTimeout);
        jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockImplementation((_entries, options) => new Promise((_resolve, reject) =>
        {
            options.signal?.addEventListener('abort', () => reject(new DOMException('Translation aborted.', 'AbortError')));
        }));

        try
        {
            await renderApp();

            const trigger = document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement;
            await act(async () =>
            {
                trigger.click();
            });
            const spanish = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
                .find((option) => option.textContent?.includes('Español')) as HTMLButtonElement;
            act(() =>
            {
                spanish.click();
            });
            act(() =>
            {
                timers.filter((timer) => timer.delay === 8000).at(-1)!.callback();
            });
            expect(document.querySelector('[role="status"]')).not.toBeNull();

            await act(async () =>
            {
                trigger.click();
            });
            const english = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
                .find((option) => option.textContent?.includes('English')) as HTMLButtonElement;
            act(() =>
            {
                english.click();
            });
            await act(async () =>
            {
                await Promise.resolve();
                await Promise.resolve();
            });

            expect(document.querySelector('[role="status"]')).toBeNull();
        }
        finally
        {
            setTimeout.mockRestore();
        }
    });

    it('clears the pending notice timer when navigation aborts the translation', async () =>
    {
        installContext(true);
        document.body.innerHTML = '<span data-twp-switcher></span>';
        let signal: AbortSignal | undefined;
        const timers: Array<{ callback: () => void; delay: number }> = [];
        const setTimeout = jest.spyOn(window, 'setTimeout').mockImplementation(((callback: TimerHandler, delay?: number) =>
        {
            timers.push({ callback: callback as () => void, delay: delay ?? 0 });
            return 1;
        }) as typeof window.setTimeout);
        const clearTimeout = jest.spyOn(window, 'clearTimeout');
        jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockImplementation((_entries, options) => new Promise((_resolve, reject) =>
        {
            signal = options.signal;
            signal?.addEventListener('abort', () => reject(new DOMException('Translation aborted.', 'AbortError')));
        }));

        try
        {
            await renderApp();

            const trigger = document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement;
            await act(async () =>
            {
                trigger.click();
            });
            const spanish = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
                .find((option) => option.textContent?.includes('Español')) as HTMLButtonElement;
            act(() =>
            {
                spanish.click();
            });
            await act(async () =>
            {
                await Promise.resolve();
                await Promise.resolve();
            });

            const root = renderedRoots.pop()!;
            act(() =>
            {
                root.unmount();
            });
            await act(async () =>
            {
                await Promise.resolve();
                await Promise.resolve();
            });

            expect(signal?.aborted).toBe(true);
            expect(clearTimeout).toHaveBeenCalled();
        }
        finally
        {
            clearTimeout.mockRestore();
            setTimeout.mockRestore();
        }
    });

    it('dismisses the pending notice when the active translation aborts', async () =>
    {
        installContext(true);
        document.body.innerHTML = '<span data-twp-switcher></span>';
        installAdminBar();
        let rejectTranslation: (error: Error) => void = () => {};
        const timers: Array<{ callback: () => void; delay: number }> = [];
        const setTimeout = jest.spyOn(window, 'setTimeout').mockImplementation(((callback: TimerHandler, delay?: number) =>
        {
            timers.push({ callback: callback as () => void, delay: delay ?? 0 });
            return 1;
        }) as typeof window.setTimeout);
        jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockImplementation(() => new Promise((_resolve, reject) =>
        {
            rejectTranslation = reject;
        }));

        try
        {
            await renderApp();

            const trigger = document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement;
            await act(async () =>
            {
                trigger.click();
            });
            const spanish = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
                .find((option) => option.textContent?.includes('Español')) as HTMLButtonElement;
            act(() =>
            {
                spanish.click();
            });
            await act(async () =>
            {
                await Promise.resolve();
                await Promise.resolve();
            });
            act(() =>
            {
                timers.filter((timer) => timer.delay === 8000).at(-1)!.callback();
            });
            expect(document.querySelector('[role="status"]')).not.toBeNull();

            await act(async () =>
            {
                rejectTranslation(new DOMException('Translation aborted.', 'AbortError'));
                await Promise.resolve();
                await Promise.resolve();
            });

            expect(document.querySelector('[role="status"]')).toBeNull();
        }
        finally
        {
            setTimeout.mockRestore();
        }
    });

    it('renders the floating switcher when the page has no inline surface', async () =>
    {
        installContext(true);

        await renderApp();

        expect(document.querySelector('.twp-container')).not.toBeNull();
    });

    it('renders nothing into placeholders when disconnected', async () =>
    {
        installContext(false);
        document.body.innerHTML = '<span data-twp-switcher></span>';

        const container = await renderApp();

        expect(container.innerHTML).toBe('');
        expect(document.querySelector('[data-twp-switcher] .twp-inline')).toBeNull();
    });

    it('shows no error notice or retry while a translation is still polling', async () =>
    {
        installContext(true, 'dropdown', {}, false, false);
        document.body.innerHTML = '<span data-twp-switcher></span>';
        jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockImplementation(() => new Promise(() => {}));

        await renderApp();

        const trigger = document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement;
        await act(async () =>
        {
            trigger.click();
        });
        const spanish = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
            .find((option) => option.textContent?.includes('Español')) as HTMLButtonElement;
        act(() =>
        {
            spanish.click();
        });
        await act(async () =>
        {
            await Promise.resolve();
            await Promise.resolve();
        });

        expect(document.querySelector('[role="status"]')).toBeNull();
        expect(document.querySelector('.twp-toast-retry')).toBeNull();
    });

    it('shows the site\'s own translating message when one is set', async () =>
    {
        installContext(true, 'dropdown', {}, false, false, { pending_message: 'Un momento, traduciendo…' });
        document.body.innerHTML = '<main><p>First group</p></main><span data-twp-switcher></span>';
        const timers: Array<{ callback: () => void; delay: number }> = [];
        const setTimeout = jest.spyOn(window, 'setTimeout').mockImplementation(((callback: TimerHandler, delay?: number) =>
        {
            timers.push({ callback: callback as () => void, delay: delay ?? 0 });
            return 1;
        }) as typeof window.setTimeout);
        jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockImplementation(() => new Promise(() => {}));

        try
        {
            await renderApp();

            const trigger = document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement;
            await act(async () =>
            {
                trigger.click();
            });
            const spanish = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
                .find((option) => option.textContent?.includes('Español')) as HTMLButtonElement;
            act(() =>
            {
                spanish.click();
            });
            await act(async () =>
            {
                await Promise.resolve();
                await Promise.resolve();
            });
            act(() =>
            {
                timers.filter((timer) => timer.delay === 8000).at(-1)!.callback();
            });

            expect(document.querySelector('[role="status"]')?.textContent).toContain('Un momento, traduciendo…');
            expect(document.querySelector('[role="status"]')?.textContent).not.toContain('Translating this page for you');
        }
        finally
        {
            setTimeout.mockRestore();
        }
    });

    it('shows the pending line to a visitor after the initial window while groups are unserved', async () =>
    {
        installContext(true, 'dropdown', {}, false, false);
        document.body.innerHTML = '<main><p>First group</p></main><span data-twp-switcher></span>';
        const timers: Array<{ callback: () => void; delay: number }> = [];
        const setTimeout = jest.spyOn(window, 'setTimeout').mockImplementation(((callback: TimerHandler, delay?: number) =>
        {
            timers.push({ callback: callback as () => void, delay: delay ?? 0 });
            return 1;
        }) as typeof window.setTimeout);
        jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockImplementation(() => new Promise(() => {}));

        try
        {
            await renderApp();

            const trigger = document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement;
            await act(async () =>
            {
                trigger.click();
            });
            const spanish = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
                .find((option) => option.textContent?.includes('Español')) as HTMLButtonElement;
            act(() =>
            {
                spanish.click();
            });
            await act(async () =>
            {
                await Promise.resolve();
                await Promise.resolve();
            });
            act(() =>
            {
                timers.filter((timer) => timer.delay === 8000).at(-1)!.callback();
            });

            expect(document.querySelector('[role="status"]')?.textContent).toContain('Translating this page for you');
        }
        finally
        {
            setTimeout.mockRestore();
        }
    });

    it('shows the loading notice to a manager while a switch is pending', async () =>
    {
        installContext(true);
        document.body.innerHTML = '<main><p>First group</p></main><span data-twp-switcher></span>';
        installAdminBar();
        const timers: Array<{ callback: () => void; delay: number }> = [];
        const setTimeout = jest.spyOn(window, 'setTimeout').mockImplementation(((callback: TimerHandler, delay?: number) =>
        {
            timers.push({ callback: callback as () => void, delay: delay ?? 0 });
            return 1;
        }) as typeof window.setTimeout);
        jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockImplementation(() => new Promise(() => {}));

        try
        {
            await renderApp();

            const trigger = document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement;
            await act(async () =>
            {
                trigger.click();
            });
            const spanish = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
                .find((option) => option.textContent?.includes('Español')) as HTMLButtonElement;
            act(() =>
            {
                spanish.click();
            });
            await act(async () =>
            {
                await Promise.resolve();
                await Promise.resolve();
            });
            act(() =>
            {
                timers.filter((timer) => timer.delay === 8000).at(-1)!.callback();
            });

            expect(document.querySelector('[role="status"]')?.textContent).toContain('Translating this page for you');
        }
        finally
        {
            setTimeout.mockRestore();
        }
    });

    it('keeps the pending line through an unrelated re-hydration while the switch is still polling', async () =>
    {
        window.history.replaceState(null, '', '/?lang=es');
        installContext(true, 'dropdown', {}, false, false);
        document.body.innerHTML = '<main><p>First group</p></main><span data-twp-switcher></span>';
        const timers: Array<{ callback: () => void; delay: number }> = [];
        const setTimeout = jest.spyOn(window, 'setTimeout').mockImplementation(((callback: TimerHandler, delay?: number) =>
        {
            timers.push({ callback: callback as () => void, delay: delay ?? 0 });
            return 1;
        }) as typeof window.setTimeout);

        type PendingBatch = { values: string[]; resolve: () => void };
        const batches: PendingBatch[] = [];
        jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockImplementation((entries, options = {}) => new Promise(resolve =>
        {
            batches.push({
                values: entries.map((entry) => entry.value),
                resolve: () =>
                {
                    if (options.signal?.aborted)
                    {
                        resolve({ translated: [], failures: [], totalGroups: 0 });
                        return;
                    }
                    const translated = entries.map((entry) => entry.withTranslation(`es:${entry.value}`));
                    for (const entry of translated)
                    {
                        options.onEntryTranslated?.(entry);
                    }
                    resolve({ translated, failures: [], totalGroups: translated.length > 0 ? 1 : 0 });
                },
            });
        }));

        try
        {
            await renderApp();
            await act(async () =>
            {
                await Promise.resolve();
                await Promise.resolve();
            });

            // The entry URL carried ?lang=es, so the switch auto-started on load
            // (not a click) and its group is still unserved.
            const switchBatch = batches.find((batch) => batch.values.includes('First group'));
            expect(switchBatch).toBeDefined();

            // The initial window (8000ms) passes with the switch still polling,
            // so the line opens.
            act(() =>
            {
                timers.filter((timer) => timer.delay === 8000).at(-1)!.callback();
            });
            expect(document.querySelector('[role="status"]')?.textContent).toContain('Translating this page for you');

            // The theme re-renders unrelated content; its re-hydration flush runs
            // on its own chain and serves cleanly while the switch poll is open.
            await act(async () =>
            {
                document.querySelector('main')!.insertAdjacentHTML('beforeend', '<p>Unrelated block</p>');
                await Promise.resolve();
                await Promise.resolve();
                await Promise.resolve();
                timers.filter((timer) => timer.delay === CHANGE_SETTLE_MS).at(-1)!.callback();
                await Promise.resolve();
                await Promise.resolve();
                await Promise.resolve();
            });
            const rehydration = batches.find((batch) => batch.values.includes('Unrelated block'));
            expect(rehydration).toBeDefined();
            await act(async () =>
            {
                rehydration!.resolve();
                await Promise.resolve();
                await Promise.resolve();
            });

            // The flush finished with no failures, but the switch still has a
            // group unserved: the line is bound to the page, not to one flush,
            // so it stays.
            expect(document.querySelector('[role="status"]')?.textContent).toContain('Translating this page for you');

            // The switch serves its last group; now nothing on the page is
            // pending and the line clears within a tick.
            await act(async () =>
            {
                switchBatch!.resolve();
                await Promise.resolve();
                await Promise.resolve();
            });
            expect(document.querySelector('[role="status"]')).toBeNull();
        }
        finally
        {
            setTimeout.mockRestore();
        }
    });

    it('never shows the pending line when the page finishes translating within the window', async () =>
    {
        installContext(true, 'dropdown', {}, false, false);
        document.body.innerHTML = '<main><p>First group</p></main><span data-twp-switcher></span>';
        const timers: Array<{ callback: () => void; delay: number }> = [];
        const setTimeout = jest.spyOn(window, 'setTimeout').mockImplementation(((callback: TimerHandler, delay?: number) =>
        {
            timers.push({ callback: callback as () => void, delay: delay ?? 0 });
            return 1;
        }) as typeof window.setTimeout);
        jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockResolvedValue({
            translated: [],
            failures: [],
            totalGroups: 0,
        });

        try
        {
            await renderApp();

            const trigger = document.querySelector('.twp-inline .twp-trigger') as HTMLButtonElement;
            await act(async () =>
            {
                trigger.click();
            });
            const spanish = Array.from(document.querySelectorAll('.twp-inline .twp-dropdown-item'))
                .find((option) => option.textContent?.includes('Español')) as HTMLButtonElement;
            act(() =>
            {
                spanish.click();
            });
            await act(async () =>
            {
                await Promise.resolve();
                await Promise.resolve();
            });

            // The switch resolved before the initial window elapsed, so the
            // timer was cancelled and the pending line never appears.
            expect(document.querySelector('[role="status"]')).toBeNull();
            expect(document.body.textContent).not.toContain('Translating this page for you');
        }
        finally
        {
            setTimeout.mockRestore();
        }
    });
});


it('renders saved floating placement for a visitor without drag controls', async () => {
    installContext(true, 'dropdown', {}, false, false);
    window.TranslateWP.options!.languages.switcher.floating_position = {
        anchor: 'bottom-left', x: 30, y: 80,
    };
    await renderApp();
    const floating = document.querySelector('.twp-container') as HTMLElement;
    expect(floating.dataset.anchor).toBe('bottom-left');
    expect(floating.style.getPropertyValue('--twp-position-x')).toBe('30px');
    expect(floating.style.getPropertyValue('--twp-position-y')).toBe('80px');
    expect(document.querySelector('[data-drag-handle]')).toBeNull();
    for (const root of renderedRoots.splice(0)) act(() => root.unmount());
    document.body.innerHTML = '';
});
