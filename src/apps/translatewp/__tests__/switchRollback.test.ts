import { switchRollbackTarget } from '@/apps/translatewp/switchRollback';

describe('switchRollbackTarget', () =>
{
    it('rolls back to the source language when nothing could be translated', () =>
    {
        expect(switchRollbackTarget('failure', 'en', 'ja')).toBe('en');
        expect(switchRollbackTarget('error', 'pt', 'ar')).toBe('pt');
    });

    it('keeps the new language on a full or partial result', () =>
    {
        expect(switchRollbackTarget('success', 'de', 'ja')).toBeNull();
        expect(switchRollbackTarget('partial', 'de', 'ja')).toBeNull();
    });

    it('does nothing when the languages are the same', () =>
    {
        expect(switchRollbackTarget('failure', 'ja', 'ja')).toBeNull();
    });
});
