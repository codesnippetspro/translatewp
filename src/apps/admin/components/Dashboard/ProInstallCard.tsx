import { __ } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { Sparkles } from 'lucide-react';

import { ProInstallAction } from '@/shared/components/pro/ProInstallAction';
import { pendingProAction } from '@/shared/lib/pro/proState';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';

/**
 * An entitled account whose site lacks an active Pro addon sees this
 * card first on the Dashboard, with the one-click install or activation,
 * until the addon is active. The sidebar "Install Pro" entry links here.
 */
export const ProInstallCard = () =>
{
    const { data } = useQuery(getConnectStatusQueryOptions());
    const step = pendingProAction(data);

    if (step === null) {
        return null;
    }

    const body = step === 'install'
        ? __('Install it on this site in one click to unlock SEO Tools, custom styles and tones, and your own AI provider.', 'translate')
        : __('It is installed on this site but not active. Activate it in one click to unlock SEO Tools, custom styles and tones, and your own AI provider.', 'translate');

    return (
        <div
            data-testid="pro-install-card"
            role="status"
            className="flex flex-wrap items-center gap-4 rounded-lg border border-translatewp-primary bg-translatewp-primary/5 px-5 py-4"
        >
            <Sparkles size={22} className="shrink-0 text-translatewp-primary" aria-hidden="true" />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="m-0 text-base font-semibold text-translatewp-neutral">{__('Your account includes Pro', 'translate')}</p>
                <p className="m-0 text-sm text-translatewp-neutral">{body}</p>
            </div>
            <ProInstallAction
                step={step}
                label={step === 'install' ? __('Install Pro', 'translate') : __('Activate Pro', 'translate')}
            />
        </div>
    );
};
