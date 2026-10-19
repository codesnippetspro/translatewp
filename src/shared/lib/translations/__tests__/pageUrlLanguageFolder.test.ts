import { canonicalizePageUrl } from '@/shared/lib/translations/pageUrl';

describe('the page key of an address in a language folder', () =>
{
    beforeEach(() =>
    {
        Object.defineProperty(window, 'TranslateWP', {
            value: {
                context: { home_url: 'https://example.com' },
                options: { languages: { list: {
                    en: { locale: 'en', slug: 'en', active: true, default: true },
                    de: { locale: 'de', slug: 'de', active: true, default: false },
                } } },
            },
            configurable: true,
            writable: true,
        });
    });

    afterEach(() => { delete (window as { TranslateWP?: unknown }).TranslateWP; });

    it('is the key of the source page, so a translated address finds the same stored translations', () =>
    {
        expect(canonicalizePageUrl('https://example.com/de/about/')).toBe(canonicalizePageUrl('https://example.com/about/'));
        expect(canonicalizePageUrl('https://example.com/de/about/')).toBe('https://example.com/about');
        expect(canonicalizePageUrl('https://example.com/de/')).toBe('https://example.com/');
    });

    it('keeps a path that only starts like a language slug', () =>
    {
        expect(canonicalizePageUrl('https://example.com/design/')).toBe('https://example.com/design');
    });

    it('still canonicalises when the site data is not on the page', () =>
    {
        delete (window as { TranslateWP?: unknown }).TranslateWP;
        expect(canonicalizePageUrl('http://example.com/about/?lang=de#x')).toBe('https://example.com/about');
    });
});
