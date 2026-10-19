import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { updateAdminData } from '@/shared/api/admin';

jest.mock('@/shared/api/admin', () => ({
    updateAdminData: jest.fn(),
}));

const cleanStore = () =>
{
    const state = useAdminSettingsStore.getState();
    const settings = JSON.parse(JSON.stringify(state.settings));
    settings['advanced'] = { delete_on_uninstall: false };
    settings['translations.style'] = { ...settings['translations.style'], type: 'neutral' };
    useAdminSettingsStore.setState({
        settings,
        persistedSettings: JSON.parse(JSON.stringify(settings)),
        hasChanges: false,
        isSaving: false,
        saveFailed: false,
        staleSettings: false,
        savedAt: null,
        revision: 1,
    } as never);
};

describe('settingsStore autosave support', () =>
{
    beforeEach(() =>
    {
        (updateAdminData as jest.Mock).mockReset();
        cleanStore();
    });

    it('an edit made while a save is in flight is still unsaved afterwards', async () =>
    {
        const store = useAdminSettingsStore;
        let finishSave: (value: unknown) => void = () => undefined;
        (updateAdminData as jest.Mock).mockImplementationOnce(() => new Promise((resolve) => { finishSave = resolve; }));

        store.getState().setSetting('advanced', previous => ({ ...previous, delete_on_uninstall: true }));
        const saving = store.getState().saveSettings();
        store.getState().setSetting('languages.seo', previous => ({ ...previous, sitemaps: !previous.sitemaps }));
        finishSave({ success: true, revision: 2 });
        await saving;

        expect(store.getState().persistedSettings['advanced'].delete_on_uninstall).toBe(true);
        expect(store.getState().hasChanges).toBe(true);
        expect(store.getState().persistedSettings['languages.seo'].sitemaps).not.toBe(store.getState().settings['languages.seo'].sitemaps);
    });

    it('a second edit to the key being saved stays unsaved after the save', async () =>
    {
        const store = useAdminSettingsStore;
        let finishSave: (value: unknown) => void = () => undefined;
        (updateAdminData as jest.Mock).mockImplementationOnce(() => new Promise((resolve) => { finishSave = resolve; }));

        store.getState().setSetting('translations.context', previous => ({ ...previous, text_context: 'A tea' }));
        const saving = store.getState().saveSettings();
        store.getState().setSetting('translations.context', previous => ({ ...previous, text_context: 'A tea shop.' }));
        finishSave({ success: true, revision: 2 });
        await saving;

        expect(store.getState().persistedSettings['translations.context'].text_context).toBe('A tea');
        expect(store.getState().hasChanges).toBe(true);
    });

    it('keeps the keys that saved when a later key in the same save fails', async () =>
    {
        const store = useAdminSettingsStore;
        (updateAdminData as jest.Mock)
            .mockResolvedValueOnce({ success: true, revision: 2 })
            .mockRejectedValueOnce(new Error('boom'));

        store.getState().setSetting('languages.seo', previous => ({ ...previous, sitemaps: !previous.sitemaps }));
        store.getState().setSetting('advanced', previous => ({ ...previous, delete_on_uninstall: true }));
        await store.getState().saveSettings();

        const state = store.getState();
        expect(state.saveFailed).toBe(true);
        expect(state.persistedSettings['languages.seo']).toEqual(state.settings['languages.seo']);
        expect(state.persistedSettings['advanced'].delete_on_uninstall).toBe(false);
        expect(state.revision).toBe(2);
    });

    it('revertKey restores only that key', () =>
    {
        const store = useAdminSettingsStore;
        store.getState().setSetting('translations.style', previous => ({ ...previous, type: 'legal' }));
        store.getState().setSetting('advanced', previous => ({ ...previous, delete_on_uninstall: true }));

        store.getState().revertKey('translations.style');

        const state = store.getState();
        expect(state.settings['translations.style']).toEqual(state.persistedSettings['translations.style']);
        expect(state.settings['advanced'].delete_on_uninstall).toBe(true);
        expect(state.hasChanges).toBe(true);
    });

    it('records when a save succeeded so the admin can say Saved', async () =>
    {
        const store = useAdminSettingsStore;
        (updateAdminData as jest.Mock).mockResolvedValue({ success: true, revision: 2 });

        store.getState().setSetting('advanced', previous => ({ ...previous, delete_on_uninstall: true }));
        await store.getState().saveSettings();

        expect(typeof store.getState().savedAt).toBe('number');
    });
});

describe('a custom field cleared again', () =>
{
    it('is not a change: an empty custom style equals a missing one, so no retranslation is asked', async () =>
    {
        const { translationAffectingChanges } = await import('@/apps/admin/stores/settingsStore');
        const store = useAdminSettingsStore;
        const state = store.getState();
        const persisted = JSON.parse(JSON.stringify(state.persistedSettings));
        const settings = JSON.parse(JSON.stringify(persisted));
        settings['translations.style'] = { ...settings['translations.style'], custom_style: '' };

        expect(translationAffectingChanges(settings, persisted)).toEqual([]);

        settings['translations.style'] = { ...settings['translations.style'], custom_style: 'Punchy' };
        expect(translationAffectingChanges(settings, persisted)).toEqual(['translations.style']);
    });
});
