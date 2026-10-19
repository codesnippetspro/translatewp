import { resolveCatalogLocale } from '@/shared/lib/languages/catalogLocale';

describe('resolveCatalogLocale', () =>
{
    it.each([
        ['lv_LV', 'lv'],
        ['en_US', 'en-us'],
        ['zh_CN', 'zh-hans'],
        ['de_DE', 'de'],
    ])('maps WordPress locale %s to catalog locale %s', (wordpressLocale, expectedLocale) =>
    {
        expect(resolveCatalogLocale(wordpressLocale)).toBe(expectedLocale);
    });

    it('returns null when the locale has no catalog match', () =>
    {
        expect(resolveCatalogLocale('zz_ZZ')).toBeNull();
    });
});
