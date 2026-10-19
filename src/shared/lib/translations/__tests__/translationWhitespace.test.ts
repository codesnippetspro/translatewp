import {
    hasTranslatableWhitespaceText,
    trimTranslationWhitespace,
} from '@/shared/lib/translations/translationWhitespace';

const PARITY_CASES = [
    { input: '   ', expectedTrim: '', translatable: false },
    { input: '\u00A0', expectedTrim: '', translatable: false },
    { input: '\u00A0\u00A0', expectedTrim: '', translatable: false },
    { input: ' Hello ', expectedTrim: 'Hello', translatable: true },
    { input: '\u00A0Hello', expectedTrim: 'Hello', translatable: true },
    { input: 'Hello\nworld', expectedTrim: 'Hello\nworld', translatable: true },
] as const;

describe('translationWhitespace parity', () =>
{
    it.each(PARITY_CASES)('matches PHP trim semantics for $input', ({ input, expectedTrim, translatable }) =>
    {
        expect(trimTranslationWhitespace(input)).toBe(expectedTrim);
        expect(hasTranslatableWhitespaceText(input)).toBe(translatable);
    });

    it('preserves interior whitespace bytes unchanged', () =>
    {
        const value = 'Hello \u00A0 world';
        expect(trimTranslationWhitespace(value)).toBe(value);
    });
});
