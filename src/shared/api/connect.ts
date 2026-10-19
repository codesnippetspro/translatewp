import apiFetch from '@wordpress/api-fetch';

export type ProCapabilities = {
    seo: boolean;
    custom_style: boolean;
    byok: boolean;
};

export type UpgradeStage = 'checkout_started' | 'checkout_completed' | 'payment_received' | 'confirmed' | 'declined';

export type UpgradeProgress = {
    stage: UpgradeStage;
    times: {
        checkoutStarted: string | null;
        checkoutCompleted: string | null;
        paymentReceived: string | null;
        confirmed: string | null;
        declined: string | null;
    };
};

export type ProPanelState = {
    plan: string;
    entitled: boolean;
    installed: boolean;
    active: boolean;
    installedVersion: string | null;
    latestVersion: string | null;
    packagePending: boolean;
    upgrade: UpgradeProgress | null;
    accountUrl: string;
    updateAvailable: boolean;
    updateUrl: string | null;
    capabilities: ProCapabilities;
};

export type AddressChange = {
    from: string;
    to: string;
};

export type ConnectStatus = {
    connectionStatus?: 'connected' | 'invalidated' | 'disconnected';
    connected: boolean;
    siteId: string | null;
    accountLabel: string;
    registeredUrl: string;
    addressChange: AddressChange | null;
    portalSitesUrl: string;
    portalSiteUrl: string;
    pro?: ProPanelState;
    setupUnfinished?: boolean;
    setupStep?: string;
    proWelcomePending?: boolean;
};

export type ProInstallResult = {
    ok: boolean;
    code: string;
    message: string;
};

export const activatePro = async (): Promise<ProInstallResult> =>
{
    return await apiFetch<ProInstallResult>({
        path: '/translatewp/v1/connect/pro-activate',
        method: 'POST',
        data: {},
    });
};

export const installPro = async (): Promise<ProInstallResult> =>
{
    return await apiFetch<ProInstallResult>({
        path: '/translatewp/v1/connect/pro-install',
        method: 'POST',
        data: {},
    });
};

export const getConnectStatus = async (options: { refresh?: boolean } = {}): Promise<ConnectStatus> =>
{
    return await apiFetch<ConnectStatus>({
        path: options.refresh ? '/translatewp/v1/connect/status?refresh=1' : '/translatewp/v1/connect/status',
        method: 'GET',
    });
};

export const beginConnect = async (): Promise<{ redirectUrl: string }> =>
{
    return await apiFetch<{ redirectUrl: string }>({
        path: '/translatewp/v1/connect/begin',
        method: 'POST',
        data: {},
    });
};

export const reconnectSite = async (): Promise<{ redirectUrl: string }> =>
{
    return await apiFetch<{ redirectUrl: string }>({
        path: '/translatewp/v1/connect/begin',
        method: 'POST',
        data: { reconnect: true },
    });
};

export const disconnectSite = async (): Promise<{ success: boolean }> =>
{
    return await apiFetch<{ success: boolean }>({
        path: '/translatewp/v1/connect/disconnect',
        method: 'POST',
        data: {},
    });
};
