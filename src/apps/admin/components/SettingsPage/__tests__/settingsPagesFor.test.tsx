import { settingsPagesFor } from '@/apps/admin/components/SettingsPage';

jest.mock('@tanstack/react-query', () => ({
    queryOptions: (options: unknown) => options,
    useQuery: () => ({ data: undefined }),
    useMutation: () => ({ mutate: () => {}, isPending: false }),
    useQueryClient: () => ({ invalidateQueries: async () => {} }),
}));

describe('the settings pages a user is shown', () =>
{
    it('gives an administrator every page', () =>
    {
        expect(settingsPagesFor(true).map(page => page.name)).toEqual(['languages', 'translation', 'switcher', 'connection', 'advanced', 'ai-model']);
    });

    it('gives a user who manages translations by grant the translation and switcher settings only', () =>
    {
        expect(settingsPagesFor(false).map(page => page.name)).toEqual(['translation', 'switcher']);
    });
});
