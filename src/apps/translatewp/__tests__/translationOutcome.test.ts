import { getTranslationOutcome } from '@/apps/translatewp/translationOutcome';

describe('getTranslationOutcome', () =>
{
    it.each([
        [{ failures: [], totalGroups: 3 }, 'success'],
        [{ failures: [{ groupId: 'a', error: 'translation_pending' }], totalGroups: 3 }, 'pending'],
        [{ failures: [{ groupId: 'a', error: 'rejected' }], totalGroups: 3 }, 'partial'],
        [{ failures: [{ groupId: 'a', error: 'rejected' }, { groupId: 'b', error: 'rejected' }, { groupId: 'c', error: 'rejected' }], totalGroups: 3 }, 'failure'],
    ] as const)('classifies $expectedResult results', (input, expectedResult) =>
    {
        expect(getTranslationOutcome(input)).toBe(expectedResult);
    });
});
