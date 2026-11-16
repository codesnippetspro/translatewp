import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useUnsavedChangesGuard } from '@/apps/admin/hooks/useUnsavedChangesGuard';
import { useSaveOnChange } from '@/apps/admin/hooks/useSaveOnChange';
import { Button, Spinner } from '@wordpress/components';
import { Check } from 'lucide-react';
import { useQueries, useQueryClient } from '@tanstack/react-query';
import { canAdminister } from '@/shared/lib/access';

import { useNavigate } from '@/apps/admin/hooks/useNavigate';
import { useConnectReturnRefresh } from '@/apps/admin/hooks/useConnectReturnRefresh';
import { getAdminDataQueryOptions } from '@/shared/query-options/admin';
import { TRANSLATION_MODEL_SETTING_FIELDS, translationAffectingChanges, useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { getQuota } from '@/shared/api/quota';
import { getRetranslationEstimate, getSettingsRevision } from '@/shared/api/admin';
import { retranslateOnSaveMessage } from '@/shared/lib/retranslationScope';
import { ConfirmDialog } from '@/shared/components/confirmDialog';
import { useConfirmDialog } from '@/shared/components/confirmDialog/useConfirmDialog';
import { useAdminConfirmStore } from '@/shared/lib/confirmLanguageRetranslation';

import { Header } from '@/apps/admin/components/Header';
import { Sidebar } from '@/apps/admin/components/Sidebar';
import { DashboardPage } from '@/apps/admin/components/Dashboard/DashboardPage';
import { Lexicon } from '@/apps/admin/components/Lexicon';
import { SeoTools } from '@/apps/admin/components/SeoTools';
import { SettingsPage } from '@/apps/admin/components/SettingsPage';
import { SlugClashActions } from '@/apps/admin/components/SlugClashActions';

// Four pages. Every previous slug forwards to the page and section that
// owns it now (useNavigate), so old links and bookmarks keep working.
const ROUTES = ['/', '/lexicon', '/seo', '/settings'];
const SAVED_MESSAGE_MS = 3000;
const TOAST_CLASS = 'fixed! bottom-6 end-6 z-50 flex max-w-[92vw] items-center gap-3 rounded-lg px-4 py-3 text-sm shadow-[0_4px_16px_rgba(0,0,0,0.12)]';

// The settings groups the form is loaded from, in the order the store reads
// them. A user who manages translations by grant is not given the
// administrator's groups, so those are not asked for and load as their defaults.
const SETTINGS_GROUPS = ['languages.list', 'languages.switcher', 'languages.seo', 'translations.settings', 'translations.context', 'translations.style', 'setup', 'advanced'] as const;
const ADMINISTRATOR_GROUPS: readonly string[] = ['languages.seo', 'setup', 'advanced'];

export const adminSettingsQueries = (administers: boolean) =>
    SETTINGS_GROUPS.map(group => administers || !ADMINISTRATOR_GROUPS.includes(group)
        ? getAdminDataQueryOptions(group)
        : { ...getAdminDataQueryOptions(group), queryFn: async () => null });

export const Admin = () =>
{
    const { route } = useNavigate();
    const queryClient = useQueryClient();

    useConnectReturnRefresh();

    const setLoading = useAdminSettingsStore(state => state.setLoading);
    const initializeFromApiData = useAdminSettingsStore(state => state.initializeFromApiData);
    const isInitialized = useAdminSettingsStore(state => state.isInitialized);
    const isLoading = useAdminSettingsStore(state => state.isLoading);
    const isSaving = useAdminSettingsStore(state => state.isSaving);
    const hasChanges = useAdminSettingsStore(state => state.hasChanges);
    const saveFailed = useAdminSettingsStore(state => state.saveFailed);
    const saveError = useAdminSettingsStore(state => state.saveError);
    const slugClash = useAdminSettingsStore(state => state.slugClash);
    const saveSettings = useAdminSettingsStore(state => state.saveSettings);
    const savedAt = useAdminSettingsStore(state => state.savedAt);
    const staleSettings = useAdminSettingsStore(state => state.staleSettings);
    const setRevision = useAdminSettingsStore(state => state.setRevision);
    const beginReload = useAdminSettingsStore(state => state.beginReload);
    const { requestConfirmation, confirmDialogProps } = useConfirmDialog();
    const setAddonConfirm = useAdminConfirmStore(state => state.setRequest);
    useEffect(() =>
    {
        setAddonConfirm(requestConfirmation);
        return () => setAddonConfirm(null);
    }, [requestConfirmation, setAddonConfirm]);
    useUnsavedChangesGuard(hasChanges);
    const [showSaved, setShowSaved] = useState(false);

    useEffect(() =>
    {
        if (savedAt === null) return undefined;
        setShowSaved(true);
        const timer = setTimeout(() => setShowSaved(false), SAVED_MESSAGE_MS);
        return () => clearTimeout(timer);
    }, [savedAt]);

    // The revision the form was loaded from travels with every save.
    useEffect(() =>
    {
        let cancelled = false;
        getSettingsRevision()
            .then(({ revision }) => { if (!cancelled) setRevision(revision); })
            .catch(() => undefined);
        return () => { cancelled = true; };
    }, [setRevision]);

    const queryResults = useQueries({ queries: adminSettingsQueries(canAdminister()) });

    const [
        languagesListResult,
        switcherSettingsResult,
        seoSettingsResult,
        translationsSettingsResult,
        translationsContextResult,
        translationsStyleResult,
        setupResult,
        advancedResult,
    ] = queryResults;
    const isBootstrapping = queryResults.some(result => result.isPending);
    const isReadyToInitialize = queryResults.every(result => result.isSuccess);
    // A settings query that failed leaves the form uninitialised; the page
    // then says so and offers a reload instead of waiting on a spinner.
    const bootstrapFailed = !isInitialized && !isBootstrapping && queryResults.some(result => result.isError);

    useEffect(() =>
    {
        setLoading((!isInitialized || isBootstrapping) && !bootstrapFailed);
    }, [isBootstrapping, isInitialized, bootstrapFailed, setLoading]);

    const retryBootstrap = () =>
    {
        for (const result of queryResults) {
            if (result.isError) {
                void result.refetch();
            }
        }
    };

    useEffect(() =>
    {
        if (isInitialized || !isReadyToInitialize) {
            return;
        }

        initializeFromApiData({
            languagesList: languagesListResult.data,
            switcher: switcherSettingsResult.data,
            seo: seoSettingsResult.data,
            translationsSettings: translationsSettingsResult.data,
            translationsContext: translationsContextResult.data,
            translationsStyle: translationsStyleResult.data,
            setup: setupResult.data,
            advanced: advancedResult.data,
        });
    }, [
        initializeFromApiData,
        isInitialized,
        isReadyToInitialize,
        languagesListResult.data,
        switcherSettingsResult.data,
        seoSettingsResult.data,
        translationsSettingsResult.data,
        translationsContextResult.data,
        translationsStyleResult.data,
        setupResult.data,
        advancedResult.data,
    ]);

    // A save that changes how the site is translated confirms first
    // and states the pages and translation languages it translates again.
    const confirmRetranslation = async (): Promise<boolean> =>
    {
        const { settings, persistedSettings } = useAdminSettingsStore.getState();
        const affecting = translationAffectingChanges(settings, persistedSettings);
        if (!affecting.length) {
            return true;
        }

        const [estimate, quota] = await Promise.all([
            getRetranslationEstimate().catch(() => null),
            getQuota().catch(() => null),
        ]);

        return requestConfirmation({
            title: __('Translate the site again', 'translate'),
            message: retranslateOnSaveMessage(estimate, quota),
            confirmLabel: __('Save and retranslate', 'translate'),
            tone: 'proceed',
        });
    };

    const handleReload = async () =>
    {
        // The settings are fetched again first; only then is the form told to
        // initialise from them, so it never repopulates from stale data.
        await queryClient.refetchQueries({ queryKey: ['getAdminData'] });
        const { revision } = await getSettingsRevision().catch(() => ({ revision: null }));
        setRevision(revision);
        beginReload();
    };

    // Cancel returns only the retranslating controls to their saved values;
    // any other change waiting to save still saves.
    const revertRetranslatingChanges = () =>
    {
        const { settings, persistedSettings, revertKey, setSetting } = useAdminSettingsStore.getState();
        for (const key of translationAffectingChanges(settings, persistedSettings)) {
            if (key !== 'translations.settings') {
                revertKey(key);
                continue;
            }
            const saved = persistedSettings['translations.settings'];
            setSetting('translations.settings', previous => TRANSLATION_MODEL_SETTING_FIELDS.reduce(
                (next, field) => ({ ...next, [field]: saved[field] }),
                previous
            ));
        }
    };

    const handleSave = async () =>
    {
        if (!(await confirmRetranslation())) {
            revertRetranslatingChanges();
        }

        const dirtyKeys = await saveSettings();

        if (dirtyKeys.length > 0)
        {
            await Promise.all(
                dirtyKeys.map((key) =>
                    queryClient.invalidateQueries({ queryKey: ['getAdminData', key] })
                )
            );
        }
    };

    useSaveOnChange(handleSave);

    return (
        <div className='translatewp-app-root w-full flex flex-col gap-6 pb-24'>
            <Header />

            <div className='flex items-start gap-6 px-6 [@media(max-width:782px)]:flex-col [@media(max-width:782px)]:gap-4 [@media(max-width:782px)]:px-4'>
                <Sidebar />
                <div className='min-w-0 flex-1 overflow-clip rounded-lg border border-solid border-translatewp-border bg-white [@media(max-width:782px)]:w-full'>
                    {/* A page draws only from loaded settings: an empty store must never
                        show as a language list or a connection state for a moment. */}
                    { isLoading && (
                        <div className='flex items-center justify-center p-16' role='status' aria-label={__('Loading', 'translate')}>
                            <Spinner />
                        </div>
                    ) }
                    { bootstrapFailed && (
                        <div className='flex flex-col items-start gap-3 p-8' role='alert'>
                            <p className='m-0 text-sm text-translatewp-neutral'>{__('The TranslateWP settings could not be loaded from this site.', 'translate')}</p>
                            <Button variant='secondary' className='rounded-sm!' onClick={retryBootstrap}>{__('Try again', 'translate')}</Button>
                        </div>
                    ) }
                    { !isLoading && !bootstrapFailed && route === '/' && <DashboardPage /> }
                    { !isLoading && !bootstrapFailed && route === '/lexicon' && <Lexicon /> }
                    { !isLoading && !bootstrapFailed && route === '/seo' && <SeoTools /> }
                    { !isLoading && !bootstrapFailed && route === '/settings' && <SettingsPage /> }
                    { !isLoading && !bootstrapFailed && !ROUTES.includes(route) && <DashboardPage /> }
                </div>
            </div>

            <ConfirmDialog {...confirmDialogProps} />
            {staleSettings ? (
                <div className={`${TOAST_CLASS} border border-translatewp-border bg-white text-translatewp-neutral`} role='alert'>
                    <span>{__('These settings were changed in another tab. Reload to see the latest; your changes here are kept until you do.', 'translate')}</span>
                    <Button variant='secondary' className='rounded-sm! text-sm! w-fit shrink-0' disabled={isSaving} onClick={() => void handleReload()}>
                        {__('Reload settings', 'translate')}
                    </Button>
                </div>
            ) : saveFailed ? (
                <div className={`${TOAST_CLASS} border border-translatewp-error bg-white text-translatewp-neutral`} role='alert'>
                    <span>
                        {saveError
                            /* translators: %s: the reason the server gave for refusing the change. */
                            ? sprintf(__('Your change could not be saved. %s', 'translate'), saveError)
                            : __('Your change could not be saved.', 'translate')}
                    </span>
                    {slugClash ? <SlugClashActions /> : (
                        <Button variant='secondary' className='rounded-sm! text-sm! w-fit shrink-0' disabled={isSaving} isBusy={isSaving} onClick={() => void handleSave()}>
                            {__('Try again', 'translate')}
                        </Button>
                    )}
                </div>
            ) : showSaved ? (
                <div className={`${TOAST_CLASS} bg-translatewp-success font-semibold text-white`} role='status'>
                    <Check size={16} aria-hidden='true' />
                    <span>{__('Saved', 'translate')}</span>
                </div>
            ) : null}
        </div>
    );
};
