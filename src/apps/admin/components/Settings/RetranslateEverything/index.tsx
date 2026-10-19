import { useState } from '@wordpress/element';
import { Button, TextControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getIndexPagesQueryOptions } from '@/shared/query-options/admin';
import { retranslateEverythingMutationOptions } from '@/shared/mutation-options/admin';

const CONFIRM_PHRASE = 'RETRANSLATE';

export const RetranslateEverything = () =>
{
    const queryClient = useQueryClient();
    const [confirmPhrase, setConfirmPhrase] = useState('');
    const { data } = useQuery(getIndexPagesQueryOptions());
    const totalSegments = data?.totals.segments ?? 0;

    const mutation = useMutation({
        ...retranslateEverythingMutationOptions(),
        onSuccess: async () =>
        {
            setConfirmPhrase('');
            await queryClient.invalidateQueries({ queryKey: ['indexPages'] });
        },
    });

    return (
        <div className="flex flex-col gap-4">
            <h3 className="font-semibold text-lg text-translatewp-neutral! m-0">
                {__('Retranslate everything', 'translate')}
            </h3>
            <p className="text-sm text-translatewp-muted! m-0">
                {__('Resend every indexed page for a fresh translation and replace the cached versions. Visitors keep seeing the current translations while the refresh runs.', 'translate')}
            </p>
            <p className="text-sm text-translatewp-muted! m-0">
                {sprintf(
                    /* translators: %d: number of text segments that will be resent. */
                    _n(
                        'This resends %d segment.',
                        'This resends %d segments.',
                        totalSegments,
                        'translate'
                    ),
                    totalSegments
                )}
            </p>
            <TextControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                label={__('Type "RETRANSLATE" to confirm', 'translate')}
                value={confirmPhrase}
                onChange={(value) => setConfirmPhrase(value)}
            />
            <div>
                <Button
                    variant="primary"
                    disabled={confirmPhrase !== CONFIRM_PHRASE || mutation.isPending}
                    isBusy={mutation.isPending}
                    onClick={() => mutation.mutate(confirmPhrase)}
                >
                    {__('Retranslate everything', 'translate')}
                </Button>
            </div>
            {mutation.isError && (
                <p className="text-sm text-translatewp-error m-0" role="alert">
                    {__('The retranslation could not be started. Try again.', 'translate')}
                </p>
            )}
        </div>
    );
};
