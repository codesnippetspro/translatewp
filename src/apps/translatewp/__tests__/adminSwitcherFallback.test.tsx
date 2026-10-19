import { TextEncoder } from 'util';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { TranslateWP } from '@/apps/translatewp';
import { resetSwitcherWarning } from '@/apps/translatewp/switcherDiagnostic';
import { CacheFirstTranslator } from '@/shared/lib/translations/CacheFirstTranslator';
import golden from '../../../../tests/fixtures/translation-fingerprint-golden.json';

// The admin chunk fails to load, as it does when an optimiser rewrites chunk URLs.
jest.mock('../AdminFloatingSwitcher', () =>
{
    throw new Error('Loading chunk AdminFloatingSwitcher failed');
});

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const goldenSettings = (golden.vectors as Array<{ id: string; settings: unknown }>)
    .find((vector) => vector.id === 'basic-proxy-fixture')!.settings;

const installContext = (switcherType: string, canManage = true) =>
{
    Object.defineProperty(window, 'TranslateWP', {
        value: {
            canManage,
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
                languages: {
                    list: {
                        en: { locale: 'en', slug: 'en', active: true, default: true },
                        es: { locale: 'es', slug: 'es', active: true, default: false },
                    },
                    switcher: { type: switcherType },
                },
                auto_translate: false,
                extraction_options: { includeAlt: false, includeAttributes: false },
                ai_settings: goldenSettings,
            },
        },
        configurable: true,
        writable: true,
    });
};

const roots: Array<ReturnType<typeof createRoot>> = [];

const renderApp = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    roots.push(root);
    await act(async () => { root.render(<TranslateWP />); });
    // Let the rejected lazy import settle and the boundary re-render.
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
    return container;
};

let translateBatch: jest.SpyInstance;

beforeEach(() =>
{
    translateBatch = jest.spyOn(CacheFirstTranslator.prototype, 'translateBatch').mockResolvedValue({ translated: [], failures: [], totalGroups: 0 });
    resetSwitcherWarning();
});

afterEach(() =>
{
    for (const root of roots.splice(0)) act(() => root.unmount());
    document.body.innerHTML = '';
    jest.useRealTimers();
    // jest-console owns the console spies; only this file's spy is restored.
    translateBatch.mockRestore();
});

// jest-console already spies on console.warn; each expected warning is asserted.
const switcherWarnings = () => (console.warn as unknown as jest.Mock).mock.calls.filter(call => String(call[0]).includes('TranslateWP: the language switcher'));

it('a failed admin chunk falls back to the plain switcher', async () =>
{
    installContext('dropdown');
    const container = await renderApp();
    expect(container.querySelector('.twp-trigger')).not.toBeNull();
    expect(switcherWarnings()).toHaveLength(1);
    expect(String(switcherWarnings()[0][0])).toContain('missing chunk');
    expect(console).toHaveWarned();
    // React reports the caught chunk error in development.
    expect(console).toHaveErrored();
});

it('a manager on native select renders the select with an admin menu button', async () =>
{
    installContext('native_select');
    const container = await renderApp();
    const select = container.querySelector('select.twp-native-select');
    expect(select).not.toBeNull();
    expect(select?.nextElementSibling?.hasAttribute('data-twp-admin-menu')).toBe(true);
});

it('warns a manager once when a placeholder host is hidden and nothing is visible', async () =>
{
    jest.useFakeTimers();
    const host = document.createElement('div');
    host.style.display = 'none';
    const placeholder = document.createElement('span');
    placeholder.setAttribute('data-twp-switcher', '');
    host.appendChild(placeholder);
    document.body.appendChild(host);
    installContext('native_select');
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    roots.push(root);
    await act(async () => { root.render(<TranslateWP />); });
    await act(async () => { jest.advanceTimersByTime(5000); });
    expect(switcherWarnings()).toHaveLength(1);
    expect(String(switcherWarnings()[0][0])).toContain('missing host');
    expect(console).toHaveWarned();
});

it('warns a manager naming the type when page CSS hides the native select', async () =>
{
    jest.useFakeTimers();
    const style = document.createElement('style');
    style.textContent = 'body > div { opacity: 0; }';
    document.head.appendChild(style);
    installContext('native_select');
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    roots.push(root);
    await act(async () => { root.render(<TranslateWP />); });
    await act(async () => { jest.advanceTimersByTime(5000); });
    style.remove();
    expect(switcherWarnings()).toHaveLength(1);
    expect(String(switcherWarnings()[0][0])).toContain('reason: type, type native_select');
    expect(console).toHaveWarned();
});

it('a visitor never gets the warning', async () =>
{
    jest.useFakeTimers();
    const style = document.createElement('style');
    style.textContent = 'body > div { opacity: 0; }';
    document.head.appendChild(style);
    installContext('native_select', false);
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    roots.push(root);
    await act(async () => { root.render(<TranslateWP />); });
    await act(async () => { jest.advanceTimersByTime(5000); });
    style.remove();
    expect(switcherWarnings()).toHaveLength(0);
});
