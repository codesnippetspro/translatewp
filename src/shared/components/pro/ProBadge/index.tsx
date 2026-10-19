interface ProBadgeProps
{
    className?: string;
    // The plan name where the chip states the site's plan rather than marking a lock.
    label?: string;
}

export const ProBadge = ({ className, label = 'Pro' }: ProBadgeProps) => (
    <span className={`inline-flex items-center rounded-md bg-translatewp-primary/10 px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-translatewp-primary! ${className ?? ''}`.trim()}>
        {label}
    </span>
);
