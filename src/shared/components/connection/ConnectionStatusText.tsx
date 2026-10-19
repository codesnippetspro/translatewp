import { __ } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';

import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';

export const ConnectionStatusText = () =>
{
    const { data, isLoading } = useQuery(getConnectStatusQueryOptions());

    if (isLoading)
    {
        return <p className="text-sm text-translatewp-muted! m-0">{__('Checking connection…', 'translate')}</p>;
    }

    if (data?.connectionStatus === 'invalidated')
    {
        return <p className="text-sm text-translatewp-muted! m-0">
            {__("This site's credentials were invalidated.", 'translate')}
        </p>;
    }

    if (data?.connected)
    {
        const label = data.accountLabel
            ? data.portalSiteUrl
                ? (
                    <a
                        href={data.portalSiteUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center rounded-full border border-translatewp-border bg-translatewp-selection px-2 py-0.5 text-xs text-translatewp-primary!"
                    >
                        {data.accountLabel}
                    </a>
                )
                : data.accountLabel
            : null;

        return (
            <p className="text-sm text-translatewp-neutral! m-0">
                {label
                    ? <>{__('Connected as', 'translate')} {label}</>
                    : __('This site is connected to your TranslateWP account.', 'translate')}
            </p>
        );
    }

    return <p className="text-sm text-translatewp-muted! m-0">{__('Not connected', 'translate')}</p>;
};
