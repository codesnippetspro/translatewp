import languagesData from '@/shared/lib/languages/languages.json';
import { getLanguageNameFromLocale } from '@/shared/lib/languages/languageName';
import type { FlagIconStyle } from '@/shared/lib/languages/shapedFlagStyle';
import { FLAG_BORDER_RADIUS, getShapedFlagStyle } from '@/shared/lib/languages/shapedFlagStyle';
import '@/shared/lib/languages/styles.css';

export {
    getBrowserLanguagePreferenceOrder,
    matchBrowserToSiteLocale,
    normalizeBrowserLanguageTag,
} from '@/shared/lib/languages/browserLocaleMatch';
export { resolveCatalogLocale } from '@/shared/lib/languages/catalogLocale';
export { getLanguageNameFromLocale } from '@/shared/lib/languages/languageName';
export type { FlagIconStyle } from '@/shared/lib/languages/shapedFlagStyle';
export { getShapedFlagStyle } from '@/shared/lib/languages/shapedFlagStyle';

export const languages = languagesData;

// Plain English is kept so a site that stored it still shows a name, but a new
// language must name its region (American, British, ...).
const UNPICKABLE_LOCALES = new Set( [ 'en' ] );

export const isPickableLocale = ( locale: string ): boolean =>
    Object.prototype.hasOwnProperty.call( languages, locale ) && !UNPICKABLE_LOCALES.has( locale );

export const sortedLocales = Object.keys( languages ).filter( isPickableLocale ).sort( ( a, b ) =>
    ( getLanguageNameFromLocale( a ) ?? a ).localeCompare( getLanguageNameFromLocale( b ) ?? b )
);

type CountryFlagOptions = {
    additionalClasses?: string;
    iconStyle?: FlagIconStyle;
};

export const getCountryFlagFromLocale = (
    locale: string,
    options: string | CountryFlagOptions = '',
) =>
{
    if ( !languages.hasOwnProperty( locale as keyof typeof languages ) ) {
        return null;
    }

    const resolvedOptions: CountryFlagOptions = typeof options === 'string'
        ? { additionalClasses: options }
        : options;
    const additionalClasses = resolvedOptions.additionalClasses ?? '';
    const iconStyle = resolvedOptions.iconStyle ?? 'rectangle';

    if ( iconStyle === 'square' || iconStyle === 'circle' ) {
        const shapedStyle = getShapedFlagStyle( locale, iconStyle );
        if ( shapedStyle ) {
            return (
                <span
                    className={`lang-icon lang-icon-${ locale } ${ additionalClasses }`.trim()}
                    style={shapedStyle}
                />
            );
        }
    }

    return <span className={`lang-icon lang-icon-${ locale } shrink-0 ${ additionalClasses }`.trim()} style={{ borderRadius: FLAG_BORDER_RADIUS }}></span>;
}

export const formatLocale = ( locale: string ) =>
{
    return locale.split('-')[0] + (locale.split('-')[1] ? '_' + locale.split('-')[1].toUpperCase() : '');
}