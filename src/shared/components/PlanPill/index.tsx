import { __ } from '@wordpress/i18n';

import { planLabel, type PlanEntitlement } from '@/shared/lib/plan/planLabel';

// The account's current plan, shown as the portal shows it: an outlined,
// rounded chip with a muted "Current plan" label and the bold plan name. A
// paid plan is framed in the primary tone; the free plan stays neutral. When
// an href is given the chip is a link to the portal account.
const FRAME_PAID = 'border-translatewp-primary bg-translatewp-primary/5';
const FRAME_FREE = 'border-twp-neutral-200 bg-twp-neutral-0';

// shrink-0 + whitespace-nowrap: the chip keeps its width and its label stays on
// one line, so a long plan name makes the row wrap the whole chip, never the text.
const BASE = 'inline-flex h-8 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border px-3.5 text-sm no-underline transition-colors duration-300 ease-[var(--ease-twp)]';

interface PlanPillProps
{
    entitlement: PlanEntitlement;
    href?: string;
    className?: string;
}

export const PlanPill = ({ entitlement, href, className = '' }: PlanPillProps) =>
{
    const paid = entitlement.plan !== 'free';
    const frame = paid ? FRAME_PAID : FRAME_FREE;
    const labelTone = paid ? 'text-translatewp-primary!' : 'text-twp-neutral-500!';
    const planTone = paid ? 'text-translatewp-primary!' : 'text-twp-neutral-800!';

    const inner = (
        <>
            <span className={`whitespace-nowrap ${labelTone}`}>{__('Current plan', 'translate')}</span>
            <span className={`whitespace-nowrap font-semibold ${planTone}`}>{planLabel(entitlement)}</span>
        </>
    );

    const classes = `${BASE} ${frame} ${className}`.trim();

    if (href) {
        return (
            <a href={href} target="_blank" rel="noopener noreferrer" className={`${classes} hover:bg-translatewp-primary/10`} data-plan-chip>
                {inner}
            </a>
        );
    }

    return <span className={classes} data-plan-chip>{inner}</span>;
};
