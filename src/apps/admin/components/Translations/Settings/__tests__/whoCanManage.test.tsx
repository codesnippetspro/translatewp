import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { WhoCanManage } from '@/apps/admin/components/Translations/Settings/WhoCanManage';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockGet = jest.fn();
const mockSaveUsers = jest.fn();
const mockSaveRoles = jest.fn();
jest.mock('@/shared/api/admin', () => ({
    getTranslationManagers: (...args: unknown[]) => mockGet(...args),
    saveTranslationManagers: (...args: unknown[]) => mockSaveUsers(...args),
    saveRolePermissions: (...args: unknown[]) => mockSaveRoles(...args),
}));

const card = {
    users: [{ id: 9, name: 'Olu Other', email: 'olu@example.com', role: 'shop_manager', granted: true }],
    roles: { manage_settings: { editor: true } },
    roleList: [{ slug: 'editor', name: 'Editor' }, { slug: 'author', name: 'Author' }],
    editable: true,
};

let root: Root | null = null;

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () =>
    {
        root?.render(
            <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
                <WhoCanManage />
            </QueryClientProvider>
        );
    });
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
    return container;
};

const cell = (container: HTMLElement, role: string, column: number) =>
    container.querySelector(`[data-role-row="${role}"]`)?.querySelectorAll('input[type="checkbox"]')[column] as HTMLInputElement;

beforeEach(() =>
{
    mockGet.mockReset().mockResolvedValue(card);
    mockSaveRoles.mockReset().mockImplementation(async (roles: unknown) => ({ ...card, roles }));
    mockSaveUsers.mockReset().mockResolvedValue(card);
    window.TranslateWP = { context: { can_administer: true } } as any;
});

afterEach(async () =>
{
    await act(async () => { root?.unmount(); });
    root = null;
    document.body.innerHTML = '';
});

describe('Translations > Settings: who can manage translations', () =>
{
    it('lists the four capabilities as columns, the administrator first and locked, then each role', async () =>
    {
        const container = await render();
        const scrollRegion = container.querySelector('[role="region"]') as HTMLElement;
        expect(scrollRegion.getAttribute('aria-label')).toBe('Who can manage translations');
        expect(scrollRegion.tabIndex).toBe(0);
        expect(scrollRegion.querySelector('table')).not.toBeNull();
        expect(Array.from(container.querySelectorAll('thead th')).map(th => th.textContent)).toEqual(['Role', 'Edit translations', 'Retranslate', 'Manage settings', 'Manage the Lexicon']);
        expect(Array.from(container.querySelectorAll('[data-role-row]')).map(row => row.getAttribute('data-role-row'))).toEqual(['administrator', 'editor', 'author']);
        const admin = Array.from(container.querySelectorAll('[data-role-row="administrator"] input')) as HTMLInputElement[];
        expect(admin.every(input => input.checked && input.disabled)).toBe(true);
        expect(cell(container, 'editor', 2).checked).toBe(true);
        expect(cell(container, 'editor', 0).checked).toBe(false);
    });

    it('saves the whole map when a box is ticked', async () =>
    {
        const container = await render();
        await act(async () => { cell(container, 'author', 1).click(); });
        expect(mockSaveRoles).toHaveBeenCalledWith({
            edit_translations: { editor: false, author: false },
            retranslate: { editor: false, author: true },
            manage_settings: { editor: true, author: false },
            manage_lexicon: { editor: false, author: false },
        });
    });

    it('keeps the named users on the same card, saying what they get', async () =>
    {
        const container = await render();
        expect(container.textContent).toContain('Also these users');
        expect(container.textContent).toContain('Olu Other');
        expect(container.textContent).toContain('the settings and the Lexicon');
    });

    it('shows the network value read-only when the network locks it', async () =>
    {
        mockGet.mockResolvedValue({ ...card, editable: false });
        const container = await render();
        expect(cell(container, 'editor', 2).disabled).toBe(true);
        expect(container.textContent).toContain('Managed by your network admin');
    });

    it('is not shown to someone who is not an administrator', async () =>
    {
        window.TranslateWP = { context: { can_administer: false } } as any;
        const container = await render();
        expect(container.querySelector('[data-who-can-manage]')).toBeNull();
        expect(mockGet).not.toHaveBeenCalled();
    });
});
