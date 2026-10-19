import languagesData from '@/shared/lib/languages/languages.json';
import { matchBrowserToSiteLocale } from '@/shared/lib/languages/browserLocaleMatch';

const catalogLocales = Object.keys(languagesData);

export const resolveCatalogLocale = (locale: string): string | null =>
{
    return matchBrowserToSiteLocale([locale], catalogLocales);
};
