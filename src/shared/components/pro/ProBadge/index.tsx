export const PRO_TAG_CLASS = 'inline-flex shrink-0 items-center whitespace-nowrap rounded-md border border-solid border-translatewp-primary/20 bg-translatewp-primary/10! px-2 py-0.5 text-xs! leading-4 font-medium text-translatewp-primary!';

interface ProBadgeProps
{
    className?: string;
    // The plan name where the chip states the site's plan rather than marking a lock.
    label?: string;
}

export const ProBadge = ({ className, label = 'Pro' }: ProBadgeProps) => (
    <span className={`${PRO_TAG_CLASS} ${className ?? ''}`.trim()}>
        {label}
    </span>
);
