import { getLanguageNameFromLocale, isPickableLocale, sortedLocales } from '@/shared/lib/languages';
import { resolveCatalogLocale } from '@/shared/lib/languages/catalogLocale';
import { getSuggestedTargets } from '@/apps/setup/components/languagesStep/suggestedTargets';

describe('plain English in the language lists', () =>
{
    it('is not offered; the regional Englishes are', () =>
    {
        expect(sortedLocales).not.toContain('en');
        expect(isPickableLocale('en')).toBe(false);
        expect(sortedLocales).toEqual(expect.arrayContaining(['en-us', 'en-gb', 'en-au']));
        expect(isPickableLocale('en-us')).toBe(true);
    });

    it('keeps the other languages, including those without a region', () =>
    {
        expect(sortedLocales).toEqual(expect.arrayContaining(['es', 'fr', 'de', 'lv']));
    });

    it('still names a site that already stores it', () =>
    {
        expect(getLanguageNameFromLocale('en')).toBe('English');
    });

    it('is not suggested from an English browser', () =>
    {
        expect(getSuggestedTargets('lv', ['en'])).not.toContain('en');
        expect(getSuggestedTargets('lv', ['en'])).toContain('en-us');
    });

    it('stays the default language read from a WordPress English with no regional entry', () =>
    {
        expect(resolveCatalogLocale('en_ZA')).toBe('en');
        expect(resolveCatalogLocale('en_GB')).toBe('en-gb');
    });
});
