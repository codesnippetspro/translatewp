interface StyleCardProps
{
    title: string;
    badge?: React.ReactNode;
    description?: string;
    children: React.ReactNode;
}

export const StyleCard = ({ title, badge, description, children }: StyleCardProps) =>
{
    return (
        <div className="flex flex-col gap-4 rounded-lg border border-translatewp-border p-5">
            <div className="flex flex-col gap-2">
                <div className="flex items-center justify-between gap-3">
                    <span className="text-sm font-bold text-translatewp-neutral!">{title}</span>
                    {badge}
                </div>
                {description && <p className="m-0! text-sm! text-translatewp-muted!">{description}</p>}
            </div>

            {children}
        </div>
    );
}
