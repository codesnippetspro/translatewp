import { localizeUrl, requestedLocaleOfPath, type UrlLanguage } from '@/shared/lib/languages/languageUrl';

const languages: UrlLanguage[] = [
    { locale: 'en-us', slug: 'en', active: true, default: true },
    { locale: 'de', slug: 'de', active: true, default: false },
    { locale: 'pt-br', slug: 'pt-br', active: true, default: false },
    { locale: 'fr', slug: 'fr', active: false, default: false },
];
const HOME = 'https://example.com';

describe('the address of a page in a language', () =>
{
    it('puts a translated page under its language folder', () =>
    {
        expect(localizeUrl('https://example.com/about/', 'de', languages, HOME)).toBe('https://example.com/de/about/');
        expect(localizeUrl('https://example.com/', 'de', languages, HOME)).toBe('https://example.com/de/');
    });

    it('keeps the plain address for the default language', () =>
    {
        expect(localizeUrl('https://example.com/about/', 'en-us', languages, HOME)).toBe('https://example.com/about/');
        expect(localizeUrl('https://example.com/de/about/', 'en-us', languages, HOME)).toBe('https://example.com/about/');
    });

    it('moves an address from one language folder to another', () =>
    {
        expect(localizeUrl('https://example.com/de/about/', 'pt-br', languages, HOME)).toBe('https://example.com/pt-br/about/');
    });

    it('drops the old lang parameter and keeps the rest of the address', () =>
    {
        expect(localizeUrl('https://example.com/about/?lang=fr&ref=9#team', 'de', languages, HOME)).toBe('https://example.com/de/about/?ref=9#team');
    });

    it('leaves an address on another site and an inactive language alone', () =>
    {
        expect(localizeUrl('https://other.example/about/', 'de', languages, HOME)).toBe('https://other.example/about/');
        expect(localizeUrl('https://example.com/about/', 'fr', languages, HOME)).toBe('https://example.com/about/');
    });

    it('keeps the site folder first on a site that lives in a folder', () =>
    {
        expect(localizeUrl('https://example.com/shop/about/', 'de', languages, 'https://example.com/shop')).toBe('https://example.com/shop/de/about/');
        expect(localizeUrl('https://example.com/shop/de/about/', 'en-us', languages, 'https://example.com/shop/')).toBe('https://example.com/shop/about/');
    });

    it('does not take a page whose address only starts like a slug for a language folder', () =>
    {
        expect(localizeUrl('https://example.com/design/', 'en-us', languages, HOME)).toBe('https://example.com/design/');
        expect(requestedLocaleOfPath('/design/', languages, HOME)).toBe('');
    });

    it('reads the language an address names', () =>
    {
        expect(requestedLocaleOfPath('/de/about/', languages, HOME)).toBe('de');
        expect(requestedLocaleOfPath('/DE/', languages, HOME)).toBe('de');
        expect(requestedLocaleOfPath('/about/', languages, HOME)).toBe('');
        expect(requestedLocaleOfPath('/en/about/', languages, HOME)).toBe('');
        expect(requestedLocaleOfPath('/fr/about/', languages, HOME)).toBe('');
    });
});
