import { __ } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';

import { beginConnect } from '@/shared/api/connect';
import { useConnectReturnError } from '@/shared/lib/connect/connectReturnError';
import { ConnectTabStatus, useConnectStart } from '@/shared/lib/connect/useConnectStart';

export const ConnectReturnError = ({ connected = false }: { connected?: boolean }) =>
{
    const error = useConnectReturnError();
    const connect = useConnectStart(beginConnect);
    useEffect(() =>
    {
        if (error) document.getElementById('translatewp-connect-return-notice')?.remove();
    }, [error]);
    const message = error?.message ?? connect.errorMessage;
    if (!message && (connected || connect.tabState.kind === 'idle')) return null;
    const needsAccountAction = error?.code === 'site_conflict' || error?.code === 'address_taken';
    const canRetry = !needsAccountAction && error?.code !== 'permission_denied';
    return (
        <div className="flex flex-col gap-3 rounded-sm border border-solid border-translatewp-error bg-white p-4 text-sm text-translatewp-error" role="alert">
            {message && <p className="m-0" data-connect-error-message>{message}</p>}
            {message && (
                <div className="flex flex-wrap items-center gap-3">
                    {needsAccountAction && error && (
                        <a href={error.portalSitesUrl} target="_blank" rel="noopener noreferrer">
                            {__('Open your sites list', 'translate')}
                        </a>
                    )}
                    {canRetry && (
                        <Button variant="primary" onClick={connect.start} disabled={connect.isPending}>
                            {__('Try again', 'translate')}
                        </Button>
                    )}
                    {error && (
                        <a href={error.supportUrl} target="_blank" rel="noopener noreferrer">
                            {__('Contact support', 'translate')}
                        </a>
                    )}
                </div>
            )}
            {!connected && <ConnectTabStatus state={connect.tabState} />}
        </div>
    );
};
