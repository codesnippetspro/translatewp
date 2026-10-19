import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { jest } from '@jest/globals';

const translated: string[] = [];

jest.mock('@wordpress/i18n', () => ({
    ...(jest.requireActual('@wordpress/i18n') as object),
    __: (text: string) =>
    {
        translated.push(text);
        return text;
    },
}));

// Stable references: fresh arrays on every render would re-run the drawer's
// reset effect forever.
const mockCategories = { data: [] };
const mockSettingsState = { settings: { 'languages.list': [{ locale: 'de_DE', active: true }] } };

jest.mock('@tanstack/react-query', () => ({
    queryOptions: (options: unknown) => options,
    useQuery: () => mockCategories,
    useMutation: () => ({ mutate: () => {}, isPending: false }),
    useQueryClient: () => ({ invalidateQueries: () => {} }),
}));

jest.mock('@/apps/admin/stores/settingsStore', () => ({
    useAdminSettingsStore: (selector: (state: unknown) => unknown) =>
        selector(mockSettingsState),
}));

jest.mock('@/shared/components/drawer', () => ({
    Drawer: ({ isOpen, title, children }: { isOpen: boolean; title?: string; children: React.ReactNode }) =>
        isOpen ? <section data-title={title}>{children}</section> : null,
}));

jest.mock('@/shared/components/form/multiselect', () => ({
    Multiselect: () => null,
}));

import { AddLexiconDrawer } from '@/apps/admin/components/Lexicon/AddLexiconDrawer';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root | null = null;
let container: HTMLDivElement | null = null;

afterEach(async () =>
{
    await act(async () =>
    {
        root?.unmount();
    });
    container?.remove();
    root = null;
    container = null;
    translated.length = 0;
});

const renderDrawer = async () =>
{
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () =>
    {
        root!.render(
            <AddLexiconDrawer
                isDrawerOpen
                setIsDrawerOpen={() => {}}
                drawerContext="add"
                editingTerm={null}
            />
        );
    });
    return container;
};

// Issue 39: the drawer confirms with Save, and its labels go through the
// translation functions.
describe('AddLexiconDrawer', () =>
{
    it("the drawer's actions are Cancel and Save", async () =>
    {
        const element = await renderDrawer();
        const labels = Array.from(element.querySelectorAll('button'))
            .map((button) => button.textContent?.trim())
            .filter((label) => label === 'Cancel' || label === 'Save' || label === 'Done');

        expect(labels).toEqual(['Cancel', 'Save']);
        expect(translated).toEqual(expect.arrayContaining(['Cancel', 'Save']));
        expect(element.textContent).not.toContain('Done');
    });

    it("the drawer's labels are translatable", async () =>
    {
        await renderDrawer();

        expect(translated).toEqual(expect.arrayContaining([
            'Add Term',
            'Your term',
            'Describe when and how this term should be interpreted...',
            'All translations',
            'Apply context to',
            'Categories',
            'What are categories?',
            'Filter categories',
            'No categories available',
            'Enter the term first; Save stays off until it has one.',
        ]));
    });
});
