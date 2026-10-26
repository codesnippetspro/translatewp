import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { jest } from '@jest/globals';

jest.mock('@wordpress/i18n', () => ({
    ...(jest.requireActual('@wordpress/i18n') as object),
    __: (text: string) => `T:${text}`,
}));

const mockSettingsState = {
    settings: {
        'translations.settings': {
            translate_all_content: true,
            post_types: [],
            categories: [],
            preserve_html_attributes: false,
            translate_image_alt_tags: true,
            preserve_branded_terms: false,
            localized_date_time_formats: false,
            enable_units_conversion: false,
        },
    },
    setSetting: () => {},
};

jest.mock('@/apps/admin/stores/settingsStore', () => ({
    useAdminSettingsStore: (selector: (state: unknown) => unknown) => selector(mockSettingsState),
}));

const mockNoData: { data: unknown } = { data: undefined };
jest.mock('@tanstack/react-query', () => ({
    useQuery: () => mockNoData,
    useMutation: () => ({ mutate: () => {}, isPending: false }),
    useQueryClient: () => ({ invalidateQueries: () => {} }),
}));

jest.mock('@/shared/api/admin', () => ({
    getRetranslationEstimate: () => Promise.resolve(null),
    getStaleTranslations: () => Promise.resolve({ localizer: {} }),
}));
jest.mock('@/shared/mutation-options/admin', () => ({ applyLocalizerNowMutationOptions: () => ({}) }));
jest.mock('@/shared/query-options/wordpress', () => ({
    getWordPressPostTypesQueryOptions: {},
    getWordPressCategoriesQueryOptions: {},
    getWordPressRolesQueryOptions: { queryKey: ['roles'] },
}));

import { TranslationsSettings } from '@/apps/admin/components/Translations/Settings';

describe('Content and behaviour toggles', () =>
{
    let container: HTMLDivElement;
    let root: Root;

    beforeEach(() =>
    {
        (globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
        container = document.createElement('div');
        document.body.appendChild(container);
        root = createRoot(container);
        act(() => root.render(<TranslationsSettings />));
    });

    afterEach(() =>
    {
        act(() => root.unmount());
        container.remove();
    });

    // The label block of a toggle: the title plus its one-line description.
    const toggleText = (title: string) =>
    {
        const heading = Array.from(container.querySelectorAll('p'))
            .find(element => element.textContent === `T:${title}`);
        return heading?.parentElement?.textContent ?? '';
    };

    const altRow = () => container.querySelector('[data-twp-alt-text]') as HTMLElement;

    test('on Free the alt text setting is a locked, greyed-out row with the Pro tag and the upgrade path', () =>
    {
        const row = altRow();
        expect(row.textContent).toContain('T:Translate image alt tags');
        expect(row.textContent).toContain('Pro');
        const input = row.querySelector('input') as HTMLInputElement;
        expect(input.disabled).toBe(true);
        expect(input.checked).toBe(false);
        expect(row.querySelector('button[aria-label^="Unlock"]')).not.toBeNull();
    });

    test('with Pro active the alt text setting is the live toggle', () =>
    {
        (window as unknown as { translatewpPro?: unknown }).translatewpPro = { connected: true, entitled: true, installed: true, active: true, version: '1.0.0', upgradeUrl: '' };
        act(() => root.render(<TranslationsSettings key="pro" />));

        const input = altRow().querySelector('input') as HTMLInputElement;
        expect(input.disabled).toBe(false);
        expect(input.checked).toBe(true);
        expect(altRow().querySelector('button[aria-label^="Unlock"]')).toBeNull();
        delete (window as unknown as { translatewpPro?: unknown }).translatewpPro;
    });

    test('offers protected and private pages as a toggle that is off, and says their text stays on the site', () =>
    {
        const text = toggleText('Translate password-protected and private pages');
        expect(text).toContain('never sent');
        const toggle = Array.from(container.querySelectorAll('p'))
            .find(element => element.textContent === 'T:Translate password-protected and private pages')!
            .closest('.components-toggle-control')!.querySelector('input') as HTMLInputElement;
        expect(toggle.checked).toBe(false);
    });

    test('each behaviour toggle states its effect with an example', () =>
    {
        expect(toggleText('Enable units conversion')).toContain('8 km');
        expect(toggleText('Enable units conversion')).toContain('recalculated');
        expect(toggleText('Preserve HTML attributes')).toContain('placeholder');
        expect(toggleText('Translate image alt tags')).toContain('for example');
        expect(toggleText('Preserve branded terms')).toContain('for example');
        expect(toggleText('Localized date and time formats')).toContain('for example');
        expect(toggleText('Translate all public content')).toContain('for example');
    });

    test('offers comments, menus and widgets as content toggles, on by default, under the page areas network group', () =>
    {
        const areas = container.querySelector('[data-page-areas]') as HTMLElement;
        expect(areas).not.toBeNull();
        for (const title of ['Translate comments', 'Translate menus', 'Translate widgets']) {
            const toggle = Array.from(areas.querySelectorAll('p'))
                .find(element => element.textContent === `T:${title}`)!
                .closest('.components-toggle-control')!.querySelector('input') as HTMLInputElement;
            expect(toggle.checked).toBe(true);
            expect(toggleText(title).slice(`T:${title}`.length)).toMatch(/^T:/);
        }
    });

    test('there is no setting for who sees translations: everyone does', () =>
    {
        expect(container.querySelector('[data-audience]')).toBeNull();
        expect(container.textContent).not.toContain('Show translations only to these roles');
        expect(container.querySelector('[data-who-can-manage]')).not.toBeNull();
    });

    test('every content and behaviour label is translatable', () =>
    {
        const headings = Array.from(container.querySelectorAll('[data-translation-settings] h4'))
            .map(heading => heading.textContent)
            .filter(text => text?.startsWith('T:'));
        expect(headings).toEqual(['T:Content', 'T:Behaviour', 'T:Who can manage translations']);

        for (const title of [
            'Translate all public content',
            'Preserve HTML attributes',
            'Translate image alt tags',
            'Preserve branded terms',
            'Localized date and time formats',
            'Enable units conversion',
        ]) {
            const text = toggleText(title);
            expect(text).not.toBe('');
            // The description after the title is translatable too.
            expect(text.slice(`T:${title}`.length)).toMatch(/^T:/);
        }
    });

    test('places Content and Behaviour in a two-column desktop row', () =>
    {
        const row = container.querySelector('[data-content-behaviour]') as HTMLElement;
        expect(row.className).toContain('lg:grid-cols-2');
        // Side by side, the two cards stretch to one height.
        expect(row.className).not.toContain('items-start');
        expect(row.children).toHaveLength(2);
        expect(row.children[0].textContent).toContain('T:Content');
        expect(row.children[1].textContent).toContain('T:Behaviour');
    });

    test('the copy has no em dashes', () =>
    {
        expect(container.textContent).not.toContain('\u2014');
    });
});
