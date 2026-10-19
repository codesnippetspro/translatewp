import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { updateAdminData } from '@/shared/api/admin';

jest.mock('@/shared/api/admin', () => ({
    updateAdminData: jest.fn(),
}));

describe('settingsStore save failure', () =>
{
    it('preserves dirty settings and hasChanges, keeps persisted state, clears isSaving, and flags failure', async () =>
    {
        const store = useAdminSettingsStore;
        const dirty = { context: 'A store for developers.' };
        const persisted = { context: '' };
        store.setState({
            settings: { ...store.getState().settings, 'translations.context': dirty },
            persistedSettings: { ...store.getState().persistedSettings, 'translations.context': persisted },
            hasChanges: true,
            isSaving: false,
            saveFailed: false,
        } as never);
        (updateAdminData as jest.Mock).mockRejectedValue(new Error('boom'));

        const saved = await store.getState().saveSettings();

        expect(saved).toEqual([]);
        expect(store.getState().saveFailed).toBe(true);
        expect(store.getState().isSaving).toBe(false);
        expect(store.getState().hasChanges).toBe(true);
        expect(store.getState().settings['translations.context']).toEqual(dirty);
        expect(store.getState().persistedSettings['translations.context']).toEqual(persisted);
    });
});

describe('settingsStore refused save', () =>
{
    it('keeps the reason the site gave for refusing the save so the admin can act on it', async () =>
    {
        const store = useAdminSettingsStore;
        store.setState({
            settings: { ...store.getState().settings, 'translations.context': { context: 'Changed.' } },
            persistedSettings: { ...store.getState().persistedSettings, 'translations.context': { context: '' } },
            hasChanges: true,
            isSaving: false,
            saveFailed: false,
            saveError: null,
        } as never);
        (updateAdminData as jest.Mock).mockRejectedValue({
            code: 'duplicate_language_slug',
            message: 'Two languages use the address slug "de". Give each language its own slug, then save.',
        });

        await store.getState().saveSettings();

        expect(store.getState().saveFailed).toBe(true);
        expect(store.getState().saveError).toBe('Two languages use the address slug "de". Give each language its own slug, then save.');
    });

    it('keeps the reason when the network admin manages the settings', async () =>
    {
        const store = useAdminSettingsStore;
        store.setState({
            settings: { ...store.getState().settings, 'translations.style': { ...store.getState().settings['translations.style'], tone: 'formal' } },
            persistedSettings: { ...store.getState().persistedSettings, 'translations.style': { ...store.getState().persistedSettings['translations.style'], tone: 'friendly' } },
            hasChanges: true,
            isSaving: false,
            saveFailed: false,
            saveError: null,
        } as never);
        (updateAdminData as jest.Mock).mockRejectedValue({
            code: 'settings_locked',
            message: 'Your network admin manages Style and tone for this site.',
            data: { status: 403, group: 'style_tone' },
        });

        await store.getState().saveSettings();

        expect(store.getState().saveError).toBe('Your network admin manages Style and tone for this site.');
    });

    it('shows no reason for an unexpected failure', async () =>
    {
        const store = useAdminSettingsStore;
        store.setState({
            settings: { ...store.getState().settings, 'translations.context': { context: 'Changed again.' } },
            persistedSettings: { ...store.getState().persistedSettings, 'translations.context': { context: '' } },
            hasChanges: true,
            isSaving: false,
            saveFailed: false,
            saveError: null,
        } as never);
        (updateAdminData as jest.Mock).mockRejectedValue(new Error('boom'));

        await store.getState().saveSettings();

        expect(store.getState().saveFailed).toBe(true);
        expect(store.getState().saveError).toBeNull();
    });
});

describe('settingsStore advanced save', () =>
{
    test('persists delete_on_uninstall through the advanced settings round trip', async () =>
    {
        const store = useAdminSettingsStore;
        const cleanSettings = JSON.parse(JSON.stringify(store.getState().persistedSettings));
        cleanSettings.advanced = { delete_on_uninstall: false };

        store.setState({
            isSaving: false,
            saveFailed: false,
            hasChanges: false,
            settings: cleanSettings,
            persistedSettings: JSON.parse(JSON.stringify(cleanSettings)),
        } as never);
        (updateAdminData as jest.Mock).mockClear();
        (updateAdminData as jest.Mock).mockResolvedValue({ success: true });

        store.getState().setSetting('advanced', { delete_on_uninstall: true });
        const saved = await store.getState().saveSettings();

        expect(saved).toEqual(['advanced']);
        expect(updateAdminData).toHaveBeenCalledWith('advanced', { delete_on_uninstall: true });
        expect(store.getState().persistedSettings.advanced.delete_on_uninstall).toBe(true);
    });
});

describe('settingsStore discard', () =>
{
    it('returns the draft to the saved values and clears the change and failure flags', () =>
    {
        const store = useAdminSettingsStore;
        const persisted = { context: 'Saved context.' };
        store.setState({
            settings: { ...store.getState().settings, 'translations.context': { context: 'Typed but not saved.' } },
            persistedSettings: { ...store.getState().persistedSettings, 'translations.context': persisted },
            hasChanges: true,
            isSaving: false,
            saveFailed: true,
        } as never);

        store.getState().discardChanges();

        expect(store.getState().settings['translations.context']).toEqual(persisted);
        expect(store.getState().settings['translations.context']).not.toBe(store.getState().persistedSettings['translations.context']);
        expect(store.getState().hasChanges).toBe(false);
        expect(store.getState().saveFailed).toBe(false);
    });

    it('does nothing while a save is in flight', () =>
    {
        const store = useAdminSettingsStore;
        const dirty = { context: 'Still saving.' };
        store.setState({
            settings: { ...store.getState().settings, 'translations.context': dirty },
            hasChanges: true,
            isSaving: true,
        } as never);

        store.getState().discardChanges();

        expect(store.getState().settings['translations.context']).toEqual(dirty);
        expect(store.getState().hasChanges).toBe(true);
        store.setState({ isSaving: false } as never);
    });
});
