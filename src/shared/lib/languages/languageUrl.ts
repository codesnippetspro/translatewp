/**
 * The address of a page in a translation language: its language folder put in
 * front of the page path, /de/about/ for the page at /about/. The default
 * language keeps the plain address. Mirrors Components/LanguageUrl.php, so the
 * browser and the server agree on every address.
 */
export type UrlLanguage = { locale: string; slug?: string; active?: boolean; default?: boolean };

const LANG_QUERY_PARAM = 'lang';

const slugOf = (language: UrlLanguage): string =>
    (language.slug || language.locale.replace(/_/g, '-')).toLowerCase();

const translationLanguages = (languages: UrlLanguage[]): UrlLanguage[] =>
    languages.filter(language => language.active && language.locale && !language.default);

const homePathOf = (homeUrl: string): string =>
{
    try
    {
        return new URL(homeUrl).pathname.replace(/\/+$/, '');
    }
    catch
    {
        return '';
    }
};

const splitPath = (pathname: string, languages: UrlLanguage[], homeUrl: string): { locale: string; path: string } =>
{
    const path = `/${pathname.replace(/^\/+/, '')}`;
    const homePath = homePathOf(homeUrl);
    if (homePath !== '' && !`${path}/`.startsWith(`${homePath}/`)) return { locale: '', path };

    const rest = path.slice(homePath.length).replace(/^\/+/, '');
    const cut = rest.indexOf('/');
    const first = (cut === -1 ? rest : rest.slice(0, cut)).toLowerCase();
    const match = first === '' ? undefined : translationLanguages(languages).find(language => slugOf(language) === first);
    if (!match) return { locale: '', path };

    return { locale: match.locale, path: `${homePath}/${cut === -1 ? '' : rest.slice(cut + 1)}` };
};

/** The translation language an address names, '' for a plain address. */
export const requestedLocaleOfPath = (pathname: string, languages: UrlLanguage[], homeUrl: string): string =>
    splitPath(pathname, languages, homeUrl).locale;

/** The path of the source page an address stands for. */
export const sourcePathOf = (pathname: string, languages: UrlLanguage[], homeUrl: string): string =>
    splitPath(pathname, languages, homeUrl).path;

/** The address of the same page in the given language. */
export const localizeUrl = (href: string, locale: string, languages: UrlLanguage[], homeUrl: string): string =>
{
    let url: URL;
    let home: URL;
    try
    {
        url = new URL(href);
        home = new URL(homeUrl);
    }
    catch
    {
        return href;
    }
    if (url.host.toLowerCase() !== home.host.toLowerCase()) return href;

    const homePath = homePathOf(homeUrl);
    const sourcePath = splitPath(url.pathname, languages, homeUrl).path;
    if (homePath !== '' && !`${sourcePath}/`.startsWith(`${homePath}/`)) return href;

    const target = translationLanguages(languages).find(language => language.locale === locale);
    const rest = `/${sourcePath.slice(homePath.length).replace(/^\/+/, '')}`;
    url.pathname = `${homePath}${target ? `/${slugOf(target)}` : ''}${rest}`;
    url.searchParams.delete(LANG_QUERY_PARAM);

    return url.toString();
};
