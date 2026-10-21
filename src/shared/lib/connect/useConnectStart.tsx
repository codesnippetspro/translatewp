import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { assignLocation, isFramed } from '@/shared/lib/connect/framed';

type StartConnect = () => Promise<{ redirectUrl: string }>;

export type ConnectTabState =
    | { kind: 'idle' }
    | { kind: 'new-tab' }
    | { kind: 'blocked'; url: string };

export const useConnectStart = (mutationFn: StartConnect) =>
{
    const queryClient = useQueryClient();
    const [tabState, setTabState] = useState<ConnectTabState>({ kind: 'idle' });
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const mutation = useMutation({ mutationFn, networkMode: 'always' });

    useEffect(() =>
    {
        if ('idle' === tabState.kind) {
            return undefined;
        }
        const recheck = () =>
        {
            if ('visible' === document.visibilityState) {
                void queryClient.invalidateQueries({ queryKey: ['connectStatus'] });
            }
        };
        document.addEventListener('visibilitychange', recheck);
        window.addEventListener('focus', recheck);
        return () =>
        {
            document.removeEventListener('visibilitychange', recheck);
            window.removeEventListener('focus', recheck);
        };
    }, [tabState.kind, queryClient]);

    const start = () =>
    {
        setErrorMessage(null);
        setTabState({ kind: 'idle' });

        const framed = isFramed();
        // Opened before the async request so the browser still treats it as the click's own popup.
        const tab = framed ? window.open('', '_blank') : null;
        if (tab) {
            tab.opener = null;
        }

        mutation.mutate(undefined, {
            onSuccess: ({ redirectUrl }) =>
            {
                if (!framed) {
                    assignLocation(redirectUrl);
                    return;
                }
                if (tab && !tab.closed) {
                    tab.location.href = redirectUrl;
                    setTabState({ kind: 'new-tab' });
                    return;
                }
                setTabState({ kind: 'blocked', url: redirectUrl });
            },
            onError: () =>
            {
                tab?.close();
                setErrorMessage(__('Connection could not be started. Try again.', 'translate'));
            },
        });
    };

    return { start, isPending: mutation.isPending, tabState, errorMessage };
};

export const ConnectTabStatus = ({ state }: { state: ConnectTabState }) =>
{
    if ('new-tab' === state.kind) {
        return (
            <p className="text-sm text-translatewp-muted! m-0" role="status">
                {__('Finish connecting in the new tab. This page picks up the connection when you come back.', 'translate')}
            </p>
        );
    }
    if ('blocked' === state.kind) {
        return (
            <p className="text-sm text-translatewp-neutral! m-0" role="alert">
                {__('Your browser blocked the sign-in tab.', 'translate')}{' '}
                <a href={state.url} target="_blank" rel="noopener noreferrer">{__('Open the sign-in page', 'translate')}</a>
            </p>
        );
    }
    return null;
};
