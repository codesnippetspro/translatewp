import { setupSlugs, suggestSlug } from '@/shared/lib/languages/slugs';

describe('suggestSlug', () =>
{
    it.each([
        ['de-at', ['en', 'de'], 'de-at'],
        ['de', ['de'], 'de-de'],
        ['en', ['en'], 'en-us'],
        ['es', ['es'], 'es-es'],
        ['pt', ['pt'], 'pt-pt'],
        ['de', ['de', 'de-de'], 'de-2'],
        ['de', ['de', 'de-de', 'de-2'], 'de-3'],
        ['lv', ['lv'], 'lv-2'],
    ])('suggests for %s with %j taken: %s', (locale, taken, expected) =>
    {
        expect(suggestSlug(locale, taken)).toBe(expected);
    });
});

describe('setupSlugs', () =>
{
    it('gives each language of a new site its slug, the default first', () =>
    {
        expect(setupSlugs('en-us', ['de', 'de-at', 'es-es'])).toEqual({ 'en-us': 'en', de: 'de', 'de-at': 'de-at', 'es-es': 'es' });
    });

    it('gives plain German de-de when the default Austrian German holds de', () =>
    {
        expect(setupSlugs('de-at', ['de-at', 'de'])).toEqual({ 'de-at': 'de', de: 'de-de' });
    });

    it('gives en-gb its whole locale when the default en-us holds en', () =>
    {
        expect(setupSlugs('en-us', ['en-gb'])).toEqual({ 'en-us': 'en', 'en-gb': 'en-gb' });
    });
});
