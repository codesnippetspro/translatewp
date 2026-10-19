import { truncateText } from '@/shared/lib/translations/truncateText';

describe('truncateText', () =>
{
    it('leaves text under the limit alone', () =>
    {
        expect(truncateText('Latvia 🇱🇻', 20)).toBe('Latvia 🇱🇻');
    });

    it('cuts at the limit when the boundary is a plain character', () =>
    {
        expect(truncateText('Lithuania Luxembourg', 8)).toBe('Lithuani');
        expect(truncateText('Lithuania Luxembourg', 10)).toBe('Lithuania ');
    });

    it('never leaves half of a surrogate pair at the cut', () =>
    {
        // "🇱🇺" is two code points of two units each; a cut of 3 would land
        // between the units of the first.
        const cut = truncateText('🇱🇺 Luxembourg', 3);
        expect(cut).toBe('🇱');
        expect(JSON.stringify(cut)).not.toMatch(/\\ud83c"$/);
        expect(() => JSON.parse(JSON.stringify(cut))).not.toThrow();
        expect(truncateText('ab🇱🇺', 3)).toBe('ab');
    });
});
