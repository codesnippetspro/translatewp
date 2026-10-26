import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';

import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { useProSlot } from '@/apps/admin/hooks/useProSlot';
import { ProMarker } from '@/shared/components/pro/ProMarker';
import { ProLock } from '@/shared/components/pro/ProLock';
import { ManagedByNetwork } from '@/shared/components/managedByNetwork';
import { getProState } from '@/shared/lib/pro/proState';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';

/**
 * The visitor language section of Settings > Languages. Browser
 * detection is free; remembering the last choice, the cookie lifetime and
 * the behaviour after switching are Pro and arrive through the
 * `visitorLanguage` slot. Free sees that slot as one locked row.
 */
export const VisitorLanguage = () =>
{
    const autoTranslate = useAdminSettingsStore(state => state.settings['translations.settings'].auto_translate);
    const setSetting = useAdminSettingsStore(state => state.setSetting);
    const Panel = useProSlot('visitorLanguage');
    const { data: connectStatus } = useQuery(getConnectStatusQueryOptions());
    // "Update it" is only true for an active but older addon; otherwise the Pro
    // prompt decides between install and upgrade.
    const proState = getProState(connectStatus);
    const addonOutdated = proState.entitled && proState.active;

    return (
        <section className="flex flex-col gap-6" aria-labelledby="twp-visitor-language">
            <div className="flex flex-col gap-2">
                <h4 id="twp-visitor-language" className="font-semibold text-lg text-translatewp-neutral! m-0">{__('Visitor language', 'translate')}</h4>
                <p className="text-sm text-translatewp-muted! m-0">{__('How the site picks a language for each visitor.', 'translate')}</p>
            </div>

            <ManagedByNetwork group="visitor_language">
            <ToggleControl
                label={
                    <div className='flex flex-col gap-1'>
                        <p className='font-bold text-sm! text-translatewp-neutral!'>{__('Auto-detect browser language', 'translate')}</p>
                        <span className='text-sm text-translatewp-muted!'>{__('Open the site in the language the visitor\'s browser asks for, when it is one of your translation languages.', 'translate')}</span>
                    </div>
                }
                checked={autoTranslate}
                onChange={(value) => setSetting('translations.settings', previous => ({ ...previous, auto_translate: value }))}
                className='[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!'
            />

            {Panel ? (
                <Panel />
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="flex items-center gap-3">
                        <span className="text-sm font-bold text-translatewp-neutral!">{__('Remember the visitor\'s choice', 'translate')}</span>
                        <ProMarker />
                    </div>
                    {addonOutdated ? (
                        <p className="text-sm text-translatewp-muted! m-0">{__('Your TranslateWP Pro addon does not include the visitor language controls yet. Update it from your Plugins screen when a new version is available.', 'translate')}</p>
                    ) : (
                        <ProLock feature={__('Visitor language', 'translate')}>
                            <p className="text-sm text-translatewp-muted! m-0">{__('Remember the last chosen language, set how long it is kept, and choose what happens after a switch.', 'translate')}</p>
                        </ProLock>
                    )}
                </div>
            )}
            </ManagedByNetwork>
        </section>
    );
};
