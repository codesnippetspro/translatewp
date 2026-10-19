import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { addFilter, removeFilter } from '@wordpress/hooks';

import { SeoTools } from '@/apps/admin/components/SeoTools';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { getConnectStatus } from '@/shared/api/connect';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@/shared/api/connect', () => ({ getConnectStatus: jest.fn() }));

const mockStatus = jest.mocked(getConnectStatus);
let root: Root;
let container: HTMLDivElement;
let client: QueryClient;
const panel = () => <div>SEO controls</div>;

const PRO_FEATURES = [
    'Translated sitemaps',
    'Translated titles and meta descriptions',
    'Open Graph tags',
    'Structured data',
    'IndexNow',
    'Translated RSS feeds and language headers',
    'AI crawler controls',
    'llms.txt',
    'AI alt text',
];

const setFree = () =>
{
    Object.defineProperty(window, 'translatewpPro', {
        value: { connected: true, entitled: false, installed: false, active: false },
        writable: true,
        configurable: true,
    });
};

const toggle = (label: string): HTMLInputElement | null =>
{
    const labelElement = Array.from(container.querySelectorAll('label'))
        .find(element => element.textContent?.includes(label));
    const id = labelElement?.getAttribute('for');
    return id ? document.getElementById(id) as HTMLInputElement | null : null;
};

const seoSettings = () => useAdminSettingsStore.getState().settings['languages.seo'];

const render = async () =>
{
    await act(async () =>
    {
        root.render(<QueryClientProvider client={client}><SeoTools /></QueryClientProvider>);
    });
};

beforeEach(() =>
{
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    Object.defineProperty(window, 'translatewpPro', {
        value: { connected: true, entitled: true, installed: true, active: true },
        writable: true,
        configurable: true,
    });
    mockStatus.mockImplementation(() => new Promise(() => {}));
    useAdminSettingsStore.getState().setSetting('languages.seo', previous => ({
        ...previous,
        output_hreflang: true,
        include_x_default: true,
    }));
});

afterEach(async () =>
{
    await act(async () => root.unmount());
    client.clear();
    removeFilter('translatewp.slot.seo', 'test');
    delete (window as unknown as { translatewpPro?: unknown }).translatewpPro;
    document.body.innerHTML = '';
    jest.clearAllMocks();
});

it('renders the registered panel while the status refresh is pending', async () =>
{
    addFilter('translatewp.slot.seo', 'test', () => panel);

    await render();

    expect(container.textContent).toContain('SEO controls');
    expect(document.body.textContent).not.toContain('Upgrade now');
});

it('does not offer another purchase while an active addon registers its panel', async () =>
{
    await render();

    expect(document.body.textContent).not.toContain('Upgrade now');
    await act(async () => { addFilter('translatewp.slot.seo', 'test', () => panel); });
    expect(container.textContent).toContain('SEO controls');
});

it('honors a refreshed denial over the initial entitled state', async () =>
{
    addFilter('translatewp.slot.seo', 'test', () => panel);
    client.setQueryData(['connectStatus'], {
        connected: true,
        pro: { entitled: false, installed: true, active: true },
    });

    await render();

    expect(container.textContent).not.toContain('SEO controls');
    expect(document.body.textContent).toContain('Upgrade now');
});

it('shows the basic hreflang controls live on Free', async () =>
{
    setFree();

    await render();

    const hreflang = toggle('Output hreflang tags');
    const xDefault = toggle('Include x-default');
    expect(hreflang?.checked).toBe(true);
    expect(xDefault?.checked).toBe(true);
    expect(hreflang?.closest('.pointer-events-none')).toBeNull();

    await act(async () => { xDefault?.click(); });
    expect(seoSettings().include_x_default).toBe(false);

    await act(async () => { hreflang?.click(); });
    expect(seoSettings().output_hreflang).toBe(false);
    expect(toggle('Include x-default')).toBeNull();
});

it('shows each Pro SEO option on Free as a greyed-out option row that cannot be used, with the upgrade prompt', async () =>
{
    setFree();

    await render();

    const rows = Array.from(container.querySelectorAll('[data-twp-pro-seo-option]'));
    expect(rows.map(row => row.querySelector('p')?.textContent)).toEqual(PRO_FEATURES);
    for (const row of rows)
    {
        const control = row.querySelector('input[type="checkbox"]') as HTMLInputElement;
        expect(control.disabled).toBe(true);
        expect(control.checked).toBe(false);
    }
    expect(container.querySelector('#twp-seo-pro')?.closest('section')?.querySelector('ul')).toBeNull();
    expect(container.querySelector('.pointer-events-none [data-twp-pro-seo-option]')).not.toBeNull();
    expect(document.body.textContent).toContain('Upgrade now');
    expect(document.body.textContent).not.toContain('\u2014');
});

it('leaves the page to the Pro panel when it is registered', async () =>
{
    addFilter('translatewp.slot.seo', 'test', () => panel);

    await render();

    expect(toggle('Output hreflang tags')).toBeNull();
    expect(container.textContent).not.toContain('Translated sitemaps');
});

it('keeps the basic controls live for an addon that predates the SEO panel', async () =>
{
    await render();

    expect(toggle('Output hreflang tags')).not.toBeNull();
    expect(container.textContent).toContain('Update it from your Plugins screen');
    expect(document.body.textContent).not.toContain('Upgrade now');
});
