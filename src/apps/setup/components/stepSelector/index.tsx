import { sprintf, __ } from '@wordpress/i18n';
import { Check } from 'lucide-react';

import { useSteps } from '@/apps/setup/hooks/useSteps';

export const StepSelector = () =>
{
    const { steps, currentIndex } = useSteps();

    return (
        <div className="px-8 py-3 flex flex-col gap-2 border-b border-translatewp-border">
            <p className="text-xs text-translatewp-muted! m-0">
                {sprintf(
                    /* translators: 1: current step number, 2: total number of steps */
                    __( 'Step %1$d of %2$d', 'translate' ),
                    currentIndex + 1,
                    steps.length
                )}
            </p>
            <div className="setup-step-names flex flex-wrap items-center gap-x-6 gap-y-2">
                {steps.map( ( step, index ) =>
                {
                    const isCurrent = index === currentIndex;
                    const isCompleted = index < currentIndex;
                    const state = isCurrent ? 'current' : isCompleted ? 'completed' : 'upcoming';
                    const color = isCurrent
                        ? 'text-translatewp-primary!'
                        : isCompleted
                            ? 'text-translatewp-neutral!'
                            : 'text-translatewp-muted!';

                    return (
                        <span
                            key={step.id}
                            data-step={step.id}
                            data-state={state}
                            className={`flex items-center gap-1 whitespace-nowrap font-bold text-xs uppercase ${color}`}
                        >
                            {isCompleted && <Check size={14} aria-hidden="true" />}
                            {step.label}
                        </span>
                    );
                } )}
            </div>
        </div>
    );
}
