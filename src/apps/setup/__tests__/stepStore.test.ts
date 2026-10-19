import { useStepStore } from '@/apps/setup/stores/stepStore';

describe('stepStore', () =>
{
    test('defaults to connect on fresh load', () =>
    {
        expect(useStepStore.getState().step).toBe('connect');
    });
});