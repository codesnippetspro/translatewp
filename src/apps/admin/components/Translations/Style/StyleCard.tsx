import { useId } from '@wordpress/element';

interface StyleCardProps
{
    title: string;
    badge?: React.ReactNode;
    description?: string;
    children: React.ReactNode;
}

export const StyleCard = ({ title, badge, description, children }: StyleCardProps) =>
{
    const headingId = useId();

    return (
        <section
            className="flex flex-col gap-4 rounded-lg border border-translatewp-border p-5"
            aria-labelledby={headingId}
        >
            <div className="flex flex-col gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <h3 id={headingId} className="text-sm! font-bold! text-translatewp-neutral! m-0!">
                        {title}
                    </h3>
                    {badge}
                </div>
                {description && <p className="m-0! text-sm! text-translatewp-muted!">{description}</p>}
            </div>

            {children}
        </section>
    );
}
