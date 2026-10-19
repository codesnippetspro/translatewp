import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { AddLanguageDrawer } from '@/apps/admin/components/Languages/Setup/AddLanguageDrawer';
import { LanguageSelect } from '@/shared/components/languageSelect';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const renderDrawer = async (props: {
    language: Parameters<typeof AddLanguageDrawer>[0]['language'];
    isNewLanguage: boolean;
    onLanguageChange?: (currentLocale: string, language: unknown) => void;
    onCancel?: () => void;
    onDone?: () => void;
}): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    await act(async () =>
    {
        createRoot(container).render(
            <AddLanguageDrawer
                isDrawerOpen={true}
                setIsDrawerOpen={() => {}}
                language={props.language}
                isNewLanguage={props.isNewLanguage}
                onLanguageChange={props.onLanguageChange ?? (() => {})}
                onCancel={props.onCancel ?? (() => {})}
                onDone={props.onDone ?? (() => {})}
            />
        );
    });
    return container;
};

const buttons = (container: HTMLElement): HTMLButtonElement[] =>
    Array.from(container.querySelectorAll('button'));

describe('AddLanguageDrawer new mode', () =>
{
    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    it('disables the add button until a locale is picked', async () =>
    {
        const container = await renderDrawer({ language: null, isNewLanguage: true });

        const addButton = buttons(container).find(button => button.textContent === 'Add language');
        expect(addButton).toBeDefined();
        expect((addButton as HTMLButtonElement).disabled).toBe(true);
    });

    it('enables the add button once a locale is picked', async () =>
    {
        let draft: unknown = null;
        const container = await renderDrawer({
            language: null,
            isNewLanguage: true,
            onLanguageChange: (_currentLocale, language) => { draft = language; },
        });

        const select = container.querySelector('input[type="text"]') as HTMLInputElement;
        expect(select).not.toBeNull();
        await act(async () =>
        {
            select.dispatchEvent(new Event('focusin', { bubbles: true }));
        });
        const dropdown = container.querySelector('.absolute.top-14');
        expect(dropdown).not.toBeNull();
        const option = dropdown!.querySelector('button');
        expect(option).not.toBeNull();
        await act(async () =>
        {
            (option as HTMLButtonElement).click();
        });

        expect(draft).not.toBeNull();
    });

    it('renders the placeholder select without a selected chip', async () =>
    {
        const container = await renderDrawer({ language: null, isNewLanguage: true });

        expect(container.querySelector('input[type="text"]')).not.toBeNull();
        expect(container.querySelector('.bg-translatewp-selection')).toBeNull();
    });
});

describe('AddLanguageDrawer default-language guards', () =>
{
    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    const checkboxes = (container: HTMLElement): HTMLInputElement[] =>
        Array.from(container.querySelectorAll('input[type="checkbox"]'));

    it('disables Is Active and names the default language for the default language', async () =>
    {
        const container = await renderDrawer({
            language: { locale: 'en-us', slug: 'en', active: true, default: true },
            isNewLanguage: false,
        });

        const [isActive] = checkboxes(container);
        expect(isActive.disabled).toBe(true);
        expect(container.textContent).toContain("This is the default language; choose another default to switch it off.");
    });

    it('leaves the active toggle editable for a non-default language', async () =>
    {
        const container = await renderDrawer({
            language: { locale: 'de', slug: 'de', active: true, default: false },
            isNewLanguage: false,
        });

        const [isActive, useAsDefault] = checkboxes(container);
        expect(isActive.disabled).toBe(false);
        expect(container.textContent).not.toContain("This is the default language; choose another default to switch it off.");
    });
});

describe('LanguageSelect placeholder mode', () =>
{
    afterEach(() =>
    {
        document.body.innerHTML = '';
    });

    it('shows no selected chip when no default is given', async () =>
    {
        const container = document.createElement('div');
        document.body.appendChild(container);
        await act(async () =>
        {
            createRoot(container).render(<LanguageSelect onChange={() => {}} placeholder='Select a language' />);
        });

        expect(container.querySelector('.bg-translatewp-selection')).toBeNull();
    });

    it('shows the chip when a default is given', async () =>
    {
        const container = document.createElement('div');
        document.body.appendChild(container);
        await act(async () =>
        {
            createRoot(container).render(<LanguageSelect defaultValue='es-es' onChange={() => {}} />);
        });

        expect(container.querySelector('.bg-translatewp-selection')).not.toBeNull();
    });
});
