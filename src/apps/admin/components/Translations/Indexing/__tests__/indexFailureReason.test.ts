import { elapsedLabel, indexFailureReason } from '@/apps/admin/components/Translations/Indexing/indexProgressMessage';

describe('index failure reasons', () =>
{
    it('explains the provider and transport codes in plain words', () =>
    {
        expect(indexFailureReason('byok_invalid_key')).toContain('rejected your API key');
        expect(indexFailureReason('pro_required')).toContain('Pro addon');
        expect(indexFailureReason('job_create_failed')).toContain('could not open a job');
        expect(indexFailureReason('http_request_failed')).toContain('outgoing requests');
        expect(indexFailureReason('invalid_stored_payload')).toContain('unreadable');
    });

    it('keeps an unknown code visible inside a sentence and handles a missing one', () =>
    {
        expect(indexFailureReason('weird_code')).toBe('The translation failed (weird_code). Retranslate to try again.');
        expect(indexFailureReason('')).toBe('No reason was recorded.');
        expect(indexFailureReason(undefined)).toBe('No reason was recorded.');
    });
});

describe('elapsed label', () =>
{
    it('shows seconds under a minute and minutes with padded seconds above', () =>
    {
        expect(elapsedLabel(42)).toBe('42 s');
        expect(elapsedLabel(125)).toBe('2 min 05 s');
    });
});
