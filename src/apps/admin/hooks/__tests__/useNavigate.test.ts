import { buildAdminUrl, useNavigate } from '@/apps/admin/hooks/useNavigate';

const setLocation = (search: string, hash = '') =>
{
    window.history.replaceState(null, '', `/wp-admin/admin.php${search}${hash}`);
};

describe('useNavigate route resolution', () =>
{
    afterEach(() => setLocation('?page=translatewp'));

    it('resolves the four pages', () =>
    {
        setLocation('?page=translatewp');
        expect(useNavigate().route).toBe('/');
        setLocation('?page=translatewp-lexicon');
        expect(useNavigate().route).toBe('/lexicon');
        setLocation('?page=translatewp-seo');
        expect(useNavigate().route).toBe('/seo');
        setLocation('?page=translatewp-settings', '#advanced');
        expect(useNavigate()).toMatchObject({ route: '/settings', tab: 'advanced' });
    });

    it('reads the tab from the hash, lower-cased', () =>
    {
        setLocation('?page=translatewp', '#Indexing');
        expect(useNavigate().tab).toBe('indexing');
    });
});

describe('admin URLs', () =>
{
    afterEach(() => setLocation('?page=translatewp'));

    it('builds a same-origin admin.php URL for a page and tab', () =>
    {
        setLocation('?page=translatewp');
        const target = new URL(buildAdminUrl('settings', 'languages'));
        expect(target.origin).toBe(window.location.origin);
        expect(target.pathname).toBe('/wp-admin/admin.php');
        expect(target.search).toBe('?page=translatewp-settings');
        expect(target.hash).toBe('#languages');
    });

    it('clears the hash when no tab is given and names the dashboard for the empty route', () =>
    {
        setLocation('?page=translatewp-settings', '#languages');
        const target = new URL(buildAdminUrl(''));
        expect(target.search).toBe('?page=translatewp');
        expect(target.hash).toBe('');
    });
});
