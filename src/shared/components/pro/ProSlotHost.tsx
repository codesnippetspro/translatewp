import { __ } from '@wordpress/i18n';

import { ProPanelBoundary } from '@/shared/components/pro/ProPanelBoundary';
import type { ProPanelComponent } from '@/shared/host';

interface ProSlotHostProps
{
    panel: ProPanelComponent;
}

export const ProSlotHost = ( { panel: Panel }: ProSlotHostProps ) =>
{
    const declaredVersion = Panel.hostVersion;
    const hostVersion = window.TranslateWP?.host?.version;
    const requiresNewerHost =
        declaredVersion !== undefined &&
        ( hostVersion === undefined || hostVersion < declaredVersion );

    if ( requiresNewerHost )
    {
        return (
            <div className="flex flex-col gap-2 p-6 border border-translatewp-border rounded-lg">
                <span className="text-sm text-translatewp-muted!">
                    { __( 'Update TranslateWP to use this Pro panel.', 'translate' ) }
                </span>
            </div>
        );
    }

    return (
        <ProPanelBoundary>
            <Panel />
        </ProPanelBoundary>
    );
};
