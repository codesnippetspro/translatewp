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

const choose = async (container: HTMLElement, label: string) =>
{
    const card = Array.from(container.querySelectorAll('[data-option-cards="background-style"] [role="radio"]'))
        .find(element => element.textContent?.includes(label)) as HTMLButtonElement;
    await act(async () => { card.click(); });
};

const switcher = () => useAdminSettingsStore.getState().settings['languages.switcher'];

describe('Settings, Switcher: the background choice', () =>
{
    beforeEach(() =>
    {
        useAdminSettingsStore.getState().setSetting('languages.switcher', previous => ({ ...previous, background_style: 'solid', text_color: '#393535' }));
    });

    afterEach(async () =>
    {
        await act(async () => { root?.unmount(); });
        root = null;
        document.body.innerHTML = '';
    });

    it('offers solid, transparent and matte glass, and shows the preview over a busy background for the see-through ones', async () =>
    {
        const container = await render();
        const labels = Array.from(container.querySelectorAll('[data-option-cards="background-style"] [role="radio"]')).map(card => card.textContent);
        expect(labels.map(label => label?.split(/(?=[A-Z][a-z]+ )/)[0])).toHaveLength(3);

        await choose(container, 'Matte glass');
        expect(switcher().background_style).toBe('glass');
        expect(container.querySelector('[data-twp-switcher-preview]')?.getAttribute('data-busy-background')).toBe('true');

        await choose(container, 'Solid');
        expect(container.querySelector('[data-twp-switcher-preview]')?.getAttribute('data-busy-background')).toBeNull();
    });

    it('brings white text with Transparent while the text colour is the default, and gives the default back after', async () =>
    {
        const container = await render();

        await choose(container, 'Transparent');
        expect(switcher()).toMatchObject({ background_style: 'transparent', text_color: '#FFFFFF' });
        expect(container.querySelector('[data-background-color-note]')).not.toBeNull();

        await choose(container, 'Matte glass');
        expect(switcher()).toMatchObject({ background_style: 'glass', text_color: '#393535' });
    });

    it('keeps a text colour the owner chose', async () =>
    {
        useAdminSettingsStore.getState().setSetting('languages.switcher', previous => ({ ...previous, text_color: '#AA0000' }));
        const container = await render();

        await choose(container, 'Transparent');
        expect(switcher().text_color).toBe('#AA0000');
    });
});
