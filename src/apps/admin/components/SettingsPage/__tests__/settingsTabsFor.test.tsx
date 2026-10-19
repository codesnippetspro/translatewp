import { settingsTabsFor } from '@/apps/admin/components/SettingsPage';

jest.mock('@tanstack/react-query', () => ({
    queryOptions: (options: unknown) => options,
    useQuery: () => ({ data: undefined }),
    useMutation: () => ({ mutate: () => {}, isPending: false }),
    useQueryClient: () => ({ invalidateQueries: async () => {} }),
}));

describe('the settings tabs a user is shown', () =>
{
    it('gives an administrator every tab', () =>
    {
        expect(settingsTabsFor(true).map(tab => tab.name)).toEqual(['languages', 'translation', 'switcher', 'connection', 'advanced', 'ai-model']);
    });

    it('gives a user who manages translations by grant the translation and switcher settings only', () =>
    {
        expect(settingsTabsFor(false).map(tab => tab.name)).toEqual(['translation', 'switcher']);
    });
});
