import { __ } from '@wordpress/i18n';

import type { SwitcherSettings } from '@/apps/admin/stores/settingsStore';
import { OptionCards } from '@/apps/admin/components/Languages/Switcher/OptionCards';

type TriggerValue = SwitcherSettings['trigger'];

// Three Free display options for the closed switcher.
const TRIGGER_OPTIONS: { value: TriggerValue; label: string; description: string }[] = [
    {
        value: 'name_flag',
        label: __('Flag and name', 'translate'),
        description: __('The current language\'s flag and name, like English.', 'translate'),
    },
    {
        value: 'flag',
        label: __('Flag only', 'translate'),
        description: __('Just the current language\'s flag, without its name.', 'translate'),
    },
    {
        value: 'icon',
        label: __('Icon only', 'translate'),
        description: __('The TranslateWP icon, without a flag or name.', 'translate'),
    },
];

interface ShowSelectedLanguageControlProps
{
    value: TriggerValue;
    onChange: (value: TriggerValue) => void;
}

export const ShowSelectedLanguageControl = ({ value, onChange }: ShowSelectedLanguageControlProps) => (
    <OptionCards options={TRIGGER_OPTIONS} value={value} onChange={onChange} name="show-selected-language" label={__('Show selected language', 'translate')} />
);
