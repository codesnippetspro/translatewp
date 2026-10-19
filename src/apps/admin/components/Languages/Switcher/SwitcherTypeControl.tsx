import { __ } from '@wordpress/i18n';

import type { SwitcherSettings } from '@/apps/admin/stores/settingsStore';
import { OptionCards } from '@/apps/admin/components/Languages/Switcher/OptionCards';
import { getProState, isProActive } from '@/shared/lib/pro/proState';

type SwitcherTypeValue = SwitcherSettings['type'];

interface SwitcherTypeOption
{
    value: SwitcherTypeValue;
    label: string;
    description: string;
    pro?: boolean;
}

const SWITCHER_TYPE_OPTIONS: SwitcherTypeOption[] = [
    {
        value: 'dropdown',
        label: __('Dropdown', 'translate'),
        description: __('A button opens a panel listing all languages.', 'translate'),
    },
    {
        value: 'native_select',
        label: __('Native dropdown', 'translate'),
        description: __('The same floating switcher, drawn as your browser\'s own dropdown.', 'translate'),
    },
    {
        value: 'side_tab',
        label: __('Side tab', 'translate'),
        description: __('A tab on the edge of the screen; the languages slide out from it. Switchers you place in the page stay dropdowns.', 'translate'),
        pro: true,
    },
];

interface SwitcherTypeControlProps
{
    value: SwitcherTypeValue;
    onChange: (value: SwitcherTypeValue) => void;
}

export const SwitcherTypeControl = ({ value, onChange }: SwitcherTypeControlProps) =>
{
    const proActive = isProActive(getProState());
    const options = SWITCHER_TYPE_OPTIONS.map(({ pro, ...option }) => pro && !proActive
        ? { ...option, locked: { feature: __('the side tab', 'translate') } }
        : option);

    return <OptionCards options={options} value={value} onChange={onChange} name="switcher-type" />;
};
