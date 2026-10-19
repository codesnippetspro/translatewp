import { digitRuns, normalizeNumbers, substituteNumbers } from '@/shared/lib/translations/numericText';

describe('normalizeNumbers', () =>
{
    it.each([
        ['3 days 12:04:11 left', '# days #:#:# left'],
        ['20:46:03', '#:#:#'],
        ['1,250 visitors', '#,# visitors'],
        ['٢٠:٤٦', '#:#'],
        ['No digits here', 'No digits here'],
    ])('%s becomes %s', (text, expected) =>
    {
        expect(normalizeNumbers(text)).toBe(expected);
    });

    it('gives the same form to values that differ only in their numbers', () =>
    {
        expect(normalizeNumbers('3 days left')).toBe(normalizeNumbers('5 days left'));
        expect(normalizeNumbers('posted 2 minutes ago')).toBe(normalizeNumbers('posted 59 minutes ago'));
    });
});

describe('digitRuns', () =>
{
    it('lists every run of digits in order', () =>
    {
        expect(digitRuns('3 days 12:04:11')).toEqual(['3', '12', '04', '11']);
        expect(digitRuns('none')).toEqual([]);
    });
});

describe('substituteNumbers', () =>
{
    it('writes the source digits into the translation in order', () =>
    {
        expect(substituteNumbers('5 days left', '3 giorni rimasti')).toBe('5 giorni rimasti');
        expect(substituteNumbers('2 days 09:58:30 left', '3 giorni 12:04:11 rimanenti')).toBe('2 giorni 09:58:30 rimanenti');
    });

    it('returns the translation unchanged when the source has no digits', () =>
    {
        expect(substituteNumbers('Top ten', 'Top 10')).toBe('Top 10');
    });

    it('returns null when the run counts differ', () =>
    {
        expect(substituteNumbers('5 days left', 'cinque giorni rimasti')).toBeNull();
        expect(substituteNumbers('12:04', '12:04:00')).toBeNull();
    });
});
