import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

describe('switcher chrome defaults lockstep', () =>
{
    it('admin store defaults match the issue #25 contract (offset 24, icon 24, padding 12)', () =>
    {
        const switcher = useAdminSettingsStore.getState().settings['languages.switcher'];

        expect(switcher.offset).toBe(24);
        expect(switcher.icon_size).toBe(24);
        expect(switcher.padding).toBe(12);
    });
});


it('preserves a dragged position when settings load from the server', () => {
    const position = { anchor: 'bottom-left' as const, x: 52, y: 152 };
    useAdminSettingsStore.getState().initializeFromApiData({
        languagesList: [], switcher: { floating_position: position }, seo: {},
        translationsSettings: {}, translationsContext: {}, translationsStyle: {}, setup: {}, advanced: {},
    });
    expect(useAdminSettingsStore.getState().settings['languages.switcher'].floating_position).toEqual(position);
});
