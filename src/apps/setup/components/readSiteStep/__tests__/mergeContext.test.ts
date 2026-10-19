import { mergeContext } from '@/apps/setup/components/readSiteStep/mergeContext';

describe('merging a freshly read brief into the context field', () =>
{
    it('fills an empty field and replaces whatever was there, never stacking two briefs', () =>
    {
        expect(mergeContext('', '', 'A bakery in Ghent.')).toBe('A bakery in Ghent.');
        expect(mergeContext('A bakery in Ghent.', 'A bakery in Ghent.', 'A bakery in Ghent, open since 1980.')).toBe('A bakery in Ghent, open since 1980.');
        expect(mergeContext('Old brief.\nOld brief again.', '', 'New brief.')).toBe('New brief.');
        expect(mergeContext('Keep the brand name.\nA bakery in Ghent.', 'A bakery in Ghent.', 'A bakery, open since 1980.')).toBe('A bakery, open since 1980.');
    });

    it('leaves the field alone when the read found nothing to say', () =>
    {
        expect(mergeContext('Our own words.', '', '')).toBe('Our own words.');
        expect(mergeContext('Our own words.', '', '   ')).toBe('Our own words.');
    });
});
