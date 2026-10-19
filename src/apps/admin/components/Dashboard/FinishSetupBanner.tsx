import { reloadPage } from '@/shared/lib/reloadPage';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { dismissFinishSetup, restartSetupWizard } from '@/shared/api/admin';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';

/**
 * A connected site that has pages read but never finished setup lands on the
 * Overview, never a silent screen of zeros. This banner leads back to the
 * remaining setup steps, or is dismissed for the current user.
 */
export const FinishSetupBanner = () =>
{
    const queryClient = useQueryClient();
    const { data } = useQuery(getConnectStatusQueryOptions());

    const finish = useMutation({
        mutationFn: restartSetupWizard,
        onSuccess: () => { reloadPage(); },
    });

    const dismiss = useMutation({
        mutationFn: dismissFinishSetup,
        onSuccess: () => { void queryClient.invalidateQueries({ queryKey: ['connectStatus'] }); },
    });

    if (data?.setupUnfinished !== true) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2 rounded-sm border border-translatewp-border bg-translatewp-selection px-4 py-3" role="status">
            <p className="m-0 text-sm font-semibold text-translatewp-neutral">{__('Finish setting up', 'translate')}</p>
            <p className="m-0 text-sm text-translatewp-neutral">
                {__('Your site is connected and read, but setup is not finished. Pick up where you left off to start translating.', 'translate')}
            </p>
            <div className="flex flex-wrap items-center gap-3">
                <Button
                    variant="primary"
                    className="rounded-sm!"
                    isBusy={finish.isPending}
                    disabled={finish.isPending}
                    onClick={() => finish.mutate()}
                >
                    {__('Finish setting up', 'translate')}
                </Button>
                <Button
                    variant="tertiary"
                    disabled={dismiss.isPending}
                    onClick={() => dismiss.mutate()}
                >
                    {__('Dismiss for now', 'translate')}
                </Button>
            </div>
        </div>
    );
};
