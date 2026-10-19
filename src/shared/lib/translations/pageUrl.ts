import { sourcePathOf, type UrlLanguage } from '@/shared/lib/languages/languageUrl';

const LANG_QUERY_PARAM = 'lang';

// The site's languages and home address, when the page carries them.
const siteLanguages = (): { languages: UrlLanguage[]; homeUrl: string } | null =>
{
    const site = (globalThis as { TranslateWP?: { context?: { home_url?: string }; options?: { languages?: { list?: unknown } } } }).TranslateWP;
    const list = site?.options?.languages?.list;
    const homeUrl = site?.context?.home_url;
    if (!homeUrl || !list || typeof list !== 'object') return null;

    return { languages: Object.values(list as Record<string, UrlLanguage>), homeUrl };
};

/**
 * Canonicalise a page URL the same way the server-side store keys pages:
 * force https, drop the trailing slash (bar the root), drop the language
 * folder and the old `lang` parameter, keep any other query, and drop the
 * fragment. Returns an empty string for an unparseable URL.
 */
export function canonicalizePageUrl(href: string): string
{
    try
    {
        const url = new URL(href);
        url.protocol = 'https:';
        url.hash = '';
        url.searchParams.delete(LANG_QUERY_PARAM);
        const site = siteLanguages();
        if (site) url.pathname = sourcePathOf(url.pathname, site.languages, site.homeUrl);
        if (url.pathname.length > 1) url.pathname = url.pathname.replace(/\/+$/, '');
        const query = url.searchParams.toString();
        url.search = query ? `?${query}` : '';
        return url.toString();
    }
    catch
    {
        return '';
    }
}
