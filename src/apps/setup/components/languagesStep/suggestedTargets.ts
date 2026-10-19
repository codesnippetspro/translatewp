import { isPickableLocale } from '@/shared/lib/languages';
import { resolveCatalogLocale } from '@/shared/lib/languages/catalogLocale';

// Most common target languages per source language. Static and offline by
// design: these are quick-add suggestions, never preselected defaults. Keys are
// the base language of the site's default locale; values are catalog locales.
const COMMON_TARGETS_BY_SOURCE: Record<string, string[]> = {
    en: [ 'es', 'fr', 'de', 'pt-br', 'it', 'zh-hans' ],
    es: [ 'en-us', 'pt-br', 'fr', 'de', 'it', 'zh-hans' ],
    fr: [ 'en-us', 'es', 'de', 'it', 'pt-br', 'nl' ],
    de: [ 'en-us', 'fr', 'es', 'it', 'nl', 'pl' ],
    pt: [ 'en-us', 'es', 'fr', 'de', 'it', 'zh-hans' ],
    it: [ 'en-us', 'es', 'fr', 'de', 'pt-br', 'nl' ],
    nl: [ 'en-us', 'de', 'fr', 'es', 'it', 'pl' ],
    ru: [ 'en-us', 'de', 'fr', 'es', 'zh-hans', 'uk' ],
    ja: [ 'en-us', 'zh-hans', 'ko', 'es', 'fr', 'de' ],
    zh: [ 'en-us', 'ja', 'ko', 'es', 'fr', 'de' ],
    pl: [ 'en-us', 'de', 'fr', 'es', 'it', 'ru' ],
};

const DEFAULT_TARGETS = [ 'en-us', 'es', 'fr', 'de', 'zh-hans', 'ar' ];


const baseLanguage = ( locale: string ): string => locale.split( '-' )[0];

// Preferred locales (the admin's profile language, then the browser's
// languages) come first, so an English site run by a Dutch admin suggests
// Dutch before the common targets. They are still suggestions only.
export const getSuggestedTargets = ( sourceLocale: string, preferredLocales: string[] = [] ): string[] =>
{
    const source = baseLanguage( sourceLocale );
    const candidates = COMMON_TARGETS_BY_SOURCE[ source ] ?? DEFAULT_TARGETS;
    const preferred = preferredLocales
        .map( ( locale ) => resolveCatalogLocale( locale ) )
        .filter( ( locale ): locale is string => null !== locale );

    return [ ...new Set( [ ...preferred, ...candidates ] ) ].filter(
        ( locale ) => isPickableLocale( locale ) && baseLanguage( locale ) !== source
    );
};
