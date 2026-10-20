import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

const store = useAdminSettingsStore;

const loadStyle = (tone: string, formality: number) =>
{
    const state = store.getState();
    const settings = JSON.parse(JSON.stringify(state.settings));
    settings['translations.style'] = { ...settings['translations.style'], tone, formality, custom_tone: '' };
    store.setState({ isInitialized: false } as never);
    store.getState().initializeSettings(settings);
};

const style = () => store.getState().settings['translations.style'];

const chooseTone = (tone: string) =>
    store.getState().setSetting('translations.style', previous => ({ ...previous, tone, custom_tone: '' }));

describe('a tone preset moves the formality slider', () =>
{
    it.each([
        ['casual', 2],
        ['friendly', 4],
        ['professional', 7],
        ['formal', 9],
    ])('choosing %s sets formality %d', (tone, level) =>
    {
        loadStyle(tone === 'formal' ? 'casual' : 'formal', 5);

        chooseTone(tone);

        expect(style().tone).toBe(tone);
        expect(style().formality).toBe(level);
    });

    it('choosing Neutral sets the midpoint formality', () =>
    {
        loadStyle('casual', 2);

        chooseTone('neutral');

        expect(style().tone).toBe('neutral');
        expect(style().formality).toBe(5);
    });

    it.each(['playful', 'luxury'])('choosing %s leaves the slider where it is', (tone) =>
    {
        loadStyle('friendly', 6);

        chooseTone(tone);

        expect(style().formality).toBe(6);
    });

    it('leaves the slider where it is for a custom tone', () =>
    {
        loadStyle('formal', 8);

        store.getState().setSetting('translations.style', previous => ({ ...previous, custom_tone: 'Warm but exact' }));

        expect(style().formality).toBe(8);
    });

    it('keeps a formality the change sets itself', () =>
    {
        loadStyle('friendly', 4);

        store.getState().setSetting('translations.style', previous => ({ ...previous, tone: 'formal', formality: 6 }));

        expect(style().formality).toBe(6);
    });

    it('lets the owner drag the slider after choosing a tone', () =>
    {
        loadStyle('casual', 2);
        chooseTone('formal');

        store.getState().setSetting('translations.style', previous => ({ ...previous, formality: 7 }));

        expect(style().tone).toBe('formal');
        expect(style().formality).toBe(7);
    });

    it('never moves the slider when saved settings load', () =>
    {
        loadStyle('formal', 3);

        expect(style().tone).toBe('formal');
        expect(style().formality).toBe(3);
    });
});
