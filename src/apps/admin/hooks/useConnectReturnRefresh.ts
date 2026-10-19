import { useEffect } from '@wordpress/element';
import { useQueryClient } from '@tanstack/react-query';

const CONNECT_NOTICE_QUERY_ARG = 'translatewp_connect_notice';

// The Connect flow returns to the admin with a server-rendered notice while the
// persisted connect status still holds the pre-connect value, so the pairing
// card and everything derived from the status read the stale value until the
// next fetch. Refetch the whole status query on that return, including a query
// that is not mounted yet, so the card body matches the notice without a reload.
export const useConnectReturnRefresh = (): void =>
{
    const queryClient = useQueryClient();

    useEffect(() =>
    {
        const hasConnectNotice = new URLSearchParams(window.location.search).has(CONNECT_NOTICE_QUERY_ARG);

        if (!hasConnectNotice) {
            return;
        }

        void queryClient.invalidateQueries({ queryKey: ['connectStatus'], refetchType: 'all' });
    }, [queryClient]);
};
