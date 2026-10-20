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
                        title={data.accountLabel}
                        className="inline-flex min-h-8 max-w-full items-center truncate whitespace-nowrap rounded-full border border-twp-neutral-200 bg-twp-neutral-0 px-3.5 text-sm font-medium text-twp-neutral-800! no-underline! transition-colors hover:border-translatewp-primary/50 hover:bg-translatewp-primary/5 hover:text-translatewp-primary! focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-translatewp-primary"
                    >
                        {data.accountLabel}
                    </a>
                )
                : data.accountLabel
            : null;

        return (
            <p className="m-0 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-sm text-translatewp-neutral!">
                {label
                    ? <>{__('Connected as', 'translate')} {label}</>
                    : __('This site is connected to your TranslateWP account.', 'translate')}
            </p>
        );
    }

    return <p className="text-sm text-translatewp-muted! m-0">{__('Not connected', 'translate')}</p>;
};
