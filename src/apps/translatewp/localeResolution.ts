import type { LanguageItem } from '@/apps/translatewp/types';

export type LocaleResolution = {
    locale: string | null;
    source: 'query' | 'cookie' | null;
    staleCookie: boolean;
};

export const resolveRequestedLocale = (
    langParam: string | null,
    cookieLocale: string | null,
    languages: LanguageItem[],
    defaultLocale: string,
): LocaleResolution =>
{
    if (langParam)
    {
        const match = languages.find(
            (language) => language.locale === langParam || language.slug === langParam,
        );
        return { locale: match?.locale ?? defaultLocale, source: 'query', staleCookie: false };
    }

    if (cookieLocale)
    {
        const match = languages.find((language) => language.locale === cookieLocale);
        if (match)
        {
            return { locale: match.locale, source: 'cookie', staleCookie: false };
        }
        return { locale: defaultLocale, source: 'cookie', staleCookie: true };
    }

    return { locale: null, source: null, staleCookie: false };
};
