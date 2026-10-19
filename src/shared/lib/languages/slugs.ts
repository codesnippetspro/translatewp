const USUAL_REGIONS: Record<string, string> = {
    ar: 'ar-sa',
    da: 'da-dk',
    de: 'de-de',
    en: 'en-us',
    es: 'es-es',
    fr: 'fr-fr',
    it: 'it-it',
    ja: 'ja-jp',
    ko: 'ko-kr',
    nl: 'nl-nl',
    pl: 'pl-pl',
    pt: 'pt-pt',
    ru: 'ru-ru',
    sv: 'sv-se',
    zh: 'zh-cn',
};

export const fullSlugFromLocale = (locale: string): string => locale.trim().replace(/_/g, '-').toLowerCase();

export const baseSlugFromLocale = (locale: string): string => fullSlugFromLocale(locale).split('-')[0];

export const slugForNewLanguage = (locale: string, takenSlugs: string[]): string =>
{
    const base = baseSlugFromLocale(locale);

    return takenSlugs.includes(base) ? fullSlugFromLocale(locale) : base;
};

export const suggestSlug = (locale: string, takenSlugs: string[]): string =>
{
    const full = fullSlugFromLocale(locale);
    if (!takenSlugs.includes(full)) {
        return full;
    }

    const regional = full === baseSlugFromLocale(locale) ? USUAL_REGIONS[full] ?? '' : '';
    if (regional !== '' && !takenSlugs.includes(regional)) {
        return regional;
    }

    let number = 2;
    while (takenSlugs.includes(`${full}-${number}`)) {
        number++;
    }

    return `${full}-${number}`;
};

export const setupSlugs = (defaultLanguage: string, languages: string[]): Record<string, string> =>
{
    const slugs: Record<string, string> = {};
    const taken: string[] = [];

    for (const locale of [defaultLanguage, ...languages]) {
        if (locale === '' || locale in slugs) {
            continue;
        }
        const slug = slugForNewLanguage(locale, taken);
        slugs[locale] = taken.includes(slug) ? suggestSlug(locale, taken) : slug;
        taken.push(slugs[locale]);
    }

    return slugs;
};
