import { SWITCHER_TYPES } from '@/apps/admin/stores/settingsStore';

describe('switcher settings type', () =>
{
    it('is exactly the types the switcher renders: two Free and the Pro Side tab', () =>
    {
        expect([...SWITCHER_TYPES]).toEqual(['dropdown', 'native_select', 'side_tab']);
    });
});
