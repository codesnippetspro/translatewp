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

describe('Settings, Advanced: export your data', () =>
{
    afterEach(async () =>
    {
        await act(async () => { root?.unmount(); });
        root = null;
        document.body.innerHTML = '';
    });

    it('offers the export as one link to the export action, with no Pro lock', async () =>
    {
        setContext({ export_url: EXPORT_URL });
        const container = await render();
        const section = container.querySelector('[data-twp-export]') as HTMLElement;

        const link = section.querySelector('a') as HTMLAnchorElement;
        expect(link.getAttribute('href')).toBe(EXPORT_URL);
        expect(link.textContent).toContain('Export your data');
        expect(section.textContent).not.toMatch(/\bPro\b/);
        expect(section.querySelector('[data-pro-lock], .twp-pro-badge')).toBeNull();
    });

    it('leaves the section out when the page has no export address', async () =>
    {
        setContext({});
        const container = await render();

        expect(container.querySelector('[data-twp-export]')).toBeNull();
    });
});
