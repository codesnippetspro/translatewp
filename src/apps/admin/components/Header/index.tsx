import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useQuery } from '@tanstack/react-query';

import { Lockup } from '@/shared/components/Icon/Lockup';
import { PlanPill } from '@/shared/components/PlanPill';
import { TWP_BUTTON_PRIMARY } from '@/shared/components/buttonStyles';
import { ProModal } from '@/shared/components/pro/ProModal';
import { ProInstallAction } from '@/shared/components/pro/ProInstallAction';
import type { ConnectStatus } from '@/shared/api/connect';
import { getProState, isProActive, proNextStep } from '@/shared/lib/pro/proState';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';

// A page that already holds the connection state (the network admin) passes
// it; the site admin reads its own.
const PORTAL_URL = 'https://app.translatewp.ai/';

export const Header = ({ status }: { status?: ConnectStatus } = {}) =>
{
    const [isUpgradeModalOpen, setIsUpgradeModalOpen] = useState(false);

    const { data: queried } = useQuery({ ...getConnectStatusQueryOptions(), enabled: status === undefined });
    const proState = getProState(status ?? queried);
    const nextStep = proNextStep(proState);
    const proActive = isProActive(proState);

    return (
        <div className="px-6 py-4 flex items-center justify-between bg-white">
            <h1 className="m-0! flex items-center leading-none" data-twp-brand>
                <Lockup height={32} />
                <span className="screen-reader-text">TranslateWP</span>
            </h1>

            {proActive && (
                <PlanPill entitlement={{ plan: 'pro' }} href={(status ?? queried)?.pro?.accountUrl || PORTAL_URL} />
            )}

            {(nextStep === 'install' || nextStep === 'activate') && (
                <ProInstallAction
                    step={nextStep}
                    label={nextStep === 'install' ? __('Install Pro', 'translate') : __('Activate Pro', 'translate')}
                    className={`twp-header-action ${TWP_BUTTON_PRIMARY}`}
                />
            )}

            {nextStep === 'upgrade' && (
                <>
                    {/*
                     * Self-styled native button on purpose: this CTA is rendered independently of
                     * the host WordPress `@wordpress/components` build (which is externalized, not
                     * bundled) and uses neutral, plugin-namespaced markup with no external href so
                     * it isn't hidden by content/ad blockers that target upsell buttons.
                     */}
                    <button
                        type="button"
                        onClick={() => setIsUpgradeModalOpen(true)}
                        className={`twp-header-action ${TWP_BUTTON_PRIMARY}`}
                    >
                        Upgrade to PRO
                    </button>

                    <ProModal isOpen={isUpgradeModalOpen} onClose={() => setIsUpgradeModalOpen(false)} state={proState} />
                </>
            )}
        </div>
    );
}
