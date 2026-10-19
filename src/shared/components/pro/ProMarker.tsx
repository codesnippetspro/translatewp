import { __ } from '@wordpress/i18n';

import { ProBadge } from '@/shared/components/pro/ProBadge';
import { ProInstallAction } from '@/shared/components/pro/ProInstallAction';
import { getProState, proNextStep } from '@/shared/lib/pro/proState';

const ACTION_CLASS = 'inline-flex items-center rounded-md border-0 bg-translatewp-primary! px-2 py-0.5 text-xs font-bold text-white! '
    + 'cursor-pointer transition-opacity hover:opacity-90 disabled:opacity-60 disabled:cursor-default';

/**
 * The marker beside a Pro-locked feature: an account that already includes
 * Pro gets the one-click install or activation, never the Pro tag that reads
 * as "buy this".
 */
export const ProMarker = () =>
{
    const step = proNextStep(getProState());

    if (step === 'install') {
        return <ProInstallAction step="install" label={__('Install Pro', 'translate')} className={ACTION_CLASS} />;
    }

    if (step === 'activate') {
        return <ProInstallAction step="activate" label={__('Activate Pro', 'translate')} className={ACTION_CLASS} />;
    }

    return <ProBadge />;
};
