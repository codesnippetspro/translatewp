import type { Language } from '@/shared/components/languageTable';
import { baseSlugFromLocale, slugForNewLanguage } from '@/shared/lib/languages/slugs';

export function defaultSlugFromLocale(locale: string): string
{
    return baseSlugFromLocale(locale);
}

export function appendNewLanguages(previous: Language[], added: Language[]): Language[]
{
    const knownLocales = new Set(previous.map(language => language.locale));
    const takenSlugs = previous.map(language => language.slug);
    const fresh: Language[] = [];

    for (const language of added) {
        if (knownLocales.has(language.locale)) {
            continue;
        }
        knownLocales.add(language.locale);
        const slug = language.slug === defaultSlugFromLocale(language.locale)
            ? slugForNewLanguage(language.locale, takenSlugs)
            : language.slug;
        takenSlugs.push(slug);
        fresh.push({ ...language, slug });
    }

    return fresh.length === 0 ? previous : [...previous, ...fresh];
}

export function languageWithLocale(language: Language, locale: string): Language
{
    return {
        ...language,
        locale,
        slug: defaultSlugFromLocale(locale),
    };
}
