import languagesData from '@/shared/lib/languages/languages.json';

export const getLanguageNameFromLocale = (locale: string, native: boolean = false): string | undefined =>
{
    const language = languagesData[locale as keyof typeof languagesData];
    return native ? language?.nameNative : language?.nameEnglish;
};
