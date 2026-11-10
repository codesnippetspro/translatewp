import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { clearSetupDraft } from '@/shared/lib/setupDraft';

import { ConnectionPanel } from '@/shared/components/connection/ConnectionPanel';
import { restartSetupWizard } from '@/shared/api/admin';

/** Settings > Connection: the site connection, the setup wizard restart and the network link. */
export const SettingsConnection = () =>
{
    const queryClient = useQueryClient();
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const networkUrl = window.TranslateWP?.context?.network_settings_url;

    const restartMutation = useMutation({
        mutationFn: restartSetupWizard,
        onError: () => setErrorMessage(__('Restart failed. Try again.', 'translate')),
        onSuccess: async () =>
        {
            clearSetupDraft();
            await queryClient.invalidateQueries({ queryKey: ['connectStatus'] });
            window.location.reload();
        },
    });

    return (
        <div className="flex flex-col gap-10">

            <div className="flex flex-col gap-4">
                <h3 className="font-semibold text-[1.3em]! text-translatewp-neutral! m-0">{__('Site connection', 'translate')}</h3>
                <ConnectionPanel />
            </div>

            <div className="flex flex-col gap-4">
                <h3 className="font-semibold text-[1.3em]! text-translatewp-neutral! m-0">{__('Setup wizard', 'translate')}</h3>
                <p className="text-sm text-translatewp-muted! m-0">
                    {__('Reopen the setup wizard. Your languages, translation settings, and connection stay intact; the wizard opens prefilled.', 'translate')}
                </p>
                <div>
                    <Button
                        variant="secondary"
                        disabled={restartMutation.isPending}
                        isBusy={restartMutation.isPending}
                        onClick={() => restartMutation.mutate()}
                    >
                        {__('Restart setup wizard', 'translate')}
                    </Button>
                </div>
            </div>

            {networkUrl && (
                <div className="flex flex-col gap-4">
                    <h3 className="font-semibold text-[1.3em]! text-translatewp-neutral! m-0">{__('Network', 'translate')}</h3>
                    <p className="text-sm text-translatewp-muted! m-0">
                        {__('Choose which settings the network shares, locks or leaves to each subsite.', 'translate')}
                    </p>
                    <div>
                        <Button variant="secondary" href={networkUrl}>{__('Open network settings', 'translate')}</Button>
                    </div>
                </div>
            )}

            {errorMessage && <p className="text-sm text-translatewp-error m-0" role="alert">{errorMessage}</p>}
        </div>
    );
};
