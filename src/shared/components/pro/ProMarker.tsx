import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

import { ProBadge, PRO_TAG_CLASS } from '@/shared/components/pro/ProBadge';
import { ProInstallAction } from '@/shared/components/pro/ProInstallAction';
import { ProModal } from '@/shared/components/pro/ProModal';
import { getProState, proNextStep, type ProState } from '@/shared/lib/pro/proState';

export const ProMarker = ({ state }: { state?: ProState } = {}) =>
{
    const [open, setOpen] = useState(false);
    const step = proNextStep(state ?? getProState());
    const className = `${PRO_TAG_CLASS} cursor-pointer`;

    if (step === 'install' || step === 'activate') {
        return <ProInstallAction
            step={step}
            label={__('Pro', 'translate')}
            actionLabel={step === 'install' ? __('Install Pro', 'translate') : __('Activate Pro', 'translate')}
            className={className}
        />;
    }
    if (step !== 'upgrade') return <ProBadge />;

    return <>
        <button type="button" className={className} title={__('Upgrade to PRO', 'translate')}
            aria-label={__('Upgrade to PRO', 'translate')} onClick={() => setOpen(true)}>
            <span>{__('Pro', 'translate')}</span>
        </button>
        <ProModal state={state} isOpen={open} onClose={() => setOpen(false)} />
    </>;
};
