import { SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { useConfirmDialog } from '@/shared/components/confirmDialog/useConfirmDialog';
import { ConfirmDialog } from '@/shared/components/confirmDialog';
import { getLanguageNameFromLocale } from '@/shared/lib/languages';
import type { Language } from '@/shared/components/languageTable';

/** The next language list after moving the default: the old default stays, switched off. */
export function moveDefaultLanguage(languages: Language[], nextLocale: string): Language[]
{
    if (!languages.some(language => language.locale === nextLocale)) return languages;
    return languages.map(language =>
    {
        if (language.locale === nextLocale) return { ...language, default: true, active: true };
        if (language.default) return { ...language, default: false, active: false };
        return language;
    });
}

/**
 * The default language as a dropdown on top of Settings > Languages
 * (and the Dashboard Languages card). Changing it asks for confirmation in
 * product; the retranslation fires on save only when the default moved.
 */
export const DefaultLanguageControl = ({ compact = false }: { compact?: boolean }) =>
{
    const languages = useAdminSettingsStore(state => state.settings['languages.list']);
    const setSetting = useAdminSettingsStore(state => state.setSetting);
    const { requestConfirmation, confirmDialogProps } = useConfirmDialog();
    const current = languages.find(language => language.default)?.locale ?? '';
    const nameOf = (locale: string) => getLanguageNameFromLocale(locale, true) || getLanguageNameFromLocale(locale) || locale;

    const handleChange = async (nextLocale: string) =>
    {
        if (!nextLocale || nextLocale === current) return;
        const confirmed = await requestConfirmation({
            title: sprintf(
                /* translators: %s: language name */
                __('Make %s the default language?', 'translate'),
                nameOf(nextLocale)
            ),
            message: sprintf(
                /* translators: 1: new default language, 2: previous default language */
                __('Your content is then treated as written in %1$s and every translation is made again from it. %2$s stays in your list, switched off.', 'translate'),
                nameOf(nextLocale),
                nameOf(current)
            ),
            confirmLabel: __('Change default language', 'translate'),
            isDestructive: false,
        });
        if (!confirmed) return;
        setSetting('languages.list', previous => moveDefaultLanguage(previous, nextLocale));
    };

    return (
        <div className={compact ? 'flex flex-col gap-1' : 'flex flex-col gap-2'}>
            <SelectControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                label={__('Default language', 'translate')}
                help={__('The language your content is written in.', 'translate')}
                value={current}
                options={languages.map(language => ({ value: language.locale, label: nameOf(language.locale) }))}
                onChange={(value) => void handleChange(String(value))}
            />
            <ConfirmDialog {...confirmDialogProps} />
        </div>
    );
};
