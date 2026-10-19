import type { CSSProperties } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import '@/shared/components/form/range/range.css';

export interface RangeProps
{
    label: string;
    beginningLabel: string;
    endingLabel: string;
    min: number;
    max: number;
    step: number;
    value: number;
    onChange: (value: number) => void;
}

export const Range = ({ label, beginningLabel, endingLabel, min, max, step, value, onChange }: RangeProps) =>
{
    const fill = max > min ? ((Math.min(max, Math.max(min, value)) - min) / (max - min)) * 100 : 0;

    return (
        <div className="flex flex-col gap-3">
            <input
                type="range"
                className="twp-range"
                min={min}
                max={max}
                step={step}
                value={value}
                aria-label={label}
                /* translators: 1: the slider's value, 2: its highest value. */
                aria-valuetext={sprintf(__('%1$d of %2$d', 'translate'), value, max)}
                style={{ '--twp-range-fill': `${fill}%` } as CSSProperties}
                onChange={(event) => onChange(Number(event.target.value))}
            />

            <div className="flex items-center w-full justify-between">
                <span className="text-sm text-translatewp-muted">{beginningLabel}</span>
                <span className="text-sm text-translatewp-muted">{endingLabel}</span>
            </div>
        </div>
    );
};
