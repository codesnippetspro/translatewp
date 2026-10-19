const mockApi = { getRetranslationEstimate: jest.fn(), getQuota: jest.fn() };
jest.mock('@/shared/api/admin', () => ({ getRetranslationEstimate: () => mockApi.getRetranslationEstimate() }));
jest.mock('@/shared/api/quota', () => ({ getQuota: () => mockApi.getQuota() }));

import { confirmLanguageRetranslation, useAdminConfirmStore } from '@/shared/lib/confirmLanguageRetranslation';
import type { ConfirmDialogContent } from '@/shared/components/confirmDialog';

describe('confirmLanguageRetranslation, the addon prompt before a model change', () =>
{
    afterEach(() => { useAdminConfirmStore.getState().setRequest(null); jest.clearAllMocks(); });

    it('states the languages, the pages and the quota share, and returns the decision', async () =>
    {
        mockApi.getRetranslationEstimate.mockResolvedValue({ pages: 28, languages: 11 });
        mockApi.getQuota.mockResolvedValue({ percentAvailable: 92 });
        const asked: ConfirmDialogContent[] = [];
        useAdminConfirmStore.getState().setRequest(async content => { asked.push(content); return false; });

        await expect(confirmLanguageRetranslation(['it'])).resolves.toBe(false);
        expect(asked[0].title).toBe('Translate those languages again?');
        expect(asked[0].message).toContain('Changing the model for Italian translates its 28 pages again.');
        expect(asked[0].message).toContain('28 page translations');
        expect(asked[0].message).toContain('92%');
        expect(asked[0].tone).toBe('proceed');
    });

    it('never makes the change without the dialog, and asks nothing when no language changed', async () =>
    {
        await expect(confirmLanguageRetranslation(['it'])).resolves.toBe(false);
        useAdminConfirmStore.getState().setRequest(async () => false);
        await expect(confirmLanguageRetranslation([])).resolves.toBe(true);
    });
});
