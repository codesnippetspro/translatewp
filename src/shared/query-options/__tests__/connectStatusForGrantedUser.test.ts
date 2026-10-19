import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';

const mockStatus = jest.fn(async () => ({ connected: true, accountLabel: 'owner@example.com' }));
jest.mock('@/shared/api/connect', () => ({ getConnectStatus: () => mockStatus() }));

const run = () => (getConnectStatusQueryOptions().queryFn as () => Promise<Record<string, unknown>>)();

describe('the connection status the admin app reads', () =>
{
    beforeEach(() => mockStatus.mockClear());

    it('asks the site for an administrator', async () =>
    {
        (window as unknown as { TranslateWP: unknown }).TranslateWP = { context: { can_administer: true } };

        expect(await run()).toMatchObject({ accountLabel: 'owner@example.com' });
        expect(mockStatus).toHaveBeenCalledTimes(1);
    });

    it('never asks for a user who manages translations by grant, and carries no account details', async () =>
    {
        (window as unknown as { TranslateWP: unknown }).TranslateWP = { context: { can_administer: false } };
        (window as unknown as { translatewpPro: unknown }).translatewpPro = { connected: true, entitled: true, installed: true, active: true, version: '1.0.0' };

        const status = await run();

        expect(mockStatus).not.toHaveBeenCalled();
        expect(status).toMatchObject({ connected: true, accountLabel: '', registeredUrl: '', portalSiteUrl: '', pro: { entitled: true, installed: true, active: true } });
    });
});
