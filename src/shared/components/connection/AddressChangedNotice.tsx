import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { useMutation, useQuery } from '@tanstack/react-query';

import { beginConnectMutationOptions, reconnectSiteMutationOptions } from '@/shared/mutation-options/connect';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';
import { ManagedByNetworkNote } from '@/shared/components/managedByNetwork';
import { isGroupLocked } from '@/shared/lib/settingsScope';

export const AddressChangedNotice = () =>
{
    const { data } = useQuery(getConnectStatusQueryOptions());
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const reconnectMutation = useMutation({
        ...reconnectSiteMutationOptions(),
        onError: () => setErrorMessage(__('Connection could not be started. Try again.', 'translate')),
        onSuccess: (result) => window.location.assign(result.redirectUrl),
    });

    const newSiteMutation = useMutation({
        ...beginConnectMutationOptions(),
        onError: () => setErrorMessage(__('Connection could not be started. Try again.', 'translate')),
        onSuccess: (result) => window.location.assign(result.redirectUrl),
    });

    const invalidated = data?.connectionStatus === 'invalidated';

    if (!invalidated && !data?.addressChange) {
        return null;
    }

    const inFlight = reconnectMutation.isPending || newSiteMutation.isPending;

    return (
        <div
            className="flex flex-col gap-3 rounded-sm border border-translatewp-border bg-white p-4"
            data-testid="address-changed-notice"
        >
            {invalidated ? (
                <p className="text-sm text-translatewp-neutral! m-0">
                    {__("The service no longer recognises this site's credentials. Reconnect it to keep it on the same account, or connect it as a new site if this is a copy.", 'translate')}
                </p>
            ) : (
                <>
                    <p className="text-sm text-translatewp-neutral! m-0">
                        {sprintf(
                            /* translators: 1: registered site address, 2: current site address */
                            __('The site address changed from %1$s to %2$s.', 'translate'),
                            data!.addressChange!.from,
                            data!.addressChange!.to
                        )}
                    </p>
                    <p className="text-sm text-translatewp-muted! m-0">
                        {__('Translation keeps running until you choose.', 'translate')}
                    </p>
                </>
            )}
            {isGroupLocked('account') ? <ManagedByNetworkNote /> : (
            <div className="flex flex-wrap gap-3">
                <Button
                    variant="primary"
                    className="rounded-sm!"
                    disabled={inFlight}
                    isBusy={reconnectMutation.isPending}
                    onClick={() =>
                    {
                        setErrorMessage(null);
                        reconnectMutation.mutate();
                    }}
                >
                    {__('Reconnect this site', 'translate')}
                </Button>
                <Button
                    variant="secondary"
                    className="rounded-sm!"
                    disabled={inFlight}
                    isBusy={newSiteMutation.isPending}
                    onClick={() =>
                    {
                        setErrorMessage(null);
                        newSiteMutation.mutate();
                    }}
                >
                    {__('Connect as a new site', 'translate')}
                </Button>
            </div>
            )}
            {errorMessage && (
                <p className="text-sm text-translatewp-error m-0" role="alert">{errorMessage}</p>
            )}
        </div>
    );
};
