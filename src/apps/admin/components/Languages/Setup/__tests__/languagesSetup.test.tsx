import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { LanguagesSetup } from '@/apps/admin/components/Languages/Setup';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import type { Language } from '@/shared/components/languageTable';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mountedRoots: Root[] = [];

const seedStore = (languages: Language[]) =>
{
    useAdminSettingsStore.getState().initializeSettings({
        'languages.list': languages,
        'languages.switcher': useAdminSettingsStore.getState().settings['languages.switcher'],
        'translations.settings': useAdminSettingsStore.getState().settings['translations.settings'],
        'translations.context': useAdminSettingsStore.getState().settings['translations.context'],
        'translations.style': useAdminSettingsStore.getState().settings['translations.style'],
        'setup': useAdminSettingsStore.getState().settings['setup'],
    });
};

const renderSetup = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    mountedRoots.push(root);
    await act(async () =>
    {
        root.render(<LanguagesSetup />);
    });
    return container;
};

const flush = async () =>
{
    await act(async () =>
    {
        await new Promise((resolve) => setTimeout(resolve, 0));
    });
};

const clickButton = async (container: HTMLElement, label: string) =>
{
    const button = Array.from(container.querySelectorAll('button'))
        .find(candidate => (candidate.textContent ?? '').trim() === label);
    expect(button).toBeDefined();
    await act(async () =>
    {
        (button as HTMLButtonElement).click();
    });
    await flush();
};

const pickLocale = async (container: HTMLElement) =>
{
    // The list is always open; tick the first offered language.
    const box = container.querySelector('input[type="checkbox"]') as HTMLInputElement;
    expect(box).not.toBeNull();
    await act(async () =>
    {
        box.click();
    });
    await flush();
};

describe('LanguagesSetup add-language flow', () =>
{
    beforeEach(() =>
    {
        useAdminSettingsStore.setState({
            isLoading: false,
            isInitialized: false,
            hasChanges: false,
            settings: useAdminSettingsStore.getState().settings,
            persistedSettings: useAdminSettingsStore.getState().persistedSettings,
        });
        seedStore([
            { locale: 'en-us', slug: 'en', active: true, default: true },
        ]);
    });

    afterEach(async () =>
    {
        await act(async () =>
        {
            for (const root of mountedRoots.splice(0))
            {
                root.unmount();
            }
        });
        document.body.innerHTML = '';
    });

    it('does not open the edit-language drawer on a clean mount', async () =>
    {
        const container = await renderSetup();

        expect(container.querySelector('[role="dialog"]')).toBeNull();
        expect(container.textContent).not.toContain('Edit Language');
    });

    it('closes the drawer on Escape once it has been opened', async () =>
    {
        const container = await renderSetup();

        await clickButton(container, 'Add languages');
        expect(container.querySelector('[role="dialog"]')).not.toBeNull();

        await act(async () =>
        {
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        });
        await flush();

        expect(container.querySelector('[role="dialog"]')).toBeNull();
    });

    it('does not dirty the settings store on open or cancel', async () =>
    {
        const container = await renderSetup();

        await clickButton(container, 'Add languages');
        expect(useAdminSettingsStore.getState().hasChanges).toBe(false);

        await clickButton(container, 'Cancel');
        expect(useAdminSettingsStore.getState().hasChanges).toBe(false);
        expect(useAdminSettingsStore.getState().settings['languages.list']).toHaveLength(1);
    });

    it('appends the picked language on Done through the duplicate guard', async () =>
    {
        const container = await renderSetup();

        await clickButton(container, 'Add languages');
        await pickLocale(container);
        await clickButton(container, 'Add language');

        const list = useAdminSettingsStore.getState().settings['languages.list'];
        expect(list.length).toBeGreaterThan(1);
        expect(useAdminSettingsStore.getState().hasChanges).toBe(true);
    });

    it('no longer offers a language once it is on the site', async () =>
    {
        const container = await renderSetup();
        await clickButton(container, 'Add languages');
        const offeredBefore = container.querySelectorAll('input[type="checkbox"]').length;
        await pickLocale(container);
        await clickButton(container, 'Add language');
        const afterFirst = useAdminSettingsStore.getState().settings['languages.list'].length;
        await clickButton(container, 'Add languages');
        expect(container.querySelectorAll('input[type="checkbox"]').length).toBe(offeredBefore - 1);
        await clickButton(container, 'Cancel');
        expect(useAdminSettingsStore.getState().settings['languages.list'].length).toBe(afterFirst);
    });

    it('deletes a language without throwing', async () =>
    {
        useAdminSettingsStore.setState({ isInitialized: false });
        seedStore([
            { locale: 'en-us', slug: 'en', active: true, default: true },
            { locale: 'fr', slug: 'fr', active: true, default: false },
        ]);
        const container = await renderSetup();

        const deleteButton = Array.from(container.querySelectorAll('button'))
            .find(candidate => candidate.getAttribute('aria-label') === 'Delete French');
        expect(deleteButton).toBeDefined();
        await act(async () =>
        {
            (deleteButton as HTMLButtonElement).click();
        });
        await flush();

        const confirmButton = Array.from(document.querySelectorAll('[role="dialog"] button'))
            .find(candidate => (candidate.textContent ?? '').trim() === 'Delete');
        expect(confirmButton).toBeDefined();
        await act(async () =>
        {
            (confirmButton as HTMLButtonElement).click();
        });
        await flush();

        expect(useAdminSettingsStore.getState().settings['languages.list']).toHaveLength(1);
    });

    it('gives each row edit and delete action a language-specific accessible name', async () =>
    {
        useAdminSettingsStore.setState({ isInitialized: false });
        seedStore([
            { locale: 'en-us', slug: 'en', active: true, default: true },
            { locale: 'de', slug: 'de', active: true, default: false },
            { locale: 'it', slug: 'it', active: true, default: false },
        ]);
        const container = await renderSetup();

        const ariaLabels = Array.from(container.querySelectorAll('button'))
            .map(button => button.getAttribute('aria-label'))
            .filter((label): label is string => label !== null);

        expect(ariaLabels).toEqual(expect.arrayContaining([
            'Edit American English',
            'Delete American English',
            'Edit German',
            'Delete German',
            'Edit Italian',
            'Delete Italian',
        ]));
        expect(ariaLabels).not.toContain('Edit language');
        expect(ariaLabels).not.toContain('Delete language');
    });

    it('shows an in-list add action without the frontend promo row when languages are empty', async () =>
    {
        useAdminSettingsStore.setState({ isInitialized: false });
        seedStore([]);
        const container = await renderSetup();

        expect(container.textContent).not.toContain('by TranslateWP');
        await clickButton(container, 'Add a language');
        expect(container.textContent).toContain('Add Language');
    });
});

describe('LanguagesSetup slug focus after a refused save', () =>
{
    afterEach(() =>
    {
        mountedRoots.splice(0).forEach(root => act(() => root.unmount()));
        document.body.innerHTML = '';
    });

    it('opens the clashing language with its slug field focused', async () =>
    {
        useAdminSettingsStore.setState({ isLoading: false, isInitialized: false, hasChanges: false, slugFocusLocale: null } as never);
        seedStore([
            { locale: 'en-us', slug: 'en', active: true, default: true },
            { locale: 'de-at', slug: 'de', active: true, default: false },
            { locale: 'de', slug: 'de', active: true, default: false },
        ]);
        await renderSetup();

        await act(async () => { useAdminSettingsStore.setState({ slugFocusLocale: 'de' } as never); });
        await flush();

        const focused = document.activeElement as HTMLInputElement | null;
        expect(focused?.tagName).toBe('INPUT');
        expect(focused?.value).toBe('de');
        expect(document.body.textContent).toContain('Edit Language');
        expect(useAdminSettingsStore.getState().slugFocusLocale).toBeNull();
    });
});
