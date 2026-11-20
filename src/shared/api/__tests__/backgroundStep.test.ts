import apiFetch from '@wordpress/api-fetch';
import { runBackgroundStep } from '@/shared/api/admin';

jest.mock('@wordpress/api-fetch', () => ({ __esModule: true, default: jest.fn() }));
const fetchMock = apiFetch as jest.Mock;
const pending = { read: 1, push: 2, refill: 3, visitor: 1 };

describe('background step response boundary', () => {
    beforeEach(() => fetchMock.mockReset());
    it('passes the bounded step contract and abort signal through the admin seam', async () => {
        const body = { state: 'more', pending, v: 1 };
        fetchMock.mockResolvedValue(body);
        const signal = new AbortController().signal;
        await expect(runBackgroundStep(signal)).resolves.toEqual(body);
        expect(fetchMock).toHaveBeenCalledWith({ path: '/translatewp/v1/background/admin-step', method: 'POST', data: {}, signal });
    });
    it.each([null, {}, { languages: {} }, { state: 'more', v: 1 }, { state: 'unknown', pending, v: 1 }, { state: 'more', pending: { ...pending, visitor: -1 }, v: 1 }])('rejects an invalid shape without returning it to the view', async body => {
        fetchMock.mockResolvedValue(body);
        await expect(runBackgroundStep()).rejects.toThrow('Invalid background step response');
    });
    it('accepts the minimal paused contract with no work', async () => {
        fetchMock.mockResolvedValue({ state: 'paused', v: 1 });
        await expect(runBackgroundStep()).resolves.toEqual({ state: 'paused', pending: { read: 0, push: 0, refill: 0, visitor: 0 }, v: 1 });
    });
});
