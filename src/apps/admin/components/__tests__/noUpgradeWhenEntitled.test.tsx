import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { jest } from '@jest/globals';
import { addFilter, removeFilter } from '@wordpress/hooks';

// Mutable per test: the connect status the surfaces read.
let mockProStatus: Record<string, unknown> = { entitled: true, installed: true, active: true };

jest.mock('@tanstack/react-query', () => ({
    queryOptions: (options: unknown) => options,
    useQuery: () => ({ data: { connected: true, pro: mockProStatus }, isLoading: false, isFetching: false }),
    useMutation: () => ({ mutate: () => {}, isPending: false, isError: false, error: null, reset: () => {} }),
    useQueryClient: () => ({ invalidateQueries: () => {} }),
}));

jest.mock('@/shared/query-options/connect', () => ({
    getConnectStatusQueryOptions: () => ({ queryKey: ['connectStatus'] }),
}));

jest.mock('@/shared/components/Icon', () => ({ Icon: () => null }));

import { Header } from '@/apps/admin/components/Header';
import { LanguagesSwitcher } from '@/apps/admin/components/Languages/Switcher';
import { TranslationsContext } from '@/apps/admin/components/Translations/Context';
import { AiProviders } from '@/apps/admin/components/AiProviders';
import { SeoTools } from '@/apps/admin/components/SeoTools';
import { CustomOption } from '@/apps/admin/components/Translations/Style/CustomOption';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const FILTER_NAMESPACE = 'translatewp-tests/no-upgrade-when-entitled';
const SLOTS = ['aiProviders', 'seo', 'translationStyle', 'translationTone'];
const RegisteredPanel = () => <div>Registered Pro panel</div>;

const setProState = (value: unknown) =>
{
    Object.defineProperty(window, 'translatewpPro', { value, writable: true, configurable: true });
};

const AdminSurfaces = () => (
    <>
        <Header />
        <LanguagesSwitcher />
        <TranslationsContext />
        <AiProviders />
        <SeoTools />
        <CustomOption feature="Custom style" slot="translationStyle" kind="style" selected="neutral" onChange={() => {}} />
        <CustomOption feature="Custom tone" slot="translationTone" kind="tone" selected="friendly" onChange={() => {}} />
    </>
);

let root: Root | null = null;
let container: HTMLElement | null = null;

const renderAdminSurfaces = async (): Promise<string> =>
{
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () =>
    {
        root!.render(<AdminSurfaces />);
    });
    return container.innerHTML;
};

const unmountAll = async () =>
{
    await act(async () =>
    {
        root?.unmount();
    });
    root = null;
    container = null;
    document.body.innerHTML = '';
    setProState(undefined);
};

describe('entitled admin with the addon active shows no upgrade surface', () =>
{
    beforeEach(() =>
    {
        mockProStatus = { entitled: true, installed: true, active: true };
        setProState({ connected: true, entitled: true, installed: true, active: true });
        SLOTS.forEach((slot) => addFilter(`translatewp.slot.${slot}`, FILTER_NAMESPACE, () => RegisteredPanel));
    });

    afterEach(async () =>
    {
        await unmountAll();
        SLOTS.forEach((slot) => removeFilter(`translatewp.slot.${slot}`, FILTER_NAMESPACE));
    });

    it('renders no /upgrade link and no upgrade button anywhere in the admin when entitled', async () =>
    {
        const html = await renderAdminSurfaces();

        expect(html).not.toContain('/upgrade');
        expect(html).not.toContain('Upgrade to PRO');
    });

    it('renders no "Unlock" control anywhere in the admin when entitled', async () =>
    {
        const html = await renderAdminSurfaces();

        expect(html).not.toContain('Unlock');
    });

    it('shows the registered Pro panels instead of locked rows when entitled', async () =>
    {
        const html = await renderAdminSurfaces();

        expect(html).toContain('Registered Pro panel');
    });
});

describe('entitled admin without the addon offers the install, never the upgrade', () =>
{
    beforeEach(() =>
    {
        mockProStatus = { entitled: true, installed: false, active: false };
        setProState({ connected: true, entitled: true, installed: false, active: false });
    });

    afterEach(unmountAll);

    it('opens the purchase-done prompt with Install now on SEO Tools and never says the addon needs an update', async () =>
    {
        await renderAdminSurfaces();
        const body = document.body.innerHTML;

        expect(body).not.toContain('does not include');
        expect(body).not.toContain('Upgrade to PRO');
        expect(body).toContain('Your account includes Pro');
        expect(body).toContain('Install now');
    });

    it('marks every locked feature with Install Pro instead of the Pro tag', async () =>
    {
        await renderAdminSurfaces();

        const markers = [...container!.querySelectorAll('button')].map(button => button.textContent?.trim()).filter(text => text === 'Install Pro');
        expect(markers.length).toBeGreaterThanOrEqual(4);
        const tags = [...container!.querySelectorAll('span')].filter(span => span.children.length === 0 && span.textContent?.trim() === 'Pro');
        expect(tags).toHaveLength(0);
    });
});

describe('admin without Pro opens the upgrade prompt on SEO Tools', () =>
{
    beforeEach(() =>
    {
        mockProStatus = { entitled: false, installed: false, active: false };
        setProState({ connected: true, entitled: false, installed: false, active: false, upgradeUrl: 'https://example.test/upgrade' });
    });

    afterEach(unmountAll);

    it('shows the upsell modal on load with the upgrade action', async () =>
    {
        await renderAdminSurfaces();
        const body = document.body.innerHTML;

        expect(body).toContain('Unlock SEO Tools');
        expect(body).toContain('Upgrade to PRO');
        expect(body).toContain('https://example.test/upgrade');
        expect(body).not.toContain('does not include');
    });
});
