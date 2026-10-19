/**
 * Maps common browser / BCP-47 tags to keys used in languages.json when they differ.
 * Values are tried in order for each browser tag.
 */
const BROWSER_TO_LIBRARY_ALIASES: Record<string, string[]> = {
    // Chinese: browsers use region; our catalog uses script subtags
    'zh-cn': ['zh-hans'],
    'zh-sg': ['zh-hans'],
    'zh-tw': ['zh-hant'],
    'zh-hk': ['zh-hant'],
    'zh-mo': ['zh-hant'],
    // Legacy ISO codes still seen in the wild
    iw: ['he'],
    in: ['id'],
    ji: ['yi'],
};

const normalizeLanguageTagForMatch = ( tag: string ): string =>
    tag.trim().replace( /_/g, '-' ).toLowerCase();

/**
 * Normalizes a browser language tag for comparison: lowercase, hyphens, single segment rules.
 */
export const normalizeBrowserLanguageTag = ( tag: string ): string =>
    normalizeLanguageTagForMatch( tag );

/**
 * Picks the first site-configured locale that best matches the user's browser preferences.
 *
 * - Walks `navigator.languages` order so user preference order is respected.
 * - Applies small alias maps where BCP-47 regions differ from our catalog (e.g. zh-CN → zh-hans).
 * - Returns the site-configured locale value byte-exact (e.g. `pt-BR`, not `pt-br`).
 */
export const matchBrowserToSiteLocale = (
    browserPreferenceOrder: string[],
    siteLocalesOrdered: string[],
): string | null =>
{
    if ( !siteLocalesOrdered.length ) {
        return null;
    }

    const siteLocaleByNormalized = new Map(
        siteLocalesOrdered.map( ( locale ) => [normalizeLanguageTagForMatch( locale ), locale] ),
    );

    const tryResolve = ( normalized: string ): string | null =>
    {
        const exactSiteLocale = siteLocaleByNormalized.get( normalized );
        if ( exactSiteLocale ) {
            return exactSiteLocale;
        }

        for ( const alias of BROWSER_TO_LIBRARY_ALIASES[normalized] ?? [] ) {
            const aliasedSiteLocale = siteLocaleByNormalized.get( alias );
            if ( aliasedSiteLocale ) {
                return aliasedSiteLocale;
            }
        }

        const parts = normalized.split( '-' );
        const primary = parts[0];
        if ( !primary ) {
            return null;
        }

        // e.g. zh-Hans-CN → zh-hans-cn: prefer language-script (zh-hans) when present on site
        if ( parts.length >= 3 ) {
            const languageScript = `${ parts[0] }-${ parts[1] }`;
            const scriptSiteLocale = siteLocaleByNormalized.get( languageScript );
            if ( scriptSiteLocale ) {
                return scriptSiteLocale;
            }
        }

        const primarySiteLocale = siteLocaleByNormalized.get( primary );
        if ( primarySiteLocale ) {
            return primarySiteLocale;
        }

        const bySiteOrder = siteLocalesOrdered.filter(
            ( loc ) => normalizeLanguageTagForMatch( loc ) === primary
                || normalizeLanguageTagForMatch( loc ).startsWith( `${ primary }-` ),
        );
        if ( bySiteOrder.length ) {
            return bySiteOrder[0];
        }

        return null;
    };

    const seen = new Set<string>();

    for ( const raw of browserPreferenceOrder ) {
        const normalized = normalizeBrowserLanguageTag( raw );
        if ( !normalized || seen.has( normalized ) ) {
            continue;
        }
        seen.add( normalized );

        const resolved = tryResolve( normalized );
        if ( resolved ) {
            return resolved;
        }
    }

    return null;
};

export const getBrowserLanguagePreferenceOrder = (): string[] =>
{
    if ( typeof navigator === 'undefined' ) {
        return [];
    }
    const list = navigator.languages?.length
        ? [...navigator.languages]
        : navigator.language
            ? [navigator.language]
            : [];
    return list;
};
