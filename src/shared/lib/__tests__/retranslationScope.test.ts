import { retranslationScope, retranslateOnSaveMessage } from '@/shared/lib/retranslationScope';

// The confirmation states the site's own pages and translation
// languages from the index, and no token figure.
describe('the retranslation confirmation speaks in pages and languages', () =>
{
    it('states pages times languages from the index and no token figure', () =>
    {
        const message = retranslateOnSaveMessage({ pages: 1204, languages: 3 });

        expect(message).not.toContain('stale');
        expect(message).toContain('1,204 pages in 3 translation languages');
        expect(message).toContain('This counts 3,612 page translations towards your quota.');
        expect(message).not.toMatch(/token/i);
        expect(retranslateOnSaveMessage({ pages: 2, languages: 2 }, { percentAvailable: 40, windowEndsAt: null, enforced: true })).toContain('40% of it is available right now.');
        expect(retranslateOnSaveMessage({ pages: 2, languages: 2 }, { percentAvailable: 100, windowEndsAt: null, enforced: false })).toContain('100% of it is available right now.');
        expect(retranslateOnSaveMessage({ pages: 2, languages: 2 }, null)).not.toContain('available right now');
    });

    it('uses the singular for one page and one language', () =>
    {
        expect(retranslationScope({ pages: 1, languages: 1 })).toBe('1 page in 1 translation language');
        expect(retranslateOnSaveMessage({ pages: 1, languages: 1 })).toBe(
            'These changes translate the site again with the new settings. The 1 page in 1 translation language on this site is translated again on its next visit. This counts 1 page translation towards your quota.'
        );
    });

    it('falls back to words without figures when the index has no translated page', () =>
    {
        expect(retranslationScope({ pages: 0, languages: 0 })).toBeNull();
        expect(retranslationScope(null)).toBeNull();

        const message = retranslateOnSaveMessage(null);
        expect(message).toContain('Pages are translated again on their next visit');
        expect(message).not.toMatch(/token/i);
    });
});
