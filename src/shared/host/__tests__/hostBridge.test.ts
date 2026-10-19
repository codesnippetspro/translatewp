import { applyFilters, removeFilter } from '@wordpress/hooks';

import { host } from '@/shared/host';

const EXPECTED_KEYS = [
    'version',
    'useAdminSettingsStore',
    'useQuery',
    'useMutation',
    'useQueryClient',
    'getConnectStatusQueryOptions',
    'Table',
    'TableCell',
    'TableHeaderRow',
    'TableRow',
    'Drawer',
    'getCountryFlagFromLocale',
    'getLanguageNameFromLocale',
    'ProLock',
    'ManagedByNetwork',
    'isGroupLocked',
    'ProBadge',
    'LanguageScopeSwitcher',
    'PresetChoice',
    'registerSlot',
    'confirmLanguageRetranslation',
];

describe('host bridge', () =>
{
    test('exposes every key a Pro panel resolves from the host', () =>
    {
        expect(host.version).toBe(3);
        expect(typeof host.LanguageScopeSwitcher).toBe('function');
        expect(typeof host.PresetChoice).toBe('function');
        expect(Object.keys(host).sort()).toEqual([...EXPECTED_KEYS].sort());

        for (const key of EXPECTED_KEYS)
        {
            expect(host[key as keyof typeof host]).toBeDefined();
        }
    });

    test('registerSlot registers a panel resolvable through the slot filter', () =>
    {
        const NAMESPACE = 'translatewp-tests/host-bridge';
        const Panel = () => null;

        host.registerSlot('aiProviders', Panel, NAMESPACE);

        expect(applyFilters('translatewp.slot.aiProviders', null)).toBe(Panel);

        removeFilter('translatewp.slot.aiProviders', NAMESPACE);
    });
});
