import { useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';

import type { IndexPageEntry } from '@/shared/api/admin';
import { getCountryFlagFromLocale, getLanguageNameFromLocale } from '@/shared/lib/languages';
import { localizeUrl } from '@/shared/lib/languages/languageUrl';
import { setupSlugs } from '@/shared/lib/languages/slugs';
import { languagePercent, readinessSummary, translationCellState } from '@/shared/lib/setupTranslationProgress';
import { useTranslationReadinessState } from '@/shared/hooks/useTranslationReadiness';
import { setupTranslationProgressQueryOptions } from '@/shared/query-options/setupTranslationProgress';
import { useSetupStore } from '@/apps/setup/stores/setupStore';

const languageName = (locale: string): string => getLanguageNameFromLocale(locale) || locale;

const pathOf = (address: string): string =>
{
    try
    {
        const url = new URL(address);
        // A page without a pretty address is /?page_id=12: the query is part of it.
        return url.pathname.replace(/\/+$/, '') + url.search;
    }
    catch
    {
        return address.replace(/\/+$/, '');
    }
};

// Compared by path: a row read before the site moved to https, or to another
// host, is still the homepage.
const samePage = (a: string, b: string): boolean => pathOf(a) === pathOf(b);

// Only pages indexing covers: a row for text a visitor met has no address.
export const homepageOf = (pages: IndexPageEntry[], homeUrl: string): IndexPageEntry | undefined =>
{
    const indexed = pages.filter(page => page.indexed !== false && page.url !== '');

    return indexed.find(page => page.objectType === 'home') ?? indexed.find(page => samePage(page.url, homeUrl));
};

export const previewUrl = (homeUrl: string, locale: string, defaultLanguage: string, languages: string[]): string =>
{
    const slugs = setupSlugs(defaultLanguage, languages);
    const list = Object.entries(slugs).map(([entry, slug]) => ({ locale: entry, slug, active: true, default: entry === defaultLanguage }));

    try
    {
        return new URL(localizeUrl(homeUrl, locale, list, homeUrl)).toString();
    }
    catch
    {
        return '';
    }
};

export const HomepageLinks = () =>
{
    const defaultLanguage = useSetupStore(state => state.defaultLanguage);
    const chosen = useSetupStore(state => state.languages);
    const spinePages = useSetupStore(state => state.spinePages);
    const languages = chosen.filter(locale => locale !== defaultLanguage);
    const { data } = useQuery(setupTranslationProgressQueryOptions(languages));
    const live = useTranslationReadinessState();

    const homeUrl = window.setupData?.homeUrl ?? '';
    const pages = data?.pages.pages.length ? data.pages.pages : spinePages;
    const home = homepageOf(pages, homeUrl);
    const summary = readinessSummary(live.status ?? data?.status, pages.filter(page => page.indexed !== false), languages);

    if (!languages.length || homeUrl === '') return null;

    return (
        <div className='ml-auto flex flex-wrap items-center justify-end gap-2' role='group' aria-label={__('See your homepage translated', 'translate')} data-homepage-links>
            <span className='text-sm text-translatewp-neutral'>{__('See your homepage translated', 'translate')}</span>
            {languages.map(locale =>
            {
                const ready = translationCellState(home?.languages[locale]) === 'translated';
                const href = ready ? previewUrl(homeUrl, locale, defaultLanguage, languages) : '';
                const percent = languagePercent(summary, locale);
                const name = languageName(locale);
                const title = ready
                    /* translators: %s: language name. */
                    ? sprintf(__('See your homepage in %s', 'translate'), name)
                    /* translators: 1: language name, 2: percent of page translations ready. */
                    : sprintf(__('%1$s: translating, %2$d%% ready', 'translate'), name, percent);
                const ring = { '--twp-ring-percent': `${ready ? 100 : percent}%` } as React.CSSProperties;
                const className = `setup-homepage-flag${ready ? ' is-ready' : ' is-running'}`;
                const flag = <span className='setup-homepage-flag-inner' aria-hidden='true'>{getCountryFlagFromLocale(locale)}</span>;

                return href !== '' ? (
                    <a
                        key={locale}
                        href={href}
                        target='_blank'
                        rel='noopener noreferrer'
                        className={className}
                        style={ring}
                        title={title}
                        aria-label={title}
                        data-homepage-link={locale}
                        data-link-state='ready'
                    >
                        {flag}
                    </a>
                ) : (
                    <span
                        key={locale}
                        aria-disabled='true'
                        title={title}
                        aria-label={title}
                        className={className}
                        style={ring}
                        data-homepage-link={locale}
                        data-link-state='translating'
                        data-link-percent={percent}
                    >
                        {flag}
                    </span>
                );
            })}
        </div>
    );
};
