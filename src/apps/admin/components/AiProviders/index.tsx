import { __ } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';

import { ProMarker } from '@/shared/components/pro/ProMarker';
import { ProLock } from '@/shared/components/pro/ProLock';
import { useProSlot } from '@/apps/admin/hooks/useProSlot';
import { getProState } from '@/shared/lib/pro/proState';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';

const LOCKED_PROVIDERS = ['OpenAI', 'Anthropic', 'Google Gemini', 'Any OpenAI-compatible endpoint'];

export const AiProviders = ({ embedded = false }: { embedded?: boolean }) =>
{
    const Panel = useProSlot('aiProviders');
    const { data: connectStatus } = useQuery(getConnectStatusQueryOptions());
    if (Panel) {
        return <Panel />;
    }

    // "Update it" is only true for an active but older addon; otherwise the Pro
    // prompt decides between install and upgrade.
    const proState = getProState(connectStatus);
    const addonOutdated = proState.entitled && proState.active;
    const placeholder = addonOutdated ? (
        <div className="flex flex-col gap-2 p-6 border border-translatewp-border rounded-lg">
            <span className="text-sm text-translatewp-muted!">
                Your TranslateWP Pro addon does not include AI Models yet. Update it from your Plugins screen when a new version is available.
            </span>
        </div>
    ) : (
        <div className="flex flex-col gap-3 p-6 border border-translatewp-border rounded-lg">
            <ul className="flex flex-col gap-1">
                {LOCKED_PROVIDERS.map((provider) => (
                    <li key={provider} className="text-sm text-translatewp-neutral!">{provider}</li>
                ))}
            </ul>
            <span className="text-xs text-translatewp-muted!">Your key is encrypted by WordPress and is never sent to visitor browsers.</span>
        </div>
    );

    return (
        <div className={embedded ? 'flex flex-col gap-8' : 'flex flex-col gap-8 px-4 py-6 md:p-8'}>
            <div className="flex flex-col gap-2">
                {!embedded && (
                    <div className="flex items-center gap-3">
                        <h2 className="text-2xl! font-semibold text-translatewp-neutral!">AI models</h2>
                        <ProMarker />
                    </div>
                )}
                <p className="m-0! text-sm font-bold text-translatewp-neutral!" role="status" data-byok-status="translatewp">
                    {__('Translations use the TranslateWP model, included in your plan.', 'translate')}
                </p>
                <p className="text-sm text-translatewp-muted!">{__('With Pro, translate with your own key from Anthropic, Google Gemini, OpenAI, or an OpenAI-compatible endpoint, billed by that provider.', 'translate')}</p>
            </div>

            {addonOutdated ? placeholder : <ProLock feature="AI Models">{placeholder}</ProLock>}
        </div>
    );
};
