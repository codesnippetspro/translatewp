import languagesData from '@/shared/lib/languages/languages.json';

export type RegionalNames = 'full' | 'short';

type CatalogueEntry = { nameNative?: string; nameEnglish?: string; region?: string };

type SwitcherLabelOptions = {
    nativeNames?: boolean;
    regionalNames?: RegionalNames;
    flagShown?: boolean;
};

const catalogue = languagesData as Record<string, CatalogueEntry>;

const nameOf = (locale: string, nativeNames: boolean): string | undefined =>
{
    const entry = catalogue[locale];

    return (nativeNames ? entry?.nameNative : undefined) || entry?.nameEnglish;
};

export const switcherLabel = (
    language: { locale: string; switcher_label?: string },
    { nativeNames = true, regionalNames = 'full', flagShown = false }: SwitcherLabelOptions = {}
): string =>
{
    if (language.switcher_label) {
        return language.switcher_label;
    }

    const region = catalogue[language.locale]?.region;
    if (regionalNames === 'short' && region) {
        const baseName = nameOf(language.locale.split('-')[0], nativeNames);
        if (baseName) {
            return flagShown ? baseName : `${baseName} (${region})`;
        }
    }

    return nameOf(language.locale, nativeNames) || language.locale;
};
