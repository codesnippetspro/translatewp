import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, TextControl, ToggleControl } from '@wordpress/components';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { clearSetupDraft } from '@/shared/lib/setupDraft';

import { clearSiteCache, resetAllPluginData } from '@/shared/api/admin';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { useConfirmDialog } from '@/shared/components/confirmDialog/useConfirmDialog';
import { ConfirmDialog } from '@/shared/components/confirmDialog';
import { ManagedByNetwork } from '@/shared/components/managedByNetwork';

const CONFIRM_PHRASE = 'RESET';

/** Settings > Advanced: the translation cache, the other-plugins notice, the uninstall rule and the full reset. */
export const SettingsAdvanced = () =>
{
    const queryClient = useQueryClient();
    const [confirmPhrase, setConfirmPhrase] = useState('');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [cacheCleared, setCacheCleared] = useState(false);
    const deleteOnUninstall = useAdminSettingsStore(state => state.settings['advanced'].delete_on_uninstall);
    const setSetting = useAdminSettingsStore(state => state.setSetting);
    const { requestConfirmation, confirmDialogProps } = useConfirmDialog();
    const exportUrl = window.TranslateWP?.context?.export_url ?? '';
    const otherTranslationPlugins = window.TranslateWP?.context?.other_translation_plugins ?? [];

    const clearCacheMutation = useMutation({
        mutationFn: clearSiteCache,
        onError: () => setErrorMessage(__('The cache could not be cleared. Try again.', 'translate')),
        onSuccess: () =>
        {
            setCacheCleared(true);
            void queryClient.invalidateQueries({ queryKey: ['dashboardMetrics'] });
        },
    });

    const resetMutation = useMutation({
        mutationFn: resetAllPluginData,
        onError: () => setErrorMessage(__('Reset failed. Try again.', 'translate')),
        onSuccess: async () =>
        {
            clearSetupDraft();
            await queryClient.invalidateQueries({ queryKey: ['connectStatus'] });
            window.location.reload();
        },
    });

    const handleClearCache = async () =>
    {
        const confirmed = await requestConfirmation({
            title: __('Clear the translation cache?', 'translate'),
            message: __('Every page is translated again on its next visit, which counts towards your usage.', 'translate'),
            confirmLabel: __('Clear cache', 'translate'),
            isDestructive: true,
        });
        if (confirmed) clearCacheMutation.mutate();
    };

    return (
        <div className="flex flex-col gap-10">
            <div className="flex flex-col gap-4">
                <h3 className="font-semibold text-lg text-translatewp-neutral! m-0">{__('Translation cache', 'translate')}</h3>
                <p className="text-sm text-translatewp-muted! m-0">
                    {__('Clearing the cache forces a fresh translation of every page on its next visit. Use it after a change the site did not pick up on its own.', 'translate')}
                </p>
                <div className="flex items-center gap-3">
                    <Button
                        variant="secondary"
                        isDestructive
                        disabled={clearCacheMutation.isPending}
                        isBusy={clearCacheMutation.isPending}
                        onClick={() => void handleClearCache()}
                    >
                        {__('Clear translation cache', 'translate')}
                    </Button>
                    {cacheCleared && <span className="text-sm text-translatewp-muted!" role="status">{__('Cache cleared. Each page translates again the next time someone opens it.', 'translate')}</span>}
                </div>
            </div>

            {otherTranslationPlugins.length > 0 && (
            <div id="other-translation-plugins" className="flex flex-col gap-4">
                <h3 className="font-semibold text-lg text-translatewp-neutral! m-0">{__('Other translation plugins', 'translate')}</h3>
                <div className="flex flex-col gap-3 rounded-sm border border-translatewp-border bg-translatewp-selection px-4 py-3" role="note">
                <p className="text-sm text-translatewp-neutral! m-0">
                    {__('TranslateWP keeps working when another translation plugin is active, and changes nothing in it unless you ask. Two translation systems on one site can show mixed languages, duplicate language addresses and two language switchers.', 'translate')}
                </p>
                <p className="text-sm text-translatewp-neutral! m-0">
                    {__('For the clearest result, use one translation system per site. The notice TranslateWP shows while another one is active has a button that deactivates that plugin in one click, for users who may deactivate plugins. If you keep both, turn off the other plugin\'s language switcher and its translated addresses on the pages TranslateWP translates.', 'translate')}
                </p>
                </div>
            </div>
            )}

            {exportUrl !== '' && (
                <div className="flex flex-col gap-4" data-twp-export>
                    <h3 className="font-semibold text-lg text-translatewp-neutral! m-0">{__('Your data', 'translate')}</h3>
                    <p className="text-sm text-translatewp-muted! m-0">
                        {__('Download this site\'s translations as one file: every page address, language, source text and translation. The file opens in any spreadsheet and is yours to keep. Available on every plan.', 'translate')}
                    </p>
                    <div>
                        <Button variant="secondary" href={exportUrl}>
                            {__('Export your data', 'translate')}
                        </Button>
                    </div>
                </div>
            )}

            <div className="flex flex-col gap-4">
                <h3 className="font-semibold text-lg text-translatewp-neutral! m-0">{__('Uninstall', 'translate')}</h3>
                <ManagedByNetwork group="uninstall">
                <ToggleControl
                    label={__('Delete all data on uninstall', 'translate')}
                    help={__(
                        'When enabled, uninstalling the plugin also deletes every option, translated term, metric, and scheduled task it created. Off by default: your data survives uninstall.',
                        'translate'
                    )}
                    checked={deleteOnUninstall}
                    onChange={(value) => setSetting('advanced', previous => ({ ...previous, delete_on_uninstall: value }))}
                />
                </ManagedByNetwork>
            </div>

            <div className="flex flex-col gap-4">
                <h3 className="font-semibold text-lg text-translatewp-neutral! m-0">{__('Danger zone', 'translate')}</h3>
                <ManagedByNetwork group="account">
                <p className="text-sm text-translatewp-muted! m-0">
                    {__('Disconnect from your TranslateWP account and delete every option, translated term, metric, and scheduled task this plugin created. This cannot be undone.', 'translate')}
                </p>
                <TextControl
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                    label={__('Type "RESET" to confirm', 'translate')}
                    value={confirmPhrase}
                    onChange={(value) => setConfirmPhrase(value)}
                />
                <div>
                    <Button
                        variant="primary"
                        isDestructive
                        disabled={confirmPhrase !== CONFIRM_PHRASE || resetMutation.isPending}
                        isBusy={resetMutation.isPending}
                        onClick={() => resetMutation.mutate()}
                    >
                        {__('Reset all plugin data', 'translate')}
                    </Button>
                </div>
                </ManagedByNetwork>
            </div>

            {errorMessage && <p className="text-sm text-translatewp-error m-0" role="alert">{errorMessage}</p>}
            <ConfirmDialog {...confirmDialogProps} />
        </div>
    );
};
