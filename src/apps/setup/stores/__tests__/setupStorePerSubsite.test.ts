const loadSetupStore = async () =>
{
    jest.resetModules();
    const module = await import('@/apps/setup/stores/setupStore');
    return module;
};

describe('setupStore keeps its browser draft per admin path', () =>
{
    beforeEach(() =>
    {
        localStorage.clear();
        delete (window as { setupData?: unknown }).setupData;
    });

    it('one subsite\'s draft never pre-fills another subsite on the same origin', async () =>
    {
        window.history.replaceState(null, '', '/wp-admin/admin.php?page=translatewp');
        const root = await loadSetupStore();
        root.useSetupStore.getState().setLanguages(['de', 'en-us']);
        expect(localStorage.getItem(root.setupStorageKey())).toContain('en-us');

        window.history.replaceState(null, '', '/shop/wp-admin/admin.php?page=translatewp');
        const shop = await loadSetupStore();
        expect(shop.setupStorageKey()).not.toBe(root.setupStorageKey());
        expect(shop.useSetupStore.getState().languages).toEqual([]);
    });
});
