export const PAGE_INSET = 'box-border px-4 md:px-8';

export const PageHeader = ({ title, description, badge, actions }: {
    title: React.ReactNode;
    description?: React.ReactNode;
    badge?: React.ReactNode;
    actions?: React.ReactNode;
}) => (
    <header className={`flex flex-wrap items-start justify-between gap-4 pt-6 pb-4 md:pt-8 md:pb-6 ${PAGE_INSET}`} data-page-header>
        <div className="flex min-w-0 flex-col gap-2">
            <div className="flex flex-wrap items-center gap-3">
                <h2 className="text-2xl! font-semibold text-translatewp-neutral! m-0">{title}</h2>
                {badge}
            </div>
            {description && <div className="text-sm text-translatewp-muted! m-0">{description}</div>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </header>
);

export const PageBody = ({ children, className = 'gap-8' }: { children: React.ReactNode; className?: string }) => (
    <div className={`flex w-full min-w-0 flex-col pb-6 md:pb-8 ${PAGE_INSET} ${className}`} data-page-body>
        {children}
    </div>
);
