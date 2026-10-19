import {
    eventKindLabel,
    formatJobEventTime,
    normalizeJobEventKind,
} from '@/shared/lib/jobEvents';

describe('normalizeJobEventKind', () =>
{
    it('accepts known kinds and maps hostile values to unknown', () =>
    {
        expect(normalizeJobEventKind('quota_block')).toBe('quota_block');
        expect(normalizeJobEventKind('<script>alert(1)</script>')).toBe('unknown');
        expect(normalizeJobEventKind('')).toBe('unknown');
    });
});

describe('formatJobEventTime', () =>
{
    it('formats valid ISO timestamps and rejects hostile values', () =>
    {
        expect(formatJobEventTime('2026-08-14T22:53:19.000Z')).toContain('2026');
        expect(formatJobEventTime('not-a-date')).toBeNull();
        expect(formatJobEventTime('Invalid Date')).toBeNull();
    });
});

describe('eventKindLabel', () =>
{
    it('maps known and unknown kinds to safe labels', () =>
    {
        expect(eventKindLabel('quota_block')).toBe('Quota limit reached');
        expect(eventKindLabel('unknown')).toBe('Translation event');
    });
});
