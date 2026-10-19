import type { ReactNode } from 'react';

import { OPTION_CARD_CLASS, OPTION_GRID_CLASS } from '@/apps/admin/components/Languages/Switcher/optionGridClass';
import { OptionRow } from '@/apps/admin/components/Languages/Switcher/OptionRow';
import { ProLock } from '@/shared/components/pro/ProLock';
import { ProMarker } from '@/shared/components/pro/ProMarker';

export interface OptionCard<Value extends string>
{
    value: Value;
    label: string;
    description: string;
    example?: ReactNode;
    /** A Pro choice this site cannot make yet: shown, marked and locked. */
    locked?: { feature: string };
}

interface OptionCardsProps<Value extends string>
{
    options: OptionCard<Value>[];
    value: Value;
    onChange: (value: Value) => void;
    columns?: 1 | 2 | 3;
    name?: string;
}

// The one toggle-card control every "pick one of these" switcher setting uses.
export const OptionCards = <Value extends string>({ options, value, onChange, columns = 3, name }: OptionCardsProps<Value>) => (
    <div className={columns === 1 ? 'grid w-full min-w-0 grid-cols-1 gap-3 [&>*]:min-w-0' : columns === 2 ? 'grid w-full min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 [&>*]:min-w-0' : OPTION_GRID_CLASS} role="radiogroup" data-option-cards={name}>
        {options.map((option) =>
        {
            const card = (
            <button
                key={option.value}
                type="button"
                role="radio"
                aria-checked={value === option.value}
                disabled={Boolean(option.locked)}
                onClick={() => onChange(option.value)}
                className={`${OPTION_CARD_CLASS} cursor-pointer transition-colors ${
                    value === option.value
                        ? 'border-translatewp-primary! bg-translatewp-primary/5'
                        : 'border-translatewp-border! bg-white hover:border-translatewp-primary/40'
                }`}
            >
                <OptionRow label={option.label} description={option.description} isSelected={value === option.value} />
                {option.example && <div className="mt-3 flex items-center gap-2" data-option-example>{option.example}</div>}
            </button>
            );
            return option.locked
                // The badge takes the check mark's place, on the title's line.
                ? <ProLock key={option.value} feature={option.locked.feature} badge={<ProMarker />} badgeClassName="right-4 top-4" className="h-full">{card}</ProLock>
                : card;
        })}
    </div>
);
