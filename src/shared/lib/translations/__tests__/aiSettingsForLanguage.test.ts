import { aiSettingsForLanguage } from '@/shared/lib/translations/aiSettingsForLanguage';

const base = {
    preserve_branded_terms: true, localized_date_time_formats: true, enable_units_conversion: true,
    context: '', writing_style: 'neutral', tone: 'friendly', formality: 5, creativity: 5,
    lexicon_terms: [
        { id: 1, term: 'zāle', description: 'the dance hall', languages: ['de'] },
        { id: 2, term: 'notebook', description: 'a sketchbook' },
        { id: 3, term: 'brand', description: 'keep', languages: [] },
    ],
} as never;

describe('aiSettingsForLanguage', () =>
{
    it('keeps terms scoped to the language or to no language, reduced to term and description', () =>
    {
        expect(aiSettingsForLanguage(base, 'de').lexicon_terms).toEqual([
            { term: 'zāle', description: 'the dance hall' },
            { term: 'notebook', description: 'a sketchbook' },
            { term: 'brand', description: 'keep' },
        ]);
        expect(aiSettingsForLanguage(base, 'it').lexicon_terms).toEqual([
            { term: 'notebook', description: 'a sketchbook' },
            { term: 'brand', description: 'keep' },
        ]);
    });

    it('leaves every other setting as it is', () =>
    {
        const scoped = aiSettingsForLanguage(base, 'it');
        expect(scoped.tone).toBe('friendly');
        expect(scoped.formality).toBe(5);
    });
});
