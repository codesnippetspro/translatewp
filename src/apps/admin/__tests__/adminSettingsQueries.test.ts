import { adminSettingsQueries } from '@/apps/admin/Admin';

const mockGet = jest.fn(async (option: string) => ({ option }));
jest.mock('@/shared/api/admin', () => ({
    ...jest.requireActual('@/shared/api/admin'),
    getAdminData: (option: string) => mockGet(option),
}));

const run = async (administers: boolean) =>
{
    mockGet.mockClear();
    for (const query of adminSettingsQueries(administers))
    {
        await (query.queryFn as () => Promise<unknown>)();
    }
    return mockGet.mock.calls.map(call => call[0]);
};

describe('the settings groups the admin app loads', () =>
{
    it('asks for every group for an administrator', async () =>
    {
        expect(await run(true)).toEqual(['languages.list', 'languages.switcher', 'languages.seo', 'translations.settings', 'translations.context', 'translations.style', 'setup', 'advanced']);
    });

    it('never asks for an administrator group for a user who manages translations by grant', async () =>
    {
        expect(await run(false)).toEqual(['languages.list', 'languages.switcher', 'translations.settings', 'translations.context', 'translations.style']);
        expect(adminSettingsQueries(false)).toHaveLength(8);
    });
});
