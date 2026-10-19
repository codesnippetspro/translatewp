import { flagEmoji } from '@/shared/lib/languages/flagEmoji';

describe('emoji flags for the native select', () =>
{
    it('uses the region of a regional locale, the country of a plain language, and nothing for the unknown', () =>
    {
        expect(flagEmoji('en-us')).toBe('🇺🇸');
        expect(flagEmoji('pt-br')).toBe('🇧🇷');
        expect(flagEmoji('lv')).toBe('🇱🇻');
        expect(flagEmoji('ja')).toBe('🇯🇵');
        expect(flagEmoji('aau')).toBe('');
    });
});
