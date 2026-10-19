import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { NetworkAdmin } from '@/apps/network-admin/NetworkAdmin';
import { networkAdminData } from '@/apps/network-admin/__fixtures__/networkAdminData';
import type { NetworkAdminData } from '@/apps/network-admin/types';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root | null = null;
const submitted: Array<Record<string, string[]>> = [];

beforeAll(() =>
{
    HTMLFormElement.prototype.requestSubmit = function requestSubmit()
    {
        const fields: Record<string, string[]> = {};
        new FormData(this).forEach((value, key) => { (fields[key] ??= []).push(String(value)); });
        submitted.push(fields);
    };
});

const render = async (data: NetworkAdminData = networkAdminData): Promise<HTMLElement> =>
{
    window.history.replaceState(null, '', '/wp-admin/network/admin.php?page=translatewp-network-settings#settings');
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () =>
    {
        root!.render(<QueryClientProvider client={new QueryClient()}><NetworkAdmin data={data} /></QueryClientProvider>);
    });
    return container;
};

const groupCard = (container: HTMLElement, label: string) =>
    container.querySelector(`[data-scope-group="${label}"]`) as HTMLElement;

const button = (scope: HTMLElement, label: string) =>
    Array.from(scope.querySelectorAll('button')).find(candidate => candidate.textContent === label) as HTMLButtonElement | undefined;

describe('the network Settings scope section', () =>
{
    beforeEach(() => { submitted.length = 0; });
    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
    });

    it('lists one row per group with its state, and the network-only groups as locked', async () =>
    {
        const container = await render();
        const appearance = groupCard(container, 'switcher_appearance');
        const radios = Array.from(appearance.querySelectorAll('input[type="radio"][name="state"]')) as HTMLInputElement[];
        expect(radios.map(radio => radio.value)).toEqual(['locked', 'default', 'local']);
        expect(radios.find(radio => radio.checked)?.value).toBe('default');

        const uninstall = groupCard(container, 'uninstall');
        expect(uninstall.querySelector('input[name="state"]')).toBeNull();
        expect(uninstall.textContent).toContain('Locked for every subsite');
        expect(uninstall.textContent).toContain('Delete TranslateWP data when the plugin is deleted');

        const account = groupCard(container, 'account');
        expect(account.textContent).toContain('The account connection');
        expect(account.querySelector('[data-scope-form]')).toBeNull();
        expect(account.textContent).toContain('Reconnect all sites');
    });

    it('shows a stored locked state selected, with its exceptions', async () =>
    {
        const locked = {
            ...networkAdminData,
            scope: {
                ...networkAdminData.scope,
                groups: networkAdminData.scope.groups.map(group => group.id === 'switcher_appearance'
                    ? { ...group, state: 'locked' as const, exceptions: { '1': 'local' as const, '3': 'local' as const } }
                    : group),
            },
        };
        const container = await render(locked);
        const appearance = groupCard(container, 'switcher_appearance');
        const checked = appearance.querySelector('input[name="state"]:checked') as HTMLInputElement;
        expect(checked.value).toBe('locked');
        expect(appearance.textContent).toContain('Main: set by the subsite');
        expect(appearance.textContent).toContain('Shop: set by the subsite');
        expect(button(appearance, 'Save scope')!.disabled).toBe(true);
    });

    it('saves a group state through the network form', async () =>
    {
        const container = await render();
        const appearance = groupCard(container, 'switcher_appearance');
        const locked = appearance.querySelector('input[name="state"][value="locked"]') as HTMLInputElement;
        await act(async () => { locked.click(); });
        await act(async () => { button(appearance, 'Save scope')!.click(); });

        expect(submitted.at(-1)).toMatchObject({ action: ['scope'], _wpnonce: ['b'], op: ['state'], group: ['switcher_appearance'], state: ['locked'] });
    });

    it('sets a value for every subsite after an in-product confirmation', async () =>
    {
        const container = await render();
        const appearance = groupCard(container, 'switcher_appearance');
        const toggle = Array.from(appearance.querySelectorAll('input[type="checkbox"]'))[0] as HTMLInputElement;
        await act(async () => { toggle.click(); });

        await act(async () => { button(appearance, 'Save value')!.click(); });
        expect(submitted).toHaveLength(0);
        expect(document.body.textContent).toContain('Every subsite takes this value, replacing its own.');

        const confirm = Array.from(document.querySelectorAll('button')).find(candidate => candidate.textContent === 'Apply to every subsite') as HTMLButtonElement;
        await act(async () => { confirm.click(); });
        expect(submitted.at(-1)).toMatchObject({
            op: ['value'], group: ['switcher_appearance'], apply_to: ['all'],
            'values[languages.switcher.show_flag]': ['0'],
            'values[languages.switcher.regional_names]': ['full'],
            'values[languages.switcher.offset]': ['24'],
            'values[languages.switcher.pending_message]': [''],
        });
    });

    it('offers a text field for the translating message and submits what is typed', async () =>
    {
        const container = await render();
        const appearance = groupCard(container, 'switcher_appearance');
        const input = appearance.querySelector('[data-text-field="languages.switcher.pending_message"] input') as HTMLInputElement;
        expect(input).not.toBeNull();
        expect(input.maxLength).toBe(200);
        expect(input.placeholder).toBe('Translating this page for you, a few more seconds.');
        expect(appearance.textContent).toContain('Shown while a page translates.');

        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!;
        await act(async () =>
        {
            setter.call(input, 'Bitte warten');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        await act(async () => { button(appearance, 'Save value')!.click(); });
        const confirm = Array.from(document.querySelectorAll('button')).find(candidate => candidate.textContent === 'Apply to every subsite') as HTMLButtonElement;
        await act(async () => { confirm.click(); });
        expect(submitted.at(-1)).toMatchObject({ 'values[languages.switcher.pending_message]': ['Bitte warten'] });
    });

    it('sets a value for chosen subsites only', async () =>
    {
        const container = await render();
        const appearance = groupCard(container, 'switcher_appearance');
        const chosen = appearance.querySelector('input[name="apply_to"][value="chosen"]') as HTMLInputElement;
        await act(async () => { chosen.click(); });
        expect(button(appearance, 'Save value')!.disabled).toBe(true);

        const shop = appearance.querySelector('input[type="checkbox"][data-value-site="3"]') as HTMLInputElement;
        await act(async () => { shop.click(); });
        await act(async () => { button(appearance, 'Save value')!.click(); });
        expect(submitted.at(-1)).toMatchObject({ op: ['value'], apply_to: ['chosen'], 'sites[]': ['3'] });
    });

    it('unlocks or locks a group for chosen subsites and lists the exceptions', async () =>
    {
        const container = await render();
        const appearance = groupCard(container, 'switcher_appearance');
        expect(appearance.textContent).toContain('Shop: set by the subsite');

        const editorial = appearance.querySelector('input[type="checkbox"][data-exception-site="2"]') as HTMLInputElement;
        await act(async () => { editorial.click(); });
        await act(async () => { button(appearance, 'Unlock for chosen subsites')!.click(); });
        expect(submitted.at(-1)).toMatchObject({ op: ['exceptions'], group: ['switcher_appearance'], exception: ['local'], 'sites[]': ['2'] });

        await act(async () => { button(appearance, 'Lock for chosen subsites')!.click(); });
        expect(submitted.at(-1)).toMatchObject({ exception: ['locked'] });
    });

    it('clears every exception of a group after an in-product confirmation, without ticking subsites', async () =>
    {
        const container = await render();
        const appearance = groupCard(container, 'switcher_appearance');
        const clear = button(appearance, 'Clear exceptions')!;
        expect(clear.disabled).toBe(false);

        await act(async () => { clear.click(); });
        expect(submitted).toHaveLength(0);
        expect(document.body.textContent).toContain('Clear every exception for Switcher appearance?');

        const confirm = Array.from(document.querySelector('[role="dialog"]')!.querySelectorAll('button')).find(candidate => candidate.textContent === 'Clear exceptions') as HTMLButtonElement;
        await act(async () => { confirm.click(); });
        expect(submitted.at(-1)).toMatchObject({ op: ['exceptions'], group: ['switcher_appearance'], exception: ['clear'], 'sites[]': ['3'] });
    });

    it('offers no clearing for a group without exceptions', async () =>
    {
        const container = await render();
        expect(button(groupCard(container, 'style_tone'), 'Clear exceptions')!.disabled).toBe(true);
    });

    it('says when a scope change was saved or refused', async () =>
    {
        let container = await render({ ...networkAdminData, scope: { ...networkAdminData.scope, status: 'scope-saved' } });
        expect(container.textContent).toContain('Settings scope saved.');
        act(() => root?.unmount());
        container = await render({ ...networkAdminData, scope: { ...networkAdminData.scope, status: 'failed' } });
        expect(container.textContent).toContain('That change could not be saved. Check the values and try again.');
    });

    it('uses plain copy without em dashes', async () =>
    {
        const container = await render();
        expect(container.textContent).not.toMatch(/—/);
    });

    it('offers applying the main site switcher position to every subsite only with Pro', async () =>
    {
        const container = await render();
        const locked = groupCard(container, 'switcher_position');
        expect(locked.querySelector('form')).toBeNull();
        expect(locked.querySelector('button[aria-label="Unlock Switcher position for every subsite with PRO"]')).not.toBeNull();

        const withPro = await render({ ...networkAdminData, connection: { ...networkAdminData.connection, pro: { ...networkAdminData.connection.pro, installed: true, active: true } } });
        const card = groupCard(withPro, 'switcher_position');
        const form = card.querySelector('form[data-scope-form="switcher_position"]') as HTMLFormElement;
        expect(form.querySelector('input[name="op"]')?.getAttribute('value')).toBe('switcher_position');
        expect(form.querySelector('button[type="submit"]')?.textContent).toBe('Apply the main site position');
    });

    it('reports the switcher position outcome', async () =>
    {
        const applied = await render({ ...networkAdminData, scope: { ...networkAdminData.scope, status: 'position-applied' } });
        expect(applied.querySelector('[role="status"]')?.textContent).toContain('applies to every subsite');

        const none = await render({ ...networkAdminData, scope: { ...networkAdminData.scope, status: 'no-position' } });
        expect(none.querySelector('[role="alert"]')?.textContent).toContain('Drag the switcher on the main site first');
    });

    it('offers the roles as checkboxes with Pro and submits the ticked ones, or an empty value when none is ticked', async () =>
    {
        const container = await render({ ...networkAdminData, connection: { ...networkAdminData.connection, pro: { ...networkAdminData.connection.pro, installed: true, active: true } } });
        const audience = groupCard(container, 'audience');
        const boxes = Array.from(audience.querySelectorAll('[data-roles-field] input[type="checkbox"]')) as HTMLInputElement[];
        expect(boxes).toHaveLength(2);
        expect(audience.textContent).toContain('No role ticked shows translations to everyone.');

        await act(async () => { button(audience, 'Save value')!.click(); });
        let confirm = Array.from(document.querySelectorAll('button')).find(candidate => candidate.textContent === 'Apply to every subsite') as HTMLButtonElement;
        await act(async () => { confirm.click(); });
        expect(submitted.at(-1)).toMatchObject({ 'values[translations.settings.visible_roles]': [''] });

        await act(async () => { boxes[1].click(); });
        await act(async () => { button(audience, 'Save value')!.click(); });
        confirm = Array.from(document.querySelectorAll('button')).find(candidate => candidate.textContent === 'Apply to every subsite') as HTMLButtonElement;
        await act(async () => { confirm.click(); });
        expect(submitted.at(-1)).toMatchObject({ 'values[translations.settings.visible_roles][]': ['editor'] });
        expect(submitted.at(-1)!['values[translations.settings.visible_roles]']).toBeUndefined();
    });

    it('locks the fields a single site keeps behind Pro until Pro is active', async () =>
    {
        const container = await render();
        const seo = groupCard(container, 'seo_technical');
        expect(seo.querySelector('[data-pro-field="languages.seo.sitemaps"]')).not.toBeNull();
        expect(seo.querySelector('[data-pro-field="languages.seo.output_hreflang"]')).toBeNull();

        const withPro = await render({ ...networkAdminData, connection: { ...networkAdminData.connection, pro: { ...networkAdminData.connection.pro, installed: true, active: true } } });
        expect(groupCard(withPro, 'seo_technical').querySelector('[data-pro-field]')).toBeNull();
    });

    it('offers the Side tab as a Pro choice of the switcher type, picked only with Pro', async () =>
    {
        const withType = (active: boolean): NetworkAdminData =>
        {
            const data = JSON.parse(JSON.stringify(networkAdminData)) as NetworkAdminData;
            data.connection.pro = { ...data.connection.pro, active };
            const group = data.scope.groups.find(candidate => candidate.id === 'switcher_appearance')!;
            group.fields = [{ key: 'languages.switcher.type', label: 'Switcher type', type: 'select', default: 'dropdown', options: { dropdown: 'Dropdown', native_select: 'Native dropdown', side_tab: 'Side tab' }, pro_values: ['side_tab'], value: 'dropdown' }, ...group.fields];
            return data;
        };
        const sideTab = (container: HTMLElement) => Array.from(groupCard(container, 'switcher_appearance').querySelectorAll('option')).find(option => option.value === 'side_tab') as HTMLOptionElement;

        let container = await render(withType(false));
        expect(sideTab(container).disabled).toBe(true);
        expect(sideTab(container).textContent).toBe('Side tab (Pro)');
        act(() => root?.unmount());
        document.body.innerHTML = '';

        container = await render(withType(true));
        expect(sideTab(container).disabled).toBe(false);
        expect(sideTab(container).textContent).toBe('Side tab');
    });

    it('shows who can manage translations as role checkboxes per capability', async () =>
    {
        const data = JSON.parse(JSON.stringify(networkAdminData)) as NetworkAdminData;
        data.scope.groups.push({ ...data.scope.groups.find(candidate => candidate.id === 'switcher_appearance')!, id: 'role_permissions', label: 'Who can manage translations', fields: [
            { key: 'access.manage_settings', label: 'Manage settings', type: 'role_map', default: [], options: { editor: 'Editor', author: 'Author' }, value: ['editor'] },
        ] });
        const container = await render(data);
        const group = groupCard(container, 'role_permissions');
        const boxes = Array.from(group.querySelectorAll('input[type="checkbox"]')) as HTMLInputElement[];
        expect(group.textContent).toContain('Editor');
        expect(boxes.length).toBeGreaterThanOrEqual(2);
        expect(boxes.some(box => box.checked)).toBe(true);
    });
});
