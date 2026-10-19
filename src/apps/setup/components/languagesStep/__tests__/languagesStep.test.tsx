import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { LanguagesStep } from '@/apps/setup/components/languagesStep';
import { useSetupStore } from '@/apps/setup/stores/setupStore';

jest.mock('@/shared/api/admin', () => ({
    getIndexPages: jest.fn(() => Promise.resolve({
        pages: Array.from({ length: 12 }, (_, index) => ({ objectType: 'page', objectId: index + 1, url: `https://example.com/r${index}/`, title: `R${index}`, languages: {} })),
    })),
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let currentRoot: Root | null = null;

const renderStep = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    currentRoot = root;
    await act(async () =>
    {
        root.render(<LanguagesStep />);
    });
    await act(async () =>
    {
        await new Promise((resolve) => setTimeout(resolve, 0));
    });
    return container;
};

describe('LanguagesStep', () =>
{
    beforeEach(() =>
    {
        useSetupStore.setState({ languages: [], defaultLanguage: 'en-us' });
    });

    afterEach(async () =>
    {
        if (currentRoot) {
            await act(async () => { currentRoot?.unmount(); });
            currentRoot = null;
        }
        document.body.innerHTML = '';
    });

    it('leaves the default language to the connection line and starts with no translation language', async () =>
    {
        const container = await renderStep();

        expect(container.textContent).not.toContain('Default language');
        expect(container.querySelectorAll('[aria-label="Filter languages"]')).toHaveLength(1);
        expect(container.textContent).toContain('No translation languages added yet.');
        expect(useSetupStore.getState().languages).toEqual([]);
        expect(container.querySelector('[aria-label^="Remove "]')).toBeNull();
    });

    it('offers one-click target suggestions for the source locale', async () =>
    {
        const container = await renderStep();

        expect(container.querySelector('[aria-label="Add Spanish"]')).not.toBeNull();
        expect(container.querySelector('[aria-label="Add French"]')).not.toBeNull();
        expect(container.querySelector('[aria-label="Add German"]')).not.toBeNull();
    });

    it('separates quick picks and selected chips from the always-visible checklist', async () =>
    {
        const container = await renderStep();
        const layout = container.querySelector('[data-language-target-layout]');
        const summary = container.querySelector('[data-language-summary]');
        const checklist = container.querySelector('[data-language-checklist]');

        expect(layout).not.toBeNull();
        expect(summary?.querySelector('[aria-label="Add Spanish"]')).not.toBeNull();
        expect(checklist?.querySelector('[role="group"][aria-label="Languages"]')).not.toBeNull();
        expect(checklist?.querySelector('[aria-live="polite"]')).toBeNull();

        await act(async () =>
        {
            (summary?.querySelector('[aria-label="Add Spanish"]') as HTMLButtonElement).click();
        });

        expect(summary?.querySelector('[aria-label="Remove Spanish"]')).not.toBeNull();
        expect((checklist?.querySelector('input[aria-label="Spanish"]') as HTMLInputElement).checked).toBe(true);
    });

    it('adds a language on one click without preselecting it, updating the active count', async () =>
    {
        const container = await renderStep();

        const addSpanish = container.querySelector('[aria-label="Add Spanish"]') as HTMLButtonElement;
        expect(addSpanish).not.toBeNull();

        await act(async () =>
        {
            addSpanish.click();
        });

        expect(useSetupStore.getState().languages).toContain('es');
        expect(container.textContent).toContain('1 translation language active.');
        // The added language is now a selected chip, and its suggestion is gone.
        expect(container.querySelector('[aria-label="Remove Spanish"]')).not.toBeNull();
        expect(container.querySelector('[aria-label="Add Spanish"]')).toBeNull();
    });
    it('keeps every language when two suggestion chips are pressed before the step re-renders', async () =>
    {
        const container = await renderStep();

        const addGerman = container.querySelector('[aria-label="Add German"]') as HTMLButtonElement;
        const addFrench = container.querySelector('[aria-label="Add French"]') as HTMLButtonElement;

        await act(async () =>
        {
            addGerman.click();
            addFrench.click();
        });

        expect(useSetupStore.getState().languages).toEqual(['de', 'fr']);
        expect(container.textContent).toContain('2 translation languages active.');
        expect(container.querySelector('[aria-label="Remove German"]')).not.toBeNull();
        expect(container.querySelector('[aria-label="Remove French"]')).not.toBeNull();
    });

    it('never offers the default language as a translation language and shows every selected language', async () =>
    {
        useSetupStore.setState({ languages: ['en-us', 'es', 'de', 'fr', 'it', 'he', 'ja'], defaultLanguage: 'en-us' });
        const container = await renderStep();

        expect(container.querySelector('[aria-label="Remove American English"]')).toBeNull();
        expect(container.querySelector('input[aria-label="American English"]')).toBeNull();
        expect(container.textContent).toContain('6 translation languages active.');
        const chips = container.querySelector('[data-language-summary] [aria-live="polite"]');
        expect(chips?.querySelectorAll('[aria-label^="Remove "]')).toHaveLength(6);
        expect(chips?.className).not.toContain('overflow-y-auto');
    });

    it('shows the address slug each language will get', async () =>
    {
        useSetupStore.setState({ languages: ['de-at', 'de'], defaultLanguage: 'de-at' });
        const container = await renderStep();

        expect(container.querySelector('[data-language-slug="de"]')?.textContent).toBe('/de-de/');

        await act(async () => { useSetupStore.setState({ languages: ['en-us', 'de', 'es-es'], defaultLanguage: 'en-us' }); });
        expect(container.querySelector('[data-language-slug="de"]')?.textContent).toBe('/de/');
        expect(container.querySelector('[data-language-slug="es-es"]')?.textContent).toBe('/es/');
    });
});


describe('changing the default language', () =>
{
    it('drops the previous default from the translation languages instead of keeping it as a target', () =>
    {
        useSetupStore.setState({ defaultLanguage: 'en-us', languages: ['en-us', 'de', 'fr'] });
        useSetupStore.getState().setDefaultLanguage('lv');
        expect(useSetupStore.getState().defaultLanguage).toBe('lv');
        expect(useSetupStore.getState().languages).toEqual(['de', 'fr']);
    });

    it('removes the new default from the targets when it was one', () =>
    {
        useSetupStore.setState({ defaultLanguage: 'en-us', languages: ['de', 'lv'] });
        useSetupStore.getState().setDefaultLanguage('lv');
        expect(useSetupStore.getState().languages).toEqual(['de']);
    });
});

describe('LanguagesStep estimate before committing', () =>
{
    afterEach(async () =>
    {
        if (currentRoot) {
            await act(async () => { currentRoot?.unmount(); });
            currentRoot = null;
        }
        document.body.innerHTML = '';
    });

    it('states the pages, languages and page translations the choice commits to, once pages were read', async () =>
    {
        useSetupStore.setState({
            languages: ['it', 'lv'],
            defaultLanguage: 'en-us',
            spinePages: Array.from({ length: 25 }, (_, index) => ({ objectType: 'page', objectId: index + 1, url: `https://example.com/p${index}/`, title: `P${index}`, languages: {} })),
        });
        const container = await renderStep();

        const estimate = container.querySelector('[data-setup-estimate]');
        expect(estimate?.textContent).toContain('Translating 25 pages into 2 languages.');
        expect(estimate?.textContent).toContain('This counts 50 page translations towards your quota.');
    });

    it('shows no estimate before the site was read', async () =>
    {
        useSetupStore.setState({ languages: ['it'], defaultLanguage: 'en-us', spinePages: [], siteRead: false });
        const container = await renderStep();

        expect(container.querySelector('[data-setup-estimate]')).toBeNull();
    });

    it('reads the pages back from the site when the wizard resumes at this step after a read', async () =>
    {
        useSetupStore.setState({ languages: ['it', 'lv'], defaultLanguage: 'en-us', spinePages: [], siteRead: true });
        const container = await renderStep();
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        const estimate = container.querySelector('[data-setup-estimate]');
        expect(estimate?.textContent).toContain('Translating 12 pages into 2 languages.');
        expect(estimate?.textContent).toContain('This counts 24 page translations towards your quota.');
    });
});
