import { rememberPendingPreset, takePendingPreset } from '@/shared/lib/pro/pendingPreset';

describe('the preset an install was started from', () =>
{
    afterEach(() => { jest.useRealTimers(); window.sessionStorage.clear(); });

    it('is read once', () =>
    {
        rememberPendingPreset({ kind: 'style', value: 'medical' });
        expect(takePendingPreset()).toEqual({ kind: 'style', value: 'medical' });
        expect(takePendingPreset()).toBeNull();
    });

    it('is dropped once it is older than a quarter of an hour', () =>
    {
        jest.useFakeTimers().setSystemTime(new Date('2026-10-17T10:00:00Z'));
        rememberPendingPreset({ kind: 'tone', value: 'playful' });
        jest.setSystemTime(new Date('2026-10-17T10:16:00Z'));
        expect(takePendingPreset()).toBeNull();
    });

    it('ignores anything that is not a style or tone choice', () =>
    {
        window.sessionStorage.setItem('translatewp-pending-pro-preset', JSON.stringify({ kind: 'switcher', value: 'x', at: Date.now() }));
        expect(takePendingPreset()).toBeNull();
        window.sessionStorage.setItem('translatewp-pending-pro-preset', '{not json');
        expect(takePendingPreset()).toBeNull();
    });
});
