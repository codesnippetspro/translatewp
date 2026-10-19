import { __ } from '@wordpress/i18n';
import { useId } from '@wordpress/element';

import { useQuery } from '@tanstack/react-query';

import { getProState, isProActive } from '@/shared/lib/pro/proState';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';
import {
    PRESET_EXAMPLES,
    PRESET_LABELS,
    PRESET_OPTIONS,
    type PresetKind,
    type PresetOption,
} from '@/apps/admin/components/Translations/Style/presetExamples';

export interface PresetChoiceProps
{
    kind: PresetKind;
    selected: string;
    onChange: (value: string) => void;
    // Appended after the presets in the same row; the Pro panel passes its
    // "Custom" option here. Extra options have no sample line.
    extraOptions?: PresetOption[];
}

export const CHIP_CLASS = 'inline-flex cursor-pointer items-center rounded-full border border-translatewp-border px-3 py-1 '
    + 'text-sm text-translatewp-muted! transition-colors hover:border-translatewp-primary '
    + 'has-checked:border-translatewp-primary has-checked:bg-translatewp-primary/10 has-checked:text-translatewp-neutral! '
    + 'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-translatewp-primary';

// A compact, start-aligned row of preset choices with a sample line for the
// chosen preset underneath. Shared with the Pro panel through the host bridge.
export const PresetChoice = ({ kind, selected, onChange, extraOptions = [] }: PresetChoiceProps) =>
{
    const name = `translatewp-preset-${kind}-${useId()}`;
    const { data: connectStatus } = useQuery(getConnectStatusQueryOptions());
    const proActive = isProActive(getProState(connectStatus));
    // Without Pro the Orbital presets sit on the Pro row with Custom (CustomOption).
    const options = [...PRESET_OPTIONS[kind].filter(option => !option.pro || proActive), ...extraOptions];
    const example = PRESET_EXAMPLES[kind][selected];

    return (
        <div className="flex flex-col gap-3">
            <div role="radiogroup" aria-label={PRESET_LABELS[kind]} className="flex flex-row flex-wrap justify-start gap-2">
                {options.map((option) => (
                    <label key={option.value} className={CHIP_CLASS}>
                        <input
                            type="radio"
                            className="sr-only"
                            name={name}
                            value={option.value}
                            checked={option.value === selected}
                            onChange={() => onChange(option.value)}
                        />
                        {option.label}
                    </label>
                ))}
            </div>
            {example && (
                <p data-preset-example className="m-0! text-sm italic text-translatewp-muted!" aria-live="polite">
                    <span className="not-italic font-medium">{__('Example:', 'translate')}</span> {example}
                </p>
            )}
        </div>
    );
};
