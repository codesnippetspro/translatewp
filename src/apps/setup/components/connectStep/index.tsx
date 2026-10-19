import { __, sprintf } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';

import { ConnectionPanel } from '@/shared/components/connection/ConnectionPanel';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';

const STEPS = [
    () => __('Sign in to TranslateWP, or create an account', 'translate'),
    () => __('Approve and connect this site to your account', 'translate'),
    () => __('Return here and continue setting up translations', 'translate'),
];

export const ConnectStep = () =>
{
    const { data } = useQuery(getConnectStatusQueryOptions());
    const needsPanel = data?.connected === true || data?.connectionStatus === 'invalidated';

    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-col gap-2">
                <h2 className="text-xl! font-semibold text-translatewp-neutral! m-0">
                    {__('Connect your site', 'translate')}
                </h2>
                <p className="text-sm text-translatewp-muted! m-0">
                    {__('Your TranslateWP account holds the settings and usage of every site you connect. Connecting takes a minute.', 'translate')}
                </p>
            </div>
            {needsPanel ? <ConnectionPanel showConnectAction={false} /> : (
                <ol className="m-0 flex list-none flex-col p-0" data-connect-steps aria-label={__('Connect your site', 'translate')}>
                    {STEPS.map((text, index) => (
                        <li key={index} className="relative m-0 flex gap-3" aria-current={index === 0 ? 'step' : undefined}>
                            <span className="flex flex-col items-center">
                                <span className={`flex size-6 shrink-0 items-center justify-center rounded-full border bg-white text-xs font-semibold ${index === 0 ? 'border-translatewp-primary text-translatewp-primary' : 'border-translatewp-border text-translatewp-muted'}`} aria-hidden="true">{index + 1}</span>
                                {index < STEPS.length - 1 && <span className="w-px flex-1 bg-translatewp-border" aria-hidden="true" />}
                            </span>
                            <p className={`m-0! pb-5 text-sm! font-semibold ${index === 0 ? 'text-translatewp-neutral!' : 'text-translatewp-muted!'}`}>
                                <span className="sr-only">{sprintf(
                                    /* translators: %d: step number. */
                                    __('Step %d: ', 'translate'),
                                    index + 1
                                )}</span>
                                {text()}
                            </p>
                        </li>
                    ))}
                </ol>
            )}
            <p className="text-sm text-translatewp-muted! m-0 flex flex-wrap items-center gap-x-3 gap-y-1">
                <span>{__('Connecting your site means you agree to the service terms and the privacy policy.', 'translate')}</span>
                <a href="https://translatewp.ai/terms" target="_blank" rel="noopener noreferrer">{__('Service terms', 'translate')}</a>
                <a href="https://translatewp.ai/privacy" target="_blank" rel="noopener noreferrer">{__('Privacy policy', 'translate')}</a>
            </p>
        </div>
    );
};
