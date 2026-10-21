import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
    activateProMutationOptions,
    disconnectSiteMutationOptions,
    installProMutationOptions,
} from '@/shared/mutation-options/connect';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';
import { AddressChangedNotice } from '@/shared/components/connection/AddressChangedNotice';
import { ConnectionStatusText } from '@/shared/components/connection/ConnectionStatusText';
import { ConfirmDialog } from '@/shared/components/confirmDialog';
import { useConfirmDialog } from '@/shared/components/confirmDialog/useConfirmDialog';
import { ManagedByNetworkNote } from '@/shared/components/managedByNetwork';
import { isGroupLocked } from '@/shared/lib/settingsScope';
import { UpgradeTimeline } from '@/shared/components/connection/UpgradeTimeline';
import { getProState } from '@/shared/lib/pro/proState';
import { reloadPage } from '@/shared/lib/reloadPage';
import { beginConnect } from '@/shared/api/connect';
import type { ProPanelState, UpgradeStage } from '@/shared/api/connect';
import { ConnectTabStatus, useConnectStart } from '@/shared/lib/connect/useConnectStart';

type ProStatusProps = {
    pro: ProPanelState;
    onlyWhenActionable?: boolean;
    framed?: boolean;
    accountUrl?: string;
};

const PACKAGE_PENDING_REFRESH_MS = 15000;

const upgradeLine = (stage: UpgradeStage): string =>
{
    if (stage === 'checkout_started') return __('Finish checkout in the TranslateWP portal. This page updates on its own.', 'translate');
    if (stage === 'checkout_completed') return __('Checkout completed. Waiting for the payment to arrive.', 'translate');
    return __('Payment received. Turning on Pro for your account.', 'translate');
};

const proNeedsAttention = (pro: ProPanelState): boolean =>
    !pro.installed || !pro.active || pro.updateAvailable;

export const ProStatus = ({ pro, onlyWhenActionable = false, framed = false, accountUrl = '' }: ProStatusProps) =>
{
    const queryClient = useQueryClient();
    const [message, setMessage] = useState<{ text: string; ok: boolean; code?: string } | null>(null);
    const upgrade = pro.upgrade ?? null;
    const upgradeWaiting = upgrade !== null && upgrade.stage !== 'declined' && !(pro.entitled && upgrade.stage === 'confirmed');

    useQuery({
        ...getConnectStatusQueryOptions(),
        enabled: pro.packagePending || upgradeWaiting,
        refetchInterval: PACKAGE_PENDING_REFRESH_MS,
    });

    const installMutation = useMutation({
        ...installProMutationOptions(),
        onError: () => setMessage({
            ok: false,
            text: __('Pro could not be installed. Try again, or install it from your account.', 'translate'),
        }),
        onSuccess: async (result) =>
        {
            setMessage({ ok: result.ok, text: result.message, code: result.code });
            if (result.ok) {
                // The sidebar menu and the page-load Pro state are server rendered.
                reloadPage();
                return;
            }
            await queryClient.invalidateQueries({ queryKey: ['connectStatus'] });
        },
    });

    const activateMutation = useMutation({
        ...activateProMutationOptions(),
        onError: () => setMessage({
            ok: false,
            text: __('Pro could not be activated. Activate it from your Plugins screen.', 'translate'),
        }),
        onSuccess: async (result) =>
        {
            setMessage({ ok: result.ok, text: result.message });
            if (result.ok) {
                reloadPage();
                return;
            }
            await queryClient.invalidateQueries({ queryKey: ['connectStatus'] });
        },
    });

    if ((!pro.entitled && upgrade === null) || (onlyWhenActionable && pro.entitled && upgrade === null && !proNeedsAttention(pro) && message === null)) {
        return null;
    }

    let body;
    if (!pro.entitled && upgrade?.stage === 'declined') {
        body = (
            <>
                <p className="text-sm text-translatewp-neutral! m-0">
                    {__('Your upgrade was not confirmed. Open your account to check the payment or try again.', 'translate')}
                </p>
                <div className="flex flex-wrap gap-3">
                    {pro.accountUrl !== '' && (
                        <Button variant="primary" className="rounded-sm!" href={pro.accountUrl} target="_blank" rel="noopener noreferrer">
                            {__('Open your account', 'translate')}
                        </Button>
                    )}
                    <Button variant="secondary" className="rounded-sm!" href={getProState().upgradeUrl}>
                        {__('Try again', 'translate')}
                    </Button>
                </div>
            </>
        );
    } else if (!pro.entitled) {
        body = (
            <p className="text-sm text-translatewp-neutral! m-0">
                {upgradeLine(upgrade?.stage ?? 'checkout_started')}
            </p>
        );
    } else if (pro.installed && !pro.active) {
        body = (
            <>
                <p className="text-sm text-translatewp-neutral! m-0">
                    {__('TranslateWP Pro is installed but not active on this site.', 'translate')}
                </p>
                <div className="flex flex-wrap gap-3">
                    <Button
                        variant="primary"
                        className="rounded-sm!"
                        disabled={activateMutation.isPending}
                        isBusy={activateMutation.isPending}
                        onClick={() =>
                        {
                            setMessage(null);
                            activateMutation.mutate();
                        }}
                    >
                        {__('Activate Pro', 'translate')}
                    </Button>
                </div>
            </>
        );
    } else if (pro.installed && pro.updateAvailable && pro.updateUrl) {
        body = (
            <>
                <p className="text-sm text-translatewp-neutral! m-0">
                    {sprintf(
                        /* translators: 1: installed version, 2: available version */
                        __('TranslateWP Pro %1$s is installed. Version %2$s is available.', 'translate'),
                        pro.installedVersion ?? '',
                        pro.latestVersion ?? ''
                    )}
                </p>
                <div className="flex flex-wrap gap-3">
                    <Button variant="primary" className="rounded-sm!" href={pro.updateUrl}>
                        {__('Update Pro', 'translate')}
                    </Button>
                </div>
            </>
        );
    } else if (pro.installed) {
        body = (
            <p className="text-sm text-translatewp-neutral! m-0">
                {__('TranslateWP Pro is installed and active.', 'translate')}
            </p>
        );
    } else if (pro.packagePending) {
        body = (
            <p className="text-sm text-translatewp-neutral! m-0">
                {__('Payment received. We are confirming it, and Pro will be ready to install in a moment.', 'translate')}
            </p>
        );
    } else {
        body = (
            <>
                <p className="text-sm text-translatewp-neutral! m-0">
                    {__('Your TranslateWP Pro features are ready to install on this site.', 'translate')}
                </p>
                <div className="flex flex-wrap gap-3">
                    <Button
                        variant="primary"
                        className="rounded-sm!"
                        disabled={installMutation.isPending}
                        isBusy={installMutation.isPending}
                        onClick={() =>
                        {
                            setMessage(null);
                            installMutation.mutate();
                        }}
                    >
                        {__('Install Pro', 'translate')}
                    </Button>
                </div>
            </>
        );
    }

    const frame = framed ? ' rounded-sm border border-translatewp-border bg-white p-4' : '';

    return (
        <div className={`flex flex-col gap-3${frame}`} data-testid="pro-status">
            {upgrade && <UpgradeTimeline upgrade={upgrade} />}
            {body}
            {message && (
                <p className={`text-sm m-0 ${message.ok ? 'text-translatewp-neutral!' : 'text-translatewp-error'}`} role="status">
                    {message.text}
                </p>
            )}
            {message?.code === 'package_unavailable' && (
                <div className="flex flex-wrap gap-3">
                    <Button
                        variant="secondary"
                        className="rounded-sm!"
                        disabled={installMutation.isPending}
                        isBusy={installMutation.isPending}
                        onClick={() =>
                        {
                            setMessage(null);
                            installMutation.mutate();
                        }}
                    >
                        {__('Try again', 'translate')}
                    </Button>
                    {accountUrl !== '' && (
                        <Button variant="tertiary" href={accountUrl} target="_blank" rel="noopener noreferrer">
                            {__('Open your account', 'translate')}
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
};

export const ConnectSiteButton = ({ label }: { label: string }) =>
{
    const { start, isPending, tabState, errorMessage } = useConnectStart(beginConnect);

    return (
        <>
            <Button
                variant="primary"
                className="rounded-sm!"
                disabled={isPending}
                isBusy={isPending}
                onClick={start}
            >
                {label}
            </Button>
            <ConnectTabStatus state={tabState} />
            {errorMessage && (
                <p className="text-sm text-translatewp-error m-0" role="alert">{errorMessage}</p>
            )}
        </>
    );
};

export const ConnectionPanel = ({ showConnectAction = true }: { showConnectAction?: boolean }) =>
{
    const queryClient = useQueryClient();
    const { data, isLoading } = useQuery(getConnectStatusQueryOptions());
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const connect = useConnectStart(beginConnect);

    const disconnectMutation = useMutation({
        ...disconnectSiteMutationOptions(),
        onError: () => setErrorMessage(
            __('Disconnect failed. Try again or disconnect from your account portal.', 'translate')
        ),
        onSuccess: async () =>
        {
            setErrorMessage(null);
            await queryClient.invalidateQueries({ queryKey: ['connectStatus'] });
        },
    });

    const { requestConfirmation, confirmDialogProps } = useConfirmDialog();

    const handleDisconnect = async () =>
    {
        const confirmed = await requestConfirmation({
            title: __('Disconnect this site?', 'translate'),
            message: __('This revokes the connection token from your TranslateWP account and clears it from this site. Translation stops until the site is connected again.', 'translate'),
            confirmLabel: __('Disconnect', 'translate'),
            isDestructive: true,
        });

        if (!confirmed) {
            return;
        }

        disconnectMutation.mutate();
    };

    const handleSwitchAccount = async () =>
    {
        const confirmed = await requestConfirmation({
            title: __('Connect a different account?', 'translate'),
            message: __('This site is disconnected from the current account first, then the connection starts again with the account you sign in with.', 'translate'),
            confirmLabel: __('Continue', 'translate'),
            isDestructive: true,
        });

        if (!confirmed) {
            return;
        }

        disconnectMutation.mutate(undefined, {
            onSuccess: () =>
            {
                connect.start();
            },
        });
    };

    if (isLoading) {
        return <p className="text-sm text-translatewp-muted!">{__('Checking connection…', 'translate')}</p>;
    }

    if (isGroupLocked('account')) {
        return (
            <div className="flex flex-col gap-4">
                <ConnectionStatusText />
                {data?.siteId && (
                    <p className="text-sm text-translatewp-muted! m-0">
                        {__('Site ID:', 'translate')}{' '}
                        <span className="font-mono">{data.siteId}</span>
                    </p>
                )}
                <ManagedByNetworkNote />
                {data?.pro?.entitled && (
                    <div className="border-t border-translatewp-border pt-4">
                        <ProStatus pro={data.pro} />
                    </div>
                )}
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <ConfirmDialog {...confirmDialogProps} />
            <ConnectionStatusText />
            <AddressChangedNotice />
            {data?.connectionStatus !== 'invalidated' && (data?.connected ? (
                <>
                    {data.siteId && (
                        <p className="text-sm text-translatewp-muted! m-0">
                            {__('Site ID:', 'translate')}{' '}
                            <span className="font-mono">{data.siteId}</span>
                        </p>
                    )}
                    <div className="flex flex-row flex-wrap gap-3">
                        <Button
                            variant="secondary"
                            className="rounded-sm!"
                            disabled={disconnectMutation.isPending}
                            isBusy={disconnectMutation.isPending}
                            onClick={() => { void handleDisconnect(); }}
                        >
                            {__('Disconnect this site', 'translate')}
                        </Button>
                        <Button
                            variant="secondary"
                            className="rounded-sm!"
                            disabled={connect.isPending || disconnectMutation.isPending}
                            isBusy={connect.isPending}
                            onClick={() => { void handleSwitchAccount(); }}
                        >
                            {__('Connect a different account', 'translate')}
                        </Button>
                        <p className="text-sm text-translatewp-muted! m-0">
                            {__(
                                'This revokes the connection token from your TranslateWP account and clears it from this site. You can reconnect at any time.',
                                'translate'
                            )}{' '}
                            {data.portalSitesUrl && (
                                <a
                                    href={data.portalSitesUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-translatewp-primary!"
                                >
                                    {__('Open account sites', 'translate')}
                                </a>
                            )}
                        </p>
                    </div>
                    {data.pro?.entitled && (
                        <div className="border-t border-translatewp-border pt-4">
                            <ProStatus pro={data.pro} />
                        </div>
                    )}
                </>
            ) : (
                <>
                    <p className="text-sm text-translatewp-neutral! m-0">
                        {__(
                            'Connect this site to your TranslateWP account to enable translation.',
                            'translate'
                        )}
                    </p>
                    {showConnectAction && (
                        <div className="flex flex-wrap gap-3">
                            <ConnectSiteButton label={__('Connect site', 'translate')} />
                        </div>
                    )}
                </>
            ))}
            <ConnectTabStatus state={connect.tabState} />
            {(errorMessage ?? connect.errorMessage) && (
                <p className="text-sm text-translatewp-error m-0" role="alert">{errorMessage ?? connect.errorMessage}</p>
            )}
        </div>
    );
};
