import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { LanguagesSwitcher } from '@/apps/admin/components/Languages/Switcher';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

jest.mock('@tanstack/react-query', () => ({
    queryOptions: (options: unknown) => options,
    useQuery: () => ({ data: undefined }),
    useMutation: () => ({ mutate: () => {}, isPending: false }),
    useQueryClient: () => ({ invalidateQueries: async () => {} }),
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root | null = null;

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () => { root?.render(<LanguagesSwitcher />); });
    return container;
};

const card = (container: HTMLElement): HTMLElement => container.querySelector('[data-support-translatewp]') as HTMLElement;
const toggleIn = (element: HTMLElement): HTMLInputElement => element.querySelector('input[type="checkbox"]') as HTMLInputElement;
const switcher = () => useAdminSettingsStore.getState().settings['languages.switcher'];

const textOf = (ids: string | null): string => (ids ?? '').split(' ').filter(Boolean).map(id => document.getElementById(id)?.textContent?.trim() ?? '').join(' ');
const accessibleName = (input: HTMLInputElement): string => input.getAttribute('aria-labelledby')
    ? textOf(input.getAttribute('aria-labelledby'))
    : (input.labels?.[0]?.textContent?.trim() ?? '');

const setPro = (active: boolean) =>
{
    (window as unknown as { translatewpPro?: unknown }).translatewpPro = active
        ? { connected: true, entitled: true, installed: true, active: true, version: '1.0.0', upgradeUrl: '' }
        : { connected: true, entitled: false, installed: false, active: false, version: '', upgradeUrl: '' };
};

const setEntitledWithoutAddon = () =>
{
    (window as unknown as { translatewpPro?: unknown }).translatewpPro = { connected: true, entitled: true, installed: false, active: false, version: '', upgradeUrl: '' };
};

describe('Settings, Switcher: Support TranslateWP', () =>
{
    afterEach(async () =>
    {
        await act(async () => { root?.unmount(); });
        root = null;
        document.body.innerHTML = '';
        delete (window as unknown as { translatewpPro?: unknown }).translatewpPro;
    });

    it('on Pro it is off by default, says what it shows, and turning it on stores the line as shown', async () =>
    {
        setPro(true);
        useAdminSettingsStore.getState().setSetting('languages.switcher', previous => ({ ...previous, hide_branding: undefined }));
        const container = await render();

        expect(card(container).textContent).toContain('Support TranslateWP');
        expect(card(container).textContent).toContain('by TranslateWP');
        expect(toggleIn(card(container)).checked).toBe(false);
        expect(toggleIn(card(container)).disabled).toBe(false);

        await act(async () => { toggleIn(card(container)).click(); });
        expect(switcher().hide_branding).toBe(false);
        expect(toggleIn(card(container)).checked).toBe(true);

        await act(async () => { toggleIn(card(container)).click(); });
        expect(switcher().hide_branding).toBe(true);
    });

    it('on Free it stays on and locked, with the Pro marker', async () =>
    {
        setPro(false);
        useAdminSettingsStore.getState().setSetting('languages.switcher', previous => ({ ...previous, hide_branding: true }));
        const container = await render();

        expect(toggleIn(card(container)).checked).toBe(true);
        expect(toggleIn(card(container)).disabled).toBe(true);
        expect(card(container).textContent).toContain('Pro');
        expect(card(container).textContent).not.toContain('Hide branding');
    });

    it('an Orbital site without the Pro addon has the same choice: off by default and working', async () =>
    {
        setEntitledWithoutAddon();
        useAdminSettingsStore.getState().setSetting('languages.switcher', previous => ({ ...previous, hide_branding: undefined }));
        const container = await render();

        expect(toggleIn(card(container)).checked).toBe(false);
        expect(toggleIn(card(container)).disabled).toBe(false);
        expect(card(container).textContent).not.toContain('Pro lets you turn it off');

        await act(async () => { toggleIn(card(container)).click(); });
        expect(switcher().hide_branding).toBe(false);
    });

    it.each([
        ['an Orbital site', () => setPro(true), 'It is how most site owners find us, and it means a lot to the team.'],
        ['a Terrestrial site', () => setPro(false), 'Free sites show a small'],
    ])('on %s the switch is a checkbox named Support TranslateWP, described by its sentence', async (_site, arrange, sentence) =>
    {
        (arrange as () => void)();
        const container = await render();
        const input = toggleIn(card(container));

        expect(input.type).toBe('checkbox');
        expect(input.getAttribute('role') ?? 'checkbox').toBe('checkbox');
        expect(accessibleName(input)).toBe('Support TranslateWP');
        expect(textOf(input.getAttribute('aria-describedby'))).toContain(sentence as string);
        expect(card(container).textContent).not.toContain('a small team');
    });
});
