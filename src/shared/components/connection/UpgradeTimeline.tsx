import { __ } from '@wordpress/i18n';
import { CheckCircle2, Circle, LoaderCircle, XCircle } from 'lucide-react';

import type { UpgradeProgress } from '@/shared/api/connect';

type StepState = 'done' | 'current' | 'upcoming' | 'failed';

type Step = {
    label: string;
    time: string | null;
    state: StepState;
};

const ORDER = ['checkout_started', 'checkout_completed', 'payment_received', 'confirmed'] as const;

const stateLabel = (state: StepState): string =>
{
    if (state === 'done') return __('Done', 'translate');
    if (state === 'current') return __('In progress', 'translate');
    if (state === 'failed') return __('Failed', 'translate');
    return __('Not started', 'translate');
};

const stepsFor = (upgrade: UpgradeProgress): Step[] =>
{
    const { stage, times } = upgrade;
    const labels = [
        __('Checkout started', 'translate'),
        __('Checkout completed', 'translate'),
        __('Payment received', 'translate'),
        __('Pro confirmed', 'translate'),
    ];
    const stepTimes = [times.checkoutStarted, times.checkoutCompleted, times.paymentReceived, times.confirmed];

    if (stage === 'declined') {
        return [
            ...labels.slice(0, 3).map((label, index): Step => ({
                label,
                time: stepTimes[index],
                state: stepTimes[index] !== null ? 'done' : 'upcoming',
            })),
            { label: __('Upgrade declined', 'translate'), time: times.declined, state: 'failed' },
        ];
    }

    const reached = ORDER.indexOf(stage);

    return labels.map((label, index): Step => ({
        label,
        time: index <= reached ? stepTimes[index] : null,
        state: index <= reached ? 'done' : index === reached + 1 ? 'current' : 'upcoming',
    }));
};

const StepIcon = ({ state }: { state: StepState }) =>
{
    if (state === 'done') return <CheckCircle2 size={16} className='text-translatewp-primary' />;
    if (state === 'current') return <LoaderCircle size={16} className='text-translatewp-primary animate-spin motion-reduce:animate-none' />;
    if (state === 'failed') return <XCircle size={16} className='text-translatewp-error' />;
    return <Circle size={16} className='text-translatewp-muted' />;
};

export const UpgradeTimeline = ({ upgrade }: { upgrade: UpgradeProgress }) => (
    <ol aria-label={__('Upgrade progress', 'translate')} className='m-0 flex list-none flex-col gap-2 p-0'>
        {stepsFor(upgrade).map(step => (
            <li
                key={step.label}
                data-state={step.state}
                aria-current={step.state === 'current' ? 'step' : undefined}
                className='m-0 flex items-center gap-2 text-sm'
            >
                <span aria-hidden='true' className='flex'><StepIcon state={step.state} /></span>
                <span className={step.state === 'upcoming'
                    ? 'text-translatewp-muted!'
                    : step.state === 'failed' ? 'text-translatewp-error font-semibold' : 'text-translatewp-neutral! font-semibold'}
                >
                    {step.label}
                </span>
                <span className='sr-only'>{stateLabel(step.state)}</span>
                {step.time !== null && <span className='text-translatewp-muted!'>{step.time}</span>}
            </li>
        ))}
    </ol>
);
