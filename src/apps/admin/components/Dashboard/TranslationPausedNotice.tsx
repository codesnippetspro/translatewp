import { __ } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';

import { getJobEventsQueryOptions } from '@/shared/query-options/admin';

const upgradeUrl = (): string =>
{
    const pro = (window as { translatewpPro?: { upgradeUrl?: unknown } }).translatewpPro;
    return typeof pro?.upgradeUrl === 'string' ? pro.upgradeUrl : '';
};

/**
 * The service records a job event when it refuses translation for the plan's
 * allowance or the site's hourly limit. While the latest event is such a
 * refusal the Overview says translation is paused, what resumes it, and where
 * to raise the allowance. Any other state shows nothing.
 */
export const TranslationPausedNotice = () =>
{
    const { data } = useQuery(getJobEventsQueryOptions(1));
    const latest = data?.events[0];
    if (!latest || latest.kind !== 'quota_block') {
        return null;
    }

    const upgrade = upgradeUrl();

    return (
        <div className="flex flex-col gap-2 rounded-sm border border-translatewp-border bg-translatewp-selection px-4 py-3" role="status">
            <p className="m-0 text-sm font-semibold text-translatewp-neutral">{__('Translation is paused', 'translate')}</p>
            <p className="m-0 text-sm text-translatewp-neutral">
                {__('The plan\'s allowance for this period is used up, or this site reached its hourly limit. Pages are served in the original language until it resumes on its own; nothing to retry.', 'translate')}
            </p>
            {upgrade !== '' && (
                <a href={upgrade} className="text-sm font-semibold text-translatewp-primary" target="_blank" rel="noreferrer">{__('Raise the allowance', 'translate')}</a>
            )}
        </div>
    );
};
