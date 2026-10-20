import { __, sprintf } from '@wordpress/i18n';
import { Modal, Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { Lock, Check } from 'lucide-react';

import { getConnectStatus, type ConnectStatus } from '@/shared/api/connect';
import { TWP_BUTTON_PRIMARY_BLOCK, TWP_BUTTON_GHOST } from '@/shared/components/buttonStyles';
import { ProInstallAction } from '@/shared/components/pro/ProInstallAction';
import { getProState, proNextStep } from '@/shared/lib/pro/proState';
import type { ProState } from '@/shared/lib/pro/proState';

const perks = (): string[] => [
    __('Bring your own AI provider and keys', 'translate'),
    __('Translated SEO titles and sitemaps', 'translate'),
    __('Custom styles and tones', 'translate'),
];

interface ProModalProps
{
    isOpen: boolean;
    onClose: () => void;
    feature?: string;
    state?: ProState;
    onProReady?: () => void;
}

/**
 * The Pro prompt behind every locked surface. An account without Pro sees the
 * upgrade; an account that already includes Pro but has no active addon on
 * this site is told the purchase is done and offered the one-click install or
 * activation instead, so nobody is sent to buy what they own.
 */
export const ProModal = ({ isOpen, onClose, feature, state: liveState, onProReady }: ProModalProps) =>
{
    // The page's own account state can be minutes old; a lock opened after
    // the account bought Pro must offer the install, so the plugin is asked
    // again each time the prompt opens.
    const [freshStatus, setFreshStatus] = useState<ConnectStatus | null>(null);
    useEffect(() =>
    {
        if (!isOpen || liveState) return;
        let cancelled = false;
        getConnectStatus({ refresh: true })
            .then((status) => { if (!cancelled) setFreshStatus(status); })
            .catch(() => undefined);
        return () => { cancelled = true; };
    }, [isOpen, liveState]);

    if (!isOpen) {
        return null;
    }

    const state = liveState ?? (freshStatus ? getProState(freshStatus) : getProState());
    const nextStep = proNextStep(state);
    const featureName = feature ?? __('this feature', 'translate');

    let title: string;
    let intro: string;
    if (nextStep === 'install') {
        title = __('Your account includes Pro', 'translate');
        /* translators: %s: name of the locked feature */
        intro = sprintf(__('Install it on this site to unlock %s.', 'translate'), featureName);
    } else if (nextStep === 'activate') {
        title = __('Your account includes Pro', 'translate');
        /* translators: %s: name of the locked feature */
        intro = sprintf(__('It is installed on this site but not active. Activate it to unlock %s.', 'translate'), featureName);
    } else {
        /* translators: %s: name of the locked feature */
        title = feature ? sprintf(__('Unlock %s', 'translate'), feature) : __('Unlock this feature', 'translate');
        intro = __('This feature is part of the Orbital plan. Upgrade from Terrestrial to unlock it and everything below.', 'translate');
    }

    return (
        <Modal
            onRequestClose={onClose}
            __experimentalHideHeader
            className='[&_.components-modal__content]:p-0! [&_.components-modal__content]:m-0! [&_.components-modal__content]:overflow-hidden! w-[440px]! max-w-[92vw]! rounded-xl! overflow-hidden!'
        >
            <div className="flex flex-col">
                <div
                    className="flex flex-col items-center gap-4 px-8 pt-10 pb-8 text-center"
                    style={{ background: 'linear-gradient(135deg, var(--color-translatewp-primary, #0e3ecd) 0%, #0b31a4 100%)' }}
                >
                    <span className="inline-flex items-center px-3 py-1 rounded-full bg-white/20 text-white text-xs font-bold tracking-widest uppercase">
                        {__('Orbital', 'translate')}
                    </span>

                    <div className="flex items-center justify-center w-16 h-16 rounded-full bg-white/15 ring-4 ring-white/10">
                        <Lock className="w-7 h-7 text-white" />
                    </div>

                    <h2 className="text-xl! font-bold text-white! m-0!">
                        {title}
                    </h2>

                    <p className="text-sm text-white/80! m-0! max-w-xs">
                        {intro}
                    </p>
                </div>

                <div className="flex flex-col gap-6 px-8 py-7">
                    <ul className="flex flex-col gap-3 m-0 p-0 list-none">
                        {perks().map((perk) => (
                            <li key={perk} className="flex items-center gap-3 m-0">
                                <span className="flex items-center justify-center w-5 h-5 rounded-full bg-translatewp-primary/10 shrink-0">
                                    <Check className="w-3.5 h-3.5 text-translatewp-primary!" />
                                </span>
                                <span className="text-sm text-translatewp-neutral!">{perk}</span>
                            </li>
                        ))}
                    </ul>

                    <div className="flex flex-col gap-2">
                        {nextStep === 'install' || nextStep === 'activate' ? (
                            <ProInstallAction
                                step={nextStep}
                                onDone={onProReady}
                                label={nextStep === 'install' ? __('Install now', 'translate') : __('Activate now', 'translate')}
                                className={`h-11 ${TWP_BUTTON_PRIMARY_BLOCK}`}
                            />
                        ) : (
                            <Button
                                variant="primary"
                                href={state.upgradeUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className={`h-11! ${TWP_BUTTON_PRIMARY_BLOCK}`}
                            >
                                {__('Upgrade now', 'translate')}
                            </Button>
                        )}

                        <Button
                            variant="tertiary"
                            onClick={onClose}
                            className={`h-9! ${TWP_BUTTON_GHOST}`}
                        >
                            {__('Maybe later', 'translate')}
                        </Button>
                    </div>
                </div>
            </div>
        </Modal>
    );
};
