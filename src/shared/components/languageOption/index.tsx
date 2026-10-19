import { getCountryFlagFromLocale, getLanguageNameFromLocale } from '@/shared/lib/languages';

interface LanguageOptionProps {
    countryCode: string;
}

export const LanguageOption = ({ countryCode }: LanguageOptionProps) =>
{
    return (
        <span className='flex items-center gap-2 text-translatewp-neutral! text-sm!'>
            {getCountryFlagFromLocale( countryCode )}
            {getLanguageNameFromLocale( countryCode )}
        </span>
    );
};
