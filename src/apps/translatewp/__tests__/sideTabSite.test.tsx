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

// The manager's switcher is a lazy chunk; this stands in for it and shows what it was given.
jest.mock('../AdminFloatingSwitcher', () => ({
    __esModule: true,
    default: (props: { switcher: { type?: string } }) => <div data-admin-floating={props.switcher.type ?? ''} />,
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const goldenSettings = (golden.vectors as Array<{ id: string; settings: unknown }>)
    .find((vector) => vector.id === 'basic-proxy-fixture')!.settings;

const installContext = (switcher: Record<string, unknown>, canManage = true) =>
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
                    switcher,
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
    translateBatch.mockRestore();
});

it('a manager on the side tab gets the draggable switcher, given the side tab', async () =>
{
    installContext({ type: 'side_tab' });
    const container = await renderApp();
    expect(container.querySelector('[data-admin-floating]')?.getAttribute('data-admin-floating')).toBe('side_tab');
});

it('a visitor on the side tab gets a tab on the bottom edge, its default place', async () =>
{
    installContext({ type: 'side_tab' }, false);
    const container = await renderApp();
    const tab = container.querySelector('.twp-container');
    expect(tab?.classList.contains('twp-side-tab')).toBe(true);
    expect(tab?.getAttribute('data-edge')).toBe('bottom');
    expect(tab?.getAttribute('data-mobile-edge')).toBe('bottom');
    expect(tab?.hasAttribute('data-anchor')).toBe(false);
});

it('a switcher placed in the page stays a dropdown on a side tab site', async () =>
{
    installContext({ type: 'side_tab' }, false);
    const host = document.createElement('div');
    host.setAttribute('data-twp-switcher', '');
    document.body.appendChild(host);
    await renderApp();
    const placed = host.querySelector('.twp-inline');
    expect(placed).not.toBeNull();
    expect(placed?.classList.contains('twp-side-tab')).toBe(false);
    expect(placed?.querySelector('.twp-trigger')).not.toBeNull();
    expect(document.querySelector('.twp-side-tab')).toBeNull();
});
