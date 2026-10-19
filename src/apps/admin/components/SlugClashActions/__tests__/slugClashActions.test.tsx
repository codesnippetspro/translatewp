import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { SlugClashActions } from '@/apps/admin/components/SlugClashActions';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { updateAdminData } from '@/shared/api/admin';
import type { Language } from '@/shared/components/languageTable';

jest.mock('@/shared/api/admin', () => ({
    updateAdminData: jest.fn(),
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const saved: Language[] = [
    { locale: 'en-us', slug: 'en', active: true, default: true },
    { locale: 'de-at', slug: 'de', active: true, default: false },
];
const draft: Language[] = [...saved, { locale: 'de', slug: 'de', active: true, default: false }];

const refuseOnce = () =>
{
    (updateAdminData as jest.Mock).mockReset();
    (updateAdminData as jest.Mock).mockRejectedValueOnce({
        code: 'duplicate_language_slug',
        message: 'Two languages use the address slug "de". Give each language its own slug, then save.',
        data: { status: 400, locale: 'de', suggested_slug: 'de-de' },
    });
    (updateAdminData as jest.Mock).mockResolvedValue({ success: true });
};

const seed = () =>
{
    useAdminSettingsStore.setState({
        settings: { ...useAdminSettingsStore.getState().settings, 'languages.list': draft },
        persistedSettings: { ...useAdminSettingsStore.getState().persistedSettings, 'languages.list': saved },
        hasChanges: true,
        isSaving: false,
        saveFailed: false,
        saveError: null,
        slugClash: null,
        slugFocusLocale: null,
        revision: null,
    } as never);
};

let root: Root | null = null;

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () => { root!.render(<SlugClashActions />); });
    return container;
};

const button = (container: HTMLElement, label: string) =>
    Array.from(container.querySelectorAll('button')).find(candidate => (candidate.textContent ?? '').trim() === label);

describe('a save refused for a repeated slug', () =>
{
    beforeEach(async () =>
    {
        seed();
        refuseOnce();
        await useAdminSettingsStore.getState().saveSettings();
    });

    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
    });

    it('keeps the clashing language and the suggested slug from the refusal', () =>
    {
        expect(useAdminSettingsStore.getState().slugClash).toEqual({ locale: 'de', suggestedSlug: 'de-de' });
    });

    it('offers the suggested slug and a way to choose another', async () =>
    {
        const container = await render();
        expect(button(container, 'Use de-de')).toBeDefined();
        expect(button(container, 'Choose another slug')).toBeDefined();
    });

    it('applies the suggested slug to the clashing language and saves', async () =>
    {
        const container = await render();
        await act(async () => { button(container, 'Use de-de')!.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        const list = (updateAdminData as jest.Mock).mock.calls.at(-1)?.[1] as Language[];
        expect(list.find(language => language.locale === 'de')?.slug).toBe('de-de');
        expect(list.find(language => language.locale === 'de-at')?.slug).toBe('de');
        expect(useAdminSettingsStore.getState().saveFailed).toBe(false);
        expect(useAdminSettingsStore.getState().slugClash).toBeNull();
        expect(container.textContent).not.toContain('Use de-de');
    });

    it('asks the languages list to open the slug field of the clashing language', async () =>
    {
        const container = await render();
        await act(async () => { button(container, 'Choose another slug')!.click(); });

        expect(useAdminSettingsStore.getState().slugFocusLocale).toBe('de');
        expect(updateAdminData).toHaveBeenCalledTimes(1);
    });

    it('shows nothing once the draft changes', async () =>
    {
        const container = await render();
        await act(async () =>
        {
            useAdminSettingsStore.getState().setSetting('languages.list', previous =>
                previous.map(language => language.locale === 'de' ? { ...language, slug: 'deutsch' } : language));
        });
        expect(container.textContent).toBe('');
    });
});
