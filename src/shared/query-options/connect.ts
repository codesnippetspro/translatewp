import { queryOptions } from '@tanstack/react-query';

import { getConnectStatus, type ConnectStatus } from '@/shared/api/connect';
import { canAdminister } from '@/shared/lib/access';
import { getProState } from '@/shared/lib/pro/proState';

/**
 * What a user who manages translations by grant is told about the
 * connection: that the site is connected (their screens exist only then) and
 * the plan state the page already carries. The account, the registered
 * address and the portal links are an administrator's, so the status route is
 * not asked.
 */
export const grantedUserConnectStatus = (): ConnectStatus =>
{
    const pro = getProState();

    return {
        connectionStatus: 'connected',
        connected: true,
        siteId: null,
        accountLabel: '',
        registeredUrl: '',
        addressChange: null,
        portalSitesUrl: '',
        portalSiteUrl: '',
        pro: {
            plan: pro.entitled ? 'pro' : 'free',
            entitled: pro.entitled,
            installed: pro.installed,
            active: pro.active,
            installedVersion: pro.version === '' ? null : pro.version,
            latestVersion: null,
            packagePending: false,
            upgrade: null,
            accountUrl: '',
            updateAvailable: false,
            updateUrl: null,
            capabilities: { seo: pro.entitled, custom_style: pro.entitled, byok: pro.entitled },
        },
    };
};

export const getConnectStatusQueryOptions = () => queryOptions({
    queryKey: ['connectStatus'],
    queryFn: () => canAdminister() ? getConnectStatus() : Promise.resolve(grantedUserConnectStatus()),
    staleTime: 0,
});
