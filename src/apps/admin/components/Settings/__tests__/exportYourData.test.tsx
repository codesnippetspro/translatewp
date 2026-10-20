import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { jest } from '@jest/globals';

jest.mock('@tanstack/react-query', () => ({
    queryOptions: (options: unknown) => options,
    useQuery: () => ({ data: undefined }),
    useMutation: () => ({ mutate: () => {}, isPending: false, isError: false }),
    useQueryClient: () => ({ invalidateQueries: async () => {} }),
}));

import { SettingsAdvanced } from '@/apps/admin/components/Settings/Advanced';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const EXPORT_URL = 'https://example.com/wp-admin/admin-post.php?action=translatewp_export_site&_wpnonce=abc';

let root: Root | null = null;

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () => { root?.render(<SettingsAdvanced />); });
    return container;
};

const setContext = (context: Record<string, unknown>) =>
{
    (window as unknown as { TranslateWP: unknown }).TranslateWP = { context };
};

describe('Settings, Advanced: no export in v1', () =>
{
    afterEach(async () =>
    {
        await act(async () => { root?.unmount(); });
        root = null;
        document.body.innerHTML = '';
    });

    it('does not show a Your data section or an export action even when an export address is present', async () =>
    {
        setContext({ export_url: EXPORT_URL });
        const container = await render();

        expect(container.querySelector('[data-twp-export]')).toBeNull();
        expect(container.textContent).not.toContain('Your data');
        expect(container.textContent).not.toContain('Export your data');
    });
});
