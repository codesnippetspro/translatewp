import type { ConnectStatus, UpgradeStage } from '@/shared/api/connect';

export interface ProState
{
    connected: boolean;
    entitled: boolean;
    installed: boolean;
    active: boolean;
    version: string;
    upgradeUrl: string;
    upgradeStage: UpgradeStage | null;
}

const DEFAULT_UPGRADE_URL = 'https://app.translatewp.ai/upgrade';

export const getProState = (status?: ConnectStatus): ProState =>
{
    const raw = (window as unknown as { translatewpPro?: Partial<ProState> }).translatewpPro ?? {};

    const upgradeUrl = typeof raw.upgradeUrl === 'string' && raw.upgradeUrl !== ''
        ? raw.upgradeUrl
        : DEFAULT_UPGRADE_URL;

    return {
        connected: status ? status.connected === true : raw.connected === true,
        entitled: status ? status.pro?.entitled === true : raw.entitled === true,
        installed: status ? status.pro?.installed === true : raw.installed === true,
        active: status ? status.pro?.active === true : raw.active === true,
        version: status ? status.pro?.installedVersion ?? '' : typeof raw.version === 'string' ? raw.version : '',
        upgradeUrl,
        upgradeStage: status?.pro?.upgrade?.stage ?? null,
    };
};

export const isProActive = (state: ProState): boolean => state.entitled && state.installed && state.active;

export const shouldShowUpsell = (state: ProState): boolean => !state.entitled && (state.upgradeStage === null || state.upgradeStage === 'declined');

export type ProNextStep = 'install' | 'activate' | 'upgrade';

/**
 * The one action every Pro surface offers: an entitled site is
 * pushed to install or activate the addon, never asked to buy again; an
 * account without Pro sees the upgrade; everything else offers nothing.
 */
export const proNextStep = (state: ProState): ProNextStep | null =>
{
    if (state.entitled && !state.installed) {
        return 'install';
    }

    if (state.entitled && !state.active) {
        return 'activate';
    }

    if (shouldShowUpsell(state)) {
        return 'upgrade';
    }

    return null;
};

/**
 * The install or activation an entitled site still owes, for the
 * surfaces that push it: the Dashboard card and the last setup step. Nothing
 * while the payment is still being confirmed, when there is no package yet.
 */
export const pendingProAction = (status?: ConnectStatus): 'install' | 'activate' | null =>
{
    if (!status || status.pro?.packagePending === true) {
        return null;
    }

    const step = proNextStep(getProState(status));

    return step === 'install' || step === 'activate' ? step : null;
};

/**
 * The one-time "Welcome to Pro" panel shows once Pro is active on
 * the site and until the owner dismisses it for good.
 */
export const proWelcomePending = (status?: ConnectStatus): boolean =>
    status?.proWelcomePending === true && isProActive(getProState(status));
