import { applyDocumentLanguage, isRtlLocale } from '@/shared/lib/languages/textDirection';

describe('text direction', () =>
{
    it('knows the right-to-left languages by primary subtag', () =>
    {
        expect(isRtlLocale('ar')).toBe(true);
        expect(isRtlLocale('ar-EG')).toBe(true);
        expect(isRtlLocale('he_IL')).toBe(true);
        expect(isRtlLocale('fa')).toBe(true);
        expect(isRtlLocale('de')).toBe(false);
        expect(isRtlLocale('en-us')).toBe(false);
    });

    it('sets the document direction with the language and clears it on the way back', () =>
    {
        document.documentElement.removeAttribute('dir');
        applyDocumentLanguage('ar');
        expect(document.documentElement.lang).toBe('ar');
        expect(document.documentElement.getAttribute('dir')).toBe('rtl');

        applyDocumentLanguage('de');
        expect(document.documentElement.lang).toBe('de');
        expect(document.documentElement.getAttribute('dir')).toBe('ltr');
    });

    it('leaves a document without direction alone for a left-to-right language', () =>
    {
        document.documentElement.removeAttribute('dir');
        applyDocumentLanguage('it');
        expect(document.documentElement.hasAttribute('dir')).toBe(false);
    });
});
