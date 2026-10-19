import { languages } from '@/shared/lib/languages';
import { flagEmoji } from '@/shared/lib/languages/flagEmoji';
import { getLanguageNameFromLocale } from '@/shared/lib/languages/languageName';

describe('Latin American Spanish', () =>
{
    it('is in the catalogue as es-419 with both names and no country flag', () =>
    {
        expect((languages as Record<string, { nameEnglish: string }>)['es-419']?.nameEnglish).toBe('Latin American Spanish');
        expect(getLanguageNameFromLocale('es-419', true)).toBe('Español de Latinoamérica');
        expect(flagEmoji('es-419')).toBe('');
    });
});

import { matchBrowserToSiteLocale } from '@/shared/lib/languages/browserLocaleMatch';

describe('browser matching for Latin American Spanish', () =>
{
    it('sends a Mexican or Colombian browser to es-419 when that is the Spanish the site offers', () =>
    {
        expect(matchBrowserToSiteLocale(['es-MX', 'en'], ['en', 'es-419'])).toBe('es-419');
        expect(matchBrowserToSiteLocale(['es-CO'], ['en', 'es-419'])).toBe('es-419');
    });

    it('prefers the exact variety when the site offers both', () =>
    {
        expect(matchBrowserToSiteLocale(['es-MX'], ['en', 'es-419', 'es-mx'])).toBe('es-mx');
        expect(matchBrowserToSiteLocale(['es-419'], ['en', 'es-mx', 'es-419'])).toBe('es-419');
    });
});
