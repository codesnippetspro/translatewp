import { appendNewLanguages, defaultSlugFromLocale, languageWithLocale } from '@/apps/admin/components/Languages/Setup/languageWithLocale';
import type { Language } from '@/shared/components/languageTable';

const abau: Language = {
    locale: 'aau',
    slug: 'aau',
    active: true,
    default: false,
};

describe('defaultSlugFromLocale', () =>
{
    it('uses the language subtag before any region', () =>
    {
        expect(defaultSlugFromLocale('aau')).toBe('aau');
        expect(defaultSlugFromLocale('es-es')).toBe('es');
        expect(defaultSlugFromLocale('pt-br')).toBe('pt');
        expect(defaultSlugFromLocale('zh-hans')).toBe('zh');
    });
});

describe('languageWithLocale', () =>
{
    it('updates slug when switching away from the default Abau placeholder', () =>
    {
        expect(languageWithLocale(abau, 'es-es')).toEqual({
            locale: 'es-es',
            slug: 'es',
            active: true,
            default: false,
        });
    });

    it('preserves other language fields', () =>
    {
        const language: Language = {
            ...abau,
            switcher_label: 'Custom',
            default: true,
        };

        expect(languageWithLocale(language, 'fr-fr')).toEqual({
            locale: 'fr-fr',
            slug: 'fr',
            active: true,
            default: true,
            switcher_label: 'Custom',
        });
    });
});

describe('appendNewLanguages', () =>
{
    const language = (locale: string, slug: string, isDefault = false): Language => ({ locale, slug, active: true, default: isDefault });
    const slugs = (languages: Language[]) => languages.map(item => `${item.locale}:${item.slug}`);

    it('gives the second of de and de-at its whole locale', () =>
    {
        const withGerman = appendNewLanguages([language('en-us', 'en', true)], [language('de', 'de')]);
        expect(slugs(appendNewLanguages(withGerman, [language('de-at', 'de')]))).toEqual(['en-us:en', 'de:de', 'de-at:de-at']);
    });

    it('gives en-gb its whole locale when the default en-us holds en', () =>
    {
        expect(slugs(appendNewLanguages([language('en-us', 'en', true)], [language('en-gb', 'en')]))).toEqual(['en-us:en', 'en-gb:en-gb']);
    });

    it('assigns slugs across languages added together', () =>
    {
        expect(slugs(appendNewLanguages([language('en-us', 'en', true)], [language('de', 'de'), language('de-at', 'de'), language('es-es', 'es')])))
            .toEqual(['en-us:en', 'de:de', 'de-at:de-at', 'es-es:es']);
    });

    it('never renames a language already on the site', () =>
    {
        const next = appendNewLanguages([language('en-us', 'en', true), language('de-at', 'de')], [language('de', 'de')]);
        expect(next[1]).toEqual(language('de-at', 'de'));
        expect(next[2].slug).toBe('de');
    });

    it('keeps a slug the admin typed', () =>
    {
        expect(slugs(appendNewLanguages([language('de', 'de', true)], [language('de-at', 'oesterreich')]))).toEqual(['de:de', 'de-at:oesterreich']);
    });

    it('skips a locale the site already has', () =>
    {
        const previous = [language('en-us', 'en', true), language('de', 'de')];
        expect(appendNewLanguages(previous, [language('de', 'de')])).toBe(previous);
    });
});
