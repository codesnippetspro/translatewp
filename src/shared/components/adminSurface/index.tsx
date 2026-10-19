export const Card = ({ title, badge, action, children, className }: { title: string; badge?: React.ReactNode; action?: React.ReactNode; children: React.ReactNode; className?: string }) => (
    <section className={`flex flex-col gap-4 rounded-lg border border-translatewp-border bg-white p-6 ${className ?? ''}`.trim()} aria-label={title}>
        <div className="flex items-center justify-between gap-4">
            <span className="flex items-center gap-2">
                <h3 className="font-semibold text-base text-translatewp-neutral! m-0">{title}</h3>
                {badge}
            </span>
            {action}
        </div>
        {children}
    </section>
);

export const Section = ({ title, description, children }: { title: string; description?: string; children: React.ReactNode }) => (
    <section className="flex flex-col gap-4" aria-label={title}>
        <div className="flex flex-col gap-1">
            <h3 className="m-0 text-xs font-semibold uppercase tracking-wide text-translatewp-muted!">{title}</h3>
            {description && <p className="m-0 text-sm text-translatewp-muted!">{description}</p>}
        </div>
        {children}
    </section>
);
