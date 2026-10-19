import { useQuery } from '@tanstack/react-query';
import { Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { Card } from '@/shared/components/adminSurface';
import { getQuotaQueryOptions } from '@/shared/api/quota';

export type QuotaLevel = 'critical' | 'low' | 'fair' | 'ample';

export const quotaLevel = (percentAvailable: number): QuotaLevel =>
{
    if (percentAvailable < 5) return 'critical';
    if (percentAvailable < 25) return 'low';
    if (percentAvailable < 65) return 'fair';
    return 'ample';
};

const BAR_COLOR: Record<QuotaLevel, string> = {
    critical: 'bg-translatewp-error',
    low: 'bg-translatewp-warning',
    fair: 'bg-translatewp-primary',
    ample: 'bg-translatewp-success',
};

export const QuotaCard = () =>
{
    const { data, isError } = useQuery(getQuotaQueryOptions());

    return (
        <Card title={__('Translations quota', 'translate')}>
            {data && (
                <div className="flex flex-col gap-3">
                    <span className="text-3xl font-semibold text-translatewp-neutral">
                        {sprintf(
                            /* translators: %d: percentage of the account's translations quota still available. */
                            __('%d%% available', 'translate'),
                            data.percentAvailable
                        )}
                    </span>
                    <div
                        className="h-2 w-full overflow-hidden rounded-full bg-translatewp-selection"
                        role="progressbar"
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={data.percentAvailable}
                        aria-label={__('Translations quota available', 'translate')}
                        data-quota-level={quotaLevel(data.percentAvailable)}
                    >
                        <div
                            className={`h-full rounded-full ${BAR_COLOR[quotaLevel(data.percentAvailable)]}`}
                            style={{ width: `${Math.max(0, Math.min(100, data.percentAvailable))}%` }}
                        />
                    </div>
                    <span className="text-sm text-translatewp-muted">
                        {__('Shared by every site on your account.', 'translate')}
                    </span>
                </div>
            )}
            {!data && isError && (
                <p className="m-0 text-sm text-translatewp-muted">{__('The quota could not be loaded.', 'translate')}</p>
            )}
            {!data && !isError && <Spinner />}
        </Card>
    );
};
