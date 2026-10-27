import { useState } from '@wordpress/element';
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Button, ToggleControl } from "@wordpress/components";
import { __, sprintf } from '@wordpress/i18n';

import { getStaleTranslations } from '@/shared/api/admin';
import { applyLocalizerNowMutationOptions } from '@/shared/mutation-options/admin';
import { getLanguageNameFromLocale } from '@/shared/lib/languages';
import { ConfirmDialog } from '@/shared/components/confirmDialog';
import { useConfirmDialog } from '@/shared/components/confirmDialog/useConfirmDialog';

import { useAdminSettingsStore, type TranslationsSettings as TranslationsSettingsType } from '@/apps/admin/stores/settingsStore';
import { getWordPressPostTypesQueryOptions, getWordPressCategoriesQueryOptions } from '@/shared/query-options/wordpress';
import { CheckboxGrid } from "@/shared/components/form/checkboxGrid";
import { ManagedByNetwork } from "@/shared/components/managedByNetwork";
import { ProMarker } from '@/shared/components/pro/ProMarker';
import { ProLock } from '@/shared/components/pro/ProLock';
import { getProState, isProActive } from '@/shared/lib/pro/proState';
import { WhoCanManage } from '@/apps/admin/components/Translations/Settings/WhoCanManage';
import { managerCan } from '@/shared/lib/access';

const ToggleLabel = ({ title, description }: { title: string; description: string }) => (
    <div className='flex flex-col gap-1'>
        <p className='font-bold text-sm! text-translatewp-neutral!'>{title}</p>
        <span className='text-sm text-translatewp-muted!'>{description}</span>
    </div>
);

export const TranslationsSettings = () =>
{
    const settings = useAdminSettingsStore(state => state.settings['translations.settings']);
    const proActive = isProActive(getProState());
    const setSetting = useAdminSettingsStore(state => state.setSetting);

    const updateSetting = <K extends keyof TranslationsSettingsType,>(key: K, value: TranslationsSettingsType[K]) =>
    {
        setSetting('translations.settings', previous =>
        {
            if (previous[key] === value) {
                return previous;
            }

            return {
                ...previous,
                [key]: value,
            };
        });
    };

    const { data: postTypes } = useQuery(getWordPressPostTypesQueryOptions);
    const { data: categories } = useQuery(getWordPressCategoriesQueryOptions);

    // The date, time and unit toggles are a version, not part of the
    // translation fingerprint. Saving them changes nothing until the pages
    // translated under the previous formats are retranslated on request.
    const queryClient = useQueryClient();
    const [applyNotice, setApplyNotice] = useState<string | null>(null);
    const { requestConfirmation, confirmDialogProps } = useConfirmDialog();
    const { data: stale } = useQuery({
        queryKey: ['getStaleTranslations'],
        queryFn: getStaleTranslations,
        refetchInterval: 30000,
    });
    const staleLocalizer = stale?.localizer ?? {};
    const stalePages = Object.values(staleLocalizer).reduce((sum, count) => sum + count, 0);
    const staleLanguages = Object.keys(staleLocalizer).map(locale => getLanguageNameFromLocale(locale)).join(', ');
    const applyLocalizerMutation = useMutation({
        ...applyLocalizerNowMutationOptions(),
        onSuccess: async (result) => {
            setApplyNotice(
                result.requeued > 0
                    /* translators: %s: number of pages. */
                    ? sprintf(__('%s pages are being translated again with the current formats.', 'translate'), result.requeued.toLocaleString())
                    : __('Every translated page already uses the current formats.', 'translate')
            );
            await queryClient.invalidateQueries({ queryKey: ['getStaleTranslations'] });
        },
        onError: () => {
            setApplyNotice(__('The retranslation could not be queued. Try again in a moment.', 'translate'));
        },
    });
    const handleApplyLocalizer = async () =>
    {
        setApplyNotice(null);
        const message = sprintf(
            /* translators: 1: number of pages, 2: language names. */
            __('%1$s pages in %2$s were translated with the previous date, time and unit settings. Translating them again counts towards your usage.', 'translate'),
            stalePages.toLocaleString(),
            staleLanguages
        );
        const confirmed = await requestConfirmation({
            title: __('Retranslate with the current formats?', 'translate'),
            message,
            confirmLabel: __('Retranslate', 'translate'),
            isDestructive: true,
        });
        if (confirmed) {
            applyLocalizerMutation.mutate();
        }
    };

    return (
        <div className="flex flex-col gap-6" data-translation-settings>
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2" data-content-behaviour>
            <div className="rounded-lg border border-translatewp-border p-5 flex flex-col gap-5">
            <h3 className="text-sm! font-bold! text-translatewp-neutral! m-0!">{__('Content', 'translate')}</h3>

            <ToggleControl label={
                <ToggleLabel title={__('Translate all public content', 'translate')} description={__('Every public page is translated. Turn it off to exclude post types or categories, for example your shop products.', 'translate')} />} checked={settings.translate_all_content} onChange={(value) => updateSetting('translate_all_content', value as boolean)} className='[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!' />

            {!settings.translate_all_content && (
                <>
                    <CheckboxGrid label={__('Post types to exclude', 'translate')} options={postTypes ?? []} selected={settings.post_types} onChange={(value) => updateSetting('post_types', value as string[])} />
                    <CheckboxGrid label={__('Categories to exclude', 'translate')} options={categories ?? []} selected={settings.categories} onChange={(value) => updateSetting('categories', value as string[])} />
                    <hr className="border-translatewp-border" />
                </>
            )}

            <ManagedByNetwork group="content_areas">
            <div className="flex flex-col gap-5" data-page-areas>
            <ToggleControl label={
                <ToggleLabel title={__('Translate comments', 'translate')} description={__('Off: visitor comments and the comment form stay as written.', 'translate')} />} checked={settings.translate_comments !== false} onChange={(value) => updateSetting('translate_comments', value as boolean)} className='[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!' />
            <ToggleControl label={
                <ToggleLabel title={__('Translate menus', 'translate')} description={__('Off: navigation menus keep their original labels.', 'translate')} />} checked={settings.translate_menus !== false} onChange={(value) => updateSetting('translate_menus', value as boolean)} className='[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!' />
            <ToggleControl label={
                <ToggleLabel title={__('Translate widgets', 'translate')} description={__('Off: sidebar and footer widgets stay as written.', 'translate')} />} checked={settings.translate_widgets !== false} onChange={(value) => updateSetting('translate_widgets', value as boolean)} className='[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!' />
            </div>
            </ManagedByNetwork>

            <ToggleControl label={
                <ToggleLabel title={__('Translate password-protected and private pages', 'translate')} description={__('Off: these pages stay in their original language and their text is never sent to the translation service. A single page can also be excluded in its editor.', 'translate')} />} checked={settings.translate_protected_pages === true} onChange={(value) => updateSetting('translate_protected_pages', value as boolean)} className='[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!' />

{/* Browser detection lives in Settings > Languages > Visitor language. */}

            </div>

            <div className="rounded-lg border border-translatewp-border p-5 flex flex-col gap-5">
            <h3 className="text-sm! font-bold! text-translatewp-neutral! m-0!">{__('Behaviour', 'translate')}</h3>

            <ManagedByNetwork group="translation_behaviour">
            <ToggleControl label={
                <ToggleLabel title={__('Preserve HTML attributes', 'translate')} description={__('Off: tooltips, placeholders and button labels are translated too. On: they stay as written, for example a search box placeholder.', 'translate')} />} checked={settings.preserve_html_attributes} onChange={(value) => updateSetting('preserve_html_attributes', value as boolean)} className='[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!' />

            <div data-twp-alt-text>
                {proActive ? (
                    <ToggleControl label={
                        <ToggleLabel title={__('Translate image alt tags', 'translate')} description={__("Image descriptions are translated, for example 'Red bicycle' becomes 'Rotes Fahrrad' in German.", 'translate')} />} checked={settings.translate_image_alt_tags} onChange={(value) => updateSetting('translate_image_alt_tags', value as boolean)} className='[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!' />
                ) : (
                    <ProLock feature={__('image alt text translation', 'translate')} badge={<ProMarker />}>
                        <ToggleControl label={
                            <ToggleLabel title={__('Translate image alt tags', 'translate')} description={__("Image descriptions are translated, for example 'Red bicycle' becomes 'Rotes Fahrrad' in German.", 'translate')} />} checked={false} disabled onChange={() => undefined} className='[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!' />
                    </ProLock>
                )}
            </div>

            <ToggleControl label={
                <ToggleLabel title={__('Preserve branded terms', 'translate')} description={__("Brand and product names stay in the original language, for example 'Paradox Paradigm'.", 'translate')} />} checked={settings.preserve_branded_terms} onChange={(value) => updateSetting('preserve_branded_terms', value as boolean)} className='[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!' />

            <ToggleControl label={
                <ToggleLabel title={__('Localized date and time formats', 'translate')} description={__("Dates and times follow the reader's format, for example 03/10/2026 becomes 10.03.2026 in German.", 'translate')} />} checked={settings.localized_date_time_formats} onChange={(value) => updateSetting('localized_date_time_formats', value as boolean)} className='[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!' />

            <ToggleControl label={
                <ToggleLabel title={__('Enable units conversion', 'translate')} description={__('Measurements are recalculated for the reader: 5 miles becomes 8 km. Prices are never converted.', 'translate')} />} checked={settings.enable_units_conversion} onChange={(value) => updateSetting('enable_units_conversion', value as boolean)} className='[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!' />
            </ManagedByNetwork>

            </div>
            </div>

            <WhoCanManage />

            <div className="flex flex-col gap-5">
            {stalePages > 0 && (
                <div className="flex flex-col gap-3 rounded-sm border border-translatewp-border p-4">
                    <p className="text-sm text-translatewp-muted!" role="status">
                        {applyNotice ?? sprintf(
                            /* translators: 1: number of pages, 2: language names. */
                            __('%1$s translated pages in %2$s still use the previous date, time and unit settings. Visitors keep the stored translation until you retranslate.', 'translate'),
                            stalePages.toLocaleString(),
                            staleLanguages
                        )}
                    </p>
                    {managerCan().retranslate && <Button
                        variant='secondary'
                        className='rounded-sm! w-fit'
                        disabled={applyLocalizerMutation.isPending}
                        isBusy={applyLocalizerMutation.isPending}
                        onClick={() => { void handleApplyLocalizer(); }}
                    >
                        {applyLocalizerMutation.isPending ? __('Queueing…', 'translate') : __('Retranslate with current formats', 'translate')}
                    </Button>}
                </div>
            )}
            {stalePages === 0 && applyNotice && (
                <p className="text-sm text-translatewp-muted!" role="status">{applyNotice}</p>
            )}
            </div>

            <ConfirmDialog {...confirmDialogProps} />
        </div>
    );
}
