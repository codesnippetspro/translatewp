import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { updateAdminData } from '@/shared/api/admin';

jest.mock('@/shared/api/admin', () => ({
    updateAdminData: jest.fn(),
}));

const dirtyTwoKeys = () =>
{
    const store = useAdminSettingsStore;
    const state = store.getState();
    store.setState({
        settings: {
            ...state.settings,
            'translations.context': { ...state.settings['translations.context'], context: 'A tea shop.' },
            'advanced': { ...state.settings['advanced'], delete_on_uninstall: true },
        },
        persistedSettings: {
            ...JSON.parse(JSON.stringify(state.persistedSettings)),
            'translations.context': { ...state.persistedSettings['translations.context'], context: '' },
            'advanced': { ...state.persistedSettings['advanced'], delete_on_uninstall: false },
        },
        hasChanges: true,
        isSaving: false,
        saveFailed: false,
        staleSettings: false,
        revision: 4,
    } as never);
    return store;
};

describe('settingsStore revision guard', () =>
{
    beforeEach(() =>
    {
        (updateAdminData as jest.Mock).mockReset();
    });

    it('sends the loaded revision with the first save and the returned one with the next', async () =>
    {
        const store = dirtyTwoKeys();
        (updateAdminData as jest.Mock)
            .mockResolvedValueOnce({ success: true, revision: 5 })
            .mockResolvedValueOnce({ success: true, revision: 6 });

        const saved = await store.getState().saveSettings();

        expect(saved.sort()).toEqual(['advanced', 'translations.context']);
        const calls = (updateAdminData as jest.Mock).mock.calls;
        expect(calls[0][2]).toBe(4);
        expect(calls[1][2]).toBe(5);
        expect(store.getState().revision).toBe(6);
        expect(store.getState().staleSettings).toBe(false);
        expect(store.getState().hasChanges).toBe(false);
    });

    it('marks the form stale and keeps the draft when another tab saved first', async () =>
    {
        const store = dirtyTwoKeys();
        (updateAdminData as jest.Mock).mockRejectedValue({ code: 'settings_changed', data: { status: 409, revision: 9 } });

        const saved = await store.getState().saveSettings();

        expect(saved).toEqual([]);
        expect(store.getState().staleSettings).toBe(true);
        expect(store.getState().saveFailed).toBe(false);
        expect(store.getState().revision).toBe(9);
        expect(store.getState().hasChanges).toBe(true);
        expect(store.getState().settings['translations.context'].context).toBe('A tea shop.');
        expect(store.getState().isSaving).toBe(false);
    });
});

describe('reloading after another tab saved', () =>
{
    it('keeps the edits of this tab on top of the freshly loaded settings, and marks them unsaved', () =>
    {
        const store = useAdminSettingsStore;
        const state = store.getState();
        store.setState({
            isInitialized: true,
            staleSettings: true,
            settings: { ...state.settings, 'languages.switcher': { ...state.settings['languages.switcher'], pending_message: 'Un momento' } },
            persistedSettings: JSON.parse(JSON.stringify(state.settings)),
            hasChanges: true,
        } as never);

        store.getState().beginReload();
        expect(store.getState().isInitialized).toBe(false);

        store.getState().initializeFromApiData({
            languagesList: [], switcher: { show_flag: false, pending_message: '' }, seo: {}, translationsSettings: {}, translationsContext: {},
            translationsStyle: {}, setup: {}, advanced: {},
        });

        const after = store.getState();
        expect(after.isInitialized).toBe(true);
        expect(after.staleSettings).toBe(false);
        expect(after.persistedSettings['languages.switcher'].show_flag).toBe(false);
        expect(after.persistedSettings['languages.switcher'].pending_message).toBe('');
        expect(after.settings['languages.switcher'].show_flag).toBe(false);
        expect(after.settings['languages.switcher'].pending_message).toBe('Un momento');
        expect(after.hasChanges).toBe(true);
    });

    it('drops nothing and has no unsaved changes when this tab had no edits', () =>
    {
        const store = useAdminSettingsStore;
        const state = store.getState();
        store.setState({ isInitialized: true, staleSettings: true, hasChanges: false, settings: JSON.parse(JSON.stringify(state.persistedSettings)) } as never);
        store.getState().beginReload();
        store.getState().initializeFromApiData({
            languagesList: [], switcher: { pending_message: 'Server' }, seo: {}, translationsSettings: {}, translationsContext: {},
            translationsStyle: {}, setup: {}, advanced: {},
        });
        expect(store.getState().settings['languages.switcher'].pending_message).toBe('Server');
        expect(store.getState().hasChanges).toBe(false);
    });
});
