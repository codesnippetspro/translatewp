import type { ReactNode } from 'react';

/**
 * A setting that does nothing for the current switcher: shown dimmed and
 * out of reach, with one short line saying why, instead of being silently
 * ignored.
 */
export const Unavailable = ({ when, reason, children }: { when: boolean; reason: string; children: ReactNode }) =>
{
    if (!when) return <>{children}</>;

    return (
        <div className="flex flex-col gap-2" data-unavailable>
            <p className="m-0 text-sm text-translatewp-muted! italic" data-unavailable-reason>{reason}</p>
            <div className="pointer-events-none opacity-50 select-none" aria-disabled="true" {...{ inert: "" }}>
                {children}
            </div>
        </div>
    );
};
