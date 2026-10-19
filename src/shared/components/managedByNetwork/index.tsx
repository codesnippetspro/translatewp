import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import { isGroupLocked, networkSettingsUrl } from '@/shared/lib/settingsScope';

export const ManagedByNetworkNote = () =>
{
    const url = networkSettingsUrl();

    return (
        <p className="m-0 flex flex-wrap items-center gap-2 rounded-sm border border-translatewp-border bg-translatewp-selection px-3 py-2 text-sm text-translatewp-neutral!" role="note">
            {__('Managed by your network admin', 'translate')}
            {url !== '' && <a href={url} className="text-translatewp-primary!">{__('Open network settings', 'translate')}</a>}
        </p>
    );
};

export const ManagedByNetwork = ({ group, children }: { group: string; children: ReactNode }) =>
{
    if (!isGroupLocked(group)) {
        return <>{children}</>;
    }

    return (
        <div className="flex flex-col gap-4">
            <ManagedByNetworkNote />
            <fieldset disabled className="m-0 flex min-w-0 flex-col gap-8 border-0 p-0 opacity-70">
                {children}
            </fieldset>
        </div>
    );
};
