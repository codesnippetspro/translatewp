import { persistedQueryStorageKey, queryPersistDehydrateOptions } from '@/shared/lib/queryPersistOptions';

test('does not dehydrate mutations to offline storage', () =>
{
    expect(queryPersistDehydrateOptions.shouldDehydrateMutation?.({} as never)).toBe(false);
});

test('dehydrates the display lists but never the settings the form initialises from', () =>
{
    expect(queryPersistDehydrateOptions.shouldDehydrateQuery?.({
        queryKey: ['indexPages'],
        state: { status: 'success' },
    } as never)).toBe(true);
    expect(queryPersistDehydrateOptions.shouldDehydrateQuery?.({
        queryKey: ['getAdminData', 'setup'],
        state: { status: 'success' },
    } as never)).toBe(false);
    expect(queryPersistDehydrateOptions.shouldDehydrateQuery?.({
        queryKey: ['dashboardMetrics'],
        state: { status: 'success' },
    } as never)).toBe(false);
});

test('keys the stored copy by the admin path so subsites on one origin never share it', () =>
{
    window.history.replaceState(null, '', '/shop/wp-admin/admin.php?page=translatewp');
    expect(persistedQueryStorageKey()).toBe('translatewp-admin-query:/shop/wp-admin/admin.php');
    window.history.replaceState(null, '', '/wp-admin/admin.php?page=translatewp');
    expect(persistedQueryStorageKey()).toBe('translatewp-admin-query:/wp-admin/admin.php');
});

test('never dehydrates the connect status so a stale rejected state cannot rehydrate', () =>
{
    expect(queryPersistDehydrateOptions.shouldDehydrateQuery?.({
        queryKey: ['connectStatus'],
        state: { status: 'success' },
    } as never)).toBe(false);
});

test('never dehydrates the account quota: it is an account figure, not a display list of this site', () =>
{
    expect(queryPersistDehydrateOptions.shouldDehydrateQuery?.({
        queryKey: ['quota'],
        state: { status: 'success' },
    } as never)).toBe(false);
});
