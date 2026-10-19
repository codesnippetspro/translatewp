import { confirmRetranslation, contextChangedSinceLoad, isRestartedSetup } from '@/apps/setup/hooks/retranslationGate';
import { useConfirmStore } from '@/apps/setup/stores/confirmStore';

const mockApi = { getRetranslationEstimate: jest.fn(), getQuota: jest.fn() };

jest.mock('@/shared/api/admin', () => ({
    getRetranslationEstimate: (...args: unknown[]) => mockApi.getRetranslationEstimate(...args),
}));
jest.mock('@/shared/api/quota', () => ({
    getQuota: (...args: unknown[]) => mockApi.getQuota(...args),
}));

describe('a restarted wizard says what a new context costs', () =>
{
    afterEach(() =>
    {
        delete (window as { setupData?: unknown }).setupData;
        useConfirmStore.setState({ request: null });
    });

    it('treats a wizard opened on a finished site as restarted, and a fresh one as not', () =>
    {
        (window as { setupData?: unknown }).setupData = { defaultLanguage: 'nl', step: 'done', context: 'a bakery' };
        expect(isRestartedSetup()).toBe(true);
        expect(contextChangedSinceLoad('a bakery ')).toBe(false);
        expect(contextChangedSinceLoad('a bakery in Ghent')).toBe(true);

        (window as { setupData?: unknown }).setupData = { defaultLanguage: 'nl', step: 'context' };
        expect(isRestartedSetup()).toBe(false);
    });

    it('asks with the site figures and returns the answer', async () =>
    {
        mockApi.getRetranslationEstimate.mockResolvedValue({ pages: 321, languages: 2 });
        mockApi.getQuota.mockResolvedValue({ percentAvailable: 63, windowEndsAt: null, enforced: true });
        const request = jest.fn().mockResolvedValue(false);
        useConfirmStore.setState({ request });

        await expect(confirmRetranslation('scan')).resolves.toBe(false);

        const content = request.mock.calls[0][0] as { title: string; message: string; confirmLabel: string; tone?: string; isDestructive?: boolean };
        expect(content.title).toBe('Translate the site again');
        expect(content.message).toContain('321 pages in 2 translation languages');
        expect(content.message).not.toMatch(/token/i);
        expect(content.message).toContain('This counts 642 page translations towards your quota. 63% of it is available right now.');
        expect(content.message).not.toContain('stale');
        expect(content.confirmLabel).toBe('Read again and retranslate');
        expect(content.tone).toBe('proceed');
        expect(content.isDestructive).toBeUndefined();
    });

    it('lets the action run when no dialog host is mounted', async () =>
    {
        await expect(confirmRetranslation('finish')).resolves.toBe(true);
    });
});
