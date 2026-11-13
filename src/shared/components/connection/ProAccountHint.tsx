import { __, sprintf } from '@wordpress/i18n';

import type { ConnectStatus } from '@/shared/api/connect';
import { canAdminister } from '@/shared/lib/access';

export const ProAccountHint = ({ status }: { status?: ConnectStatus }) =>
{
    const pro = status?.pro;
    if (!canAdminister() || !status?.connected || pro?.plan !== 'free' || pro.entitled || (!pro.installed && !pro.active)) {
        return null;
    }

    const label = status.accountLabel.trim();
    const supportUrl = window.TranslateWP?.context?.support_url;
    return (
        <div className="flex flex-col gap-2 text-sm text-translatewp-muted!" data-pro-account-hint>
            <p className="m-0 text-sm text-translatewp-muted!">
                {label ? sprintf(
                    /* translators: %s: the connected account's display name or email. */
                    __('This site is connected as %s, which is on the Free plan. Bought Orbital with another email? Connect this site with that account.', 'translate'),
                    label
                ) : __('This site is connected to an account on the Free plan. Bought Orbital with another email? Connect this site with that account.', 'translate')}
            </p>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                {status.portalSitesUrl && (
                    <a href={status.portalSitesUrl} target="_blank" rel="noopener noreferrer" className="text-sm text-translatewp-primary! underline">
                        {__('Open your sites list', 'translate')}
                    </a>
                )}
                {supportUrl && (
                    <a href={supportUrl} target="_blank" rel="noopener noreferrer" className="text-sm text-translatewp-primary! underline">
                        {__('Contact support', 'translate')}
                    </a>
                )}
            </div>
        </div>
    );
};
