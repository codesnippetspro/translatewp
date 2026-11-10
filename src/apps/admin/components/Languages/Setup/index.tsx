import { useEffect, useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { Language, LanguageTable } from '@/shared/components/languageTable';
import { ConfirmDialog } from '@/shared/components/confirmDialog';
import { useConfirmDialog } from '@/shared/components/confirmDialog/useConfirmDialog';
import { getLanguageNameFromLocale } from '@/shared/lib/languages';
import { AddLanguageDrawer } from '@/apps/admin/components/Languages/Setup/AddLanguageDrawer';
import { appendNewLanguages } from '@/apps/admin/components/Languages/Setup/languageWithLocale';
import { DefaultLanguageControl } from '@/apps/admin/components/Languages/Setup/DefaultLanguageControl';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

export const LanguagesSetup = () =>
{
    const languages = useAdminSettingsStore(state => state.settings['languages.list']);
    const setSetting = useAdminSettingsStore(state => state.setSetting);
    const isLoading = useAdminSettingsStore(state => state.isLoading);

    const [isDrawerOpen, setIsDrawerOpen] = useState(false);
    const [editingLanguageLocale, setEditingLanguageLocale] = useState<string | null>(null);
    const [isNewLanguage, setIsNewLanguage] = useState(false);
    const [draftLanguage, setDraftLanguage] = useState<Language | null>(null);
    const [focusSlug, setFocusSlug] = useState(false);
    const slugFocusLocale = useAdminSettingsStore(state => state.slugFocusLocale);
    const clearSlugFocus = useAdminSettingsStore(state => state.clearSlugFocus);
    const { requestConfirmation, confirmDialogProps } = useConfirmDialog();

    const handleUpdateLanguages = (nextLanguages: Language[] | ((prevState: Language[]) => Language[])) =>
    {
        setSetting('languages.list', previous =>
        {
            const updatedLanguages =
                typeof nextLanguages === 'function'
                    ? nextLanguages(previous)
                    : nextLanguages;

            return updatedLanguages;
        });
    };

    const handleAddLanguage = () =>
    {
        setFocusSlug(false);
        setEditingLanguageLocale(null);
        setIsNewLanguage(true);
        setDraftLanguage(null);
        setIsDrawerOpen(true);
    };

    const handleEditLanguage = (locale: string) =>
    {
        setFocusSlug(false);
        setEditingLanguageLocale(locale);
        setIsNewLanguage(false);
        setDraftLanguage(null);
        setIsDrawerOpen(true);
    };

    useEffect(() =>
    {
        if (slugFocusLocale === null) {
            return;
        }
        if (languages.some(language => language.locale === slugFocusLocale)) {
            handleEditLanguage(slugFocusLocale);
            setFocusSlug(true);
        }
        clearSlugFocus();
    }, [slugFocusLocale]);

    const removeLanguage = (locale: string, options?: { allowDeleteLast?: boolean }) =>
    {
        handleUpdateLanguages(previous =>
        {
            if (!options?.allowDeleteLast && previous.length <= 1) {
                return previous;
            }

            const nextLanguages = previous.filter(language => language.locale !== locale);
            const removedLanguage = previous.find(language => language.locale === locale);

            if (!removedLanguage?.default) {
                return nextLanguages;
            }

            return nextLanguages.map((language, index) =>
                index === 0 ? { ...language, default: true } : language
            );
        });

        if (editingLanguageLocale === locale) {
            setEditingLanguageLocale(null);
            setIsDrawerOpen(false);
        }
    };

    const handleDeleteLanguage = async (locale: string) =>
    {
        const languageName = getLanguageNameFromLocale(locale) ?? locale;

        const confirmed = await requestConfirmation({
            /* translators: %s: language name. */
            title: sprintf(__('Delete %s?', 'translate'), languageName),
            /* translators: %s: language name. */
            message: sprintf(__('This removes %s from your website languages.', 'translate'), languageName),
            confirmLabel: __('Delete', 'translate'),
            isDestructive: true,
        });

        if (!confirmed)
        {
            return;
        }

        removeLanguage(locale);
    };

    const handleDrawerCancel = () =>
    {
        setEditingLanguageLocale(null);
        setIsNewLanguage(false);
        setDraftLanguage(null);
        setIsDrawerOpen(false);
    };

    const handleDrawerDone = () =>
    {
        if (isNewLanguage && draftLanguage) {
            handleUpdateLanguages(previous => appendNewLanguages(previous, [draftLanguage]));
        }

        setEditingLanguageLocale(null);
        setIsNewLanguage(false);
        setDraftLanguage(null);
        setIsDrawerOpen(false);
    };

    const handleAddLanguages = (added: Language[]) =>
    {
        handleUpdateLanguages(previous => appendNewLanguages(previous, added));

        setEditingLanguageLocale(null);
        setIsNewLanguage(false);
        setDraftLanguage(null);
        setIsDrawerOpen(false);
    };

    const handleEditLanguageChanges = (currentLocale: string, updatedLanguage: Language) =>
    {
        if (isNewLanguage) {
            setDraftLanguage(updatedLanguage);
            return;
        }

        handleUpdateLanguages(previous =>
        {
            const hasDuplicatedLocale =
                updatedLanguage.locale !== currentLocale &&
                previous.some(language => language.locale === updatedLanguage.locale);

            if (hasDuplicatedLocale) {
                return previous;
            }

            const nextLanguages = previous.map(language =>
                language.locale === currentLocale ? updatedLanguage : language
            );

            if (!updatedLanguage.default) {
                return nextLanguages;
            }

            return nextLanguages.map(language =>
                language.locale === updatedLanguage.locale ? language : { ...language, default: false }
            );
        });

        setEditingLanguageLocale(updatedLanguage.locale);
    };

    const editingLanguage = languages.find(language => language.locale === editingLanguageLocale) || null;
    const drawerLanguage = isNewLanguage ? draftLanguage : editingLanguage;

    return (
        <div className="flex flex-col gap-8">
            <DefaultLanguageControl />

            <Button variant='primary' className='rounded-sm! w-fit' onClick={handleAddLanguage}>Add languages</Button>

            <h3 className="font-semibold text-base! text-translatewp-neutral!">Translation languages</h3>

            {isLoading ? (
                <div className="flex items-center justify-center py-12">
                    <Spinner />
                </div>
            ) : (
                <LanguageTable
                    languages={languages}
                    setLanguages={handleUpdateLanguages}
                    onEditLanguage={handleEditLanguage}
                    onDeleteLanguage={handleDeleteLanguage}
                    onAddLanguage={handleAddLanguage}
                />
            )}

            <AddLanguageDrawer
                isDrawerOpen={isDrawerOpen}
                setIsDrawerOpen={setIsDrawerOpen}
                language={drawerLanguage}
                isNewLanguage={isNewLanguage}
                onLanguageChange={handleEditLanguageChanges}
                onCancel={handleDrawerCancel}
                onDone={handleDrawerDone}
                existingLocales={languages.map(language => language.locale)}
                onAddLanguages={handleAddLanguages}
                focusSlug={focusSlug && isDrawerOpen}
            />

            <ConfirmDialog {...confirmDialogProps} />
        </div>
    );
}
