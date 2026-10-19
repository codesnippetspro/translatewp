jest.mock('@wordpress/api-fetch', () => ({
    __esModule: true,
    default: jest.fn(async () => ({ requeued: 1 })),
}));

import apiFetch from '@wordpress/api-fetch';
import {
    retranslatePageMutationOptions,
    retranslateEverythingMutationOptions,
} from '@/shared/mutation-options/admin';

const apiFetchMock = apiFetch as unknown as jest.Mock;

beforeEach(() =>
{
    apiFetchMock.mockClear();
});

describe('retranslatePageMutationOptions', () =>
{
    it('posts the page identifiers to the retranslate-page endpoint', async () =>
    {
        const input = {
            objectType: 'page',
            objectId: 42,
            url: 'https://example.test/room',
            language: 'de',
        };

        await retranslatePageMutationOptions().mutationFn(input);

        expect(apiFetchMock).toHaveBeenCalledWith({
            path: '/translatewp/v1/indexing/retranslate-page',
            method: 'POST',
            data: input,
        });
    });
});

describe('retranslateEverythingMutationOptions', () =>
{
    it('posts the confirmation phrase to the retranslate-all endpoint', async () =>
    {
        await retranslateEverythingMutationOptions().mutationFn('RETRANSLATE');

        expect(apiFetchMock).toHaveBeenCalledWith({
            path: '/translatewp/v1/indexing/retranslate-all',
            method: 'POST',
            data: { confirm: 'RETRANSLATE' },
        });
    });
});
