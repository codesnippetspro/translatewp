import { resolveRequestedLocale } from '@/apps/translatewp/localeResolution';
import type { LanguageItem } from '@/apps/translatewp/types';

const languages: LanguageItem[] = [
    { locale: 'en', slug: 'en', active: true, default: false },
    { locale: 'es', slug: 'es', active: true, default: false },
    { locale: 'de', slug: 'de', active: true, default: true },
];

const DEFAULT_LOCALE = 'de';

describe('resolveRequestedLocale', () =>
{
    it('resolves a known ?lang to that locale', () =>
    {
        expect(resolveRequestedLocale('es', null, languages, DEFAULT_LOCALE)).toEqual({
            locale: 'es', source: 'query', staleCookie: false,
        });
    });

    it('resolves a ?lang given as a language slug to that locale', () =>
    {
        const slugLanguages: LanguageItem[] = [
            { locale: 'lv', slug: 'lv', active: true, default: true },
            { locale: 'en-us', slug: 'en', active: true, default: false },
        ];
        expect(resolveRequestedLocale('en', null, slugLanguages, 'lv')).toEqual({
            locale: 'en-us', source: 'query', staleCookie: false,
        });
    });

    it('still resolves a ?lang given as the full locale', () =>
    {
        const slugLanguages: LanguageItem[] = [
            { locale: 'lv', slug: 'lv', active: true, default: true },
            { locale: 'en-us', slug: 'en', active: true, default: false },
        ];
        expect(resolveRequestedLocale('en-us', null, slugLanguages, 'lv')).toEqual({
            locale: 'en-us', source: 'query', staleCookie: false,
        });
    });

    it('falls back to the default locale for an unknown ?lang', () =>
    {
        expect(resolveRequestedLocale('xx', null, languages, DEFAULT_LOCALE)).toEqual({
            locale: 'de', source: 'query', staleCookie: false,
        });
    });

    it('falls back to the default locale for a deactivated ?lang', () =>
    {
        const withoutInactive = languages.filter((language) => language.locale !== 'fr');
        expect(resolveRequestedLocale('fr', null, withoutInactive, DEFAULT_LOCALE)).toEqual({
            locale: 'de', source: 'query', staleCookie: false,
        });
    });

    it('resolves a known cookie locale', () =>
    {
        expect(resolveRequestedLocale(null, 'de', languages, DEFAULT_LOCALE)).toEqual({
            locale: 'de', source: 'cookie', staleCookie: false,
        });
    });

    it('falls back to the default locale and flags a stale cookie for a removed language', () =>
    {
        expect(resolveRequestedLocale(null, 'xx', languages, DEFAULT_LOCALE)).toEqual({
            locale: 'de', source: 'cookie', staleCookie: true,
        });
    });

    it('returns no locale when neither param nor cookie is present', () =>
    {
        expect(resolveRequestedLocale(null, null, languages, DEFAULT_LOCALE)).toEqual({
            locale: null, source: null, staleCookie: false,
        });
    });
});
