import { TextControl, ToggleControl, RadioControl, Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { defaultSlugFromLocale, languageWithLocale } from '@/apps/admin/components/Languages/Setup/languageWithLocale';
import { Drawer } from '@/shared/components/drawer';
import { LanguageSelect } from '@/shared/components/languageSelect';
import { LanguageMultiSelect } from '@/shared/components/languageMultiselect';
import { formatLocale, getLanguageNameFromLocale } from '@/shared/lib/languages';
import type { Language } from '@/shared/components/languageTable';

interface AddLanguageDrawerProps {
    isDrawerOpen: boolean;
    setIsDrawerOpen: (isOpen: boolean) => void;
    language: Language | null;
    isNewLanguage: boolean;
    onLanguageChange: (currentLocale: string, language: Language) => void;
    onCancel: () => void;
    onDone: () => void;
    existingLocales?: string[];
    onAddLanguages?: (languages: Language[]) => void;
    focusSlug?: boolean;
}

const newLanguageDraft = (locale: string): Language => ({
    locale,
    slug: defaultSlugFromLocale(locale),
    active: true,
    default: false,
});

export const AddLanguageDrawer = ({ isDrawerOpen, setIsDrawerOpen, language, isNewLanguage, onLanguageChange, onCancel, onDone, existingLocales = [], onAddLanguages, focusSlug = false }: AddLanguageDrawerProps) =>
{
    const draft = language;
    // A new-language drawer collects any number of languages at once.
    const [selectedLocales, setSelectedLocales] = useState<string[]>([]);
    useEffect(() =>
    {
        if (isDrawerOpen) setSelectedLocales([]);
    }, [isDrawerOpen, isNewLanguage]);
    const multiAdd = isNewLanguage && typeof onAddLanguages === 'function';
    const canSave = multiAdd ? selectedLocales.length > 0 : draft !== null;

    const handleAddSelected = () =>
    {
        if (!multiAdd || selectedLocales.length === 0) return;
        onAddLanguages(selectedLocales.map(newLanguageDraft));
        setSelectedLocales([]);
    };

    const handleLanguageChange = (updatedLanguage: Language) =>
    {
        onLanguageChange(draft?.locale ?? '', updatedLanguage);
    };

    const handleLocalePick = (locale: string) =>
    {
        onLanguageChange('', newLanguageDraft(locale));
    };

    return (
        <Drawer
            isOpen={isDrawerOpen}
            onClose={() => setIsDrawerOpen(false)}
            title={isNewLanguage ? 'Add Language' : 'Edit Language'}
        >
            <div className='flex flex-col justify-between h-[calc(100%-8rem)] min-h-0'>
                <div className={multiAdd ? 'flex flex-col gap-4 flex-1 min-h-0' : 'flex flex-col gap-8'}>
                    {multiAdd ? (
                        <LanguageMultiSelect
                            selected={selectedLocales}
                            onChange={setSelectedLocales}
                            excludeLocales={existingLocales}
                        />
                    ) : isNewLanguage && !draft ? (
                        <LanguageSelect onChange={handleLocalePick} placeholder='Select a language' />
                    ) : draft ? (
                        <>
                            <LanguageSelect defaultValue={draft.locale} onChange={(locale) => handleLanguageChange(languageWithLocale(draft, locale))} />

                            <div className='flex items-center justify-between'>
                                <span className='text-sm font-bold text-translatewp-neutral'>Locale</span>

                                <TextControl
                                    __next40pxDefaultSize
                                    __nextHasNoMarginBottom
                                    className='[&_input]:border-translatewp-border! [&_input]:rounded-sm!'
                                    value={formatLocale(draft.locale)}
                                    onChange={() => { }}
                                    disabled
                                />
                            </div>

                            <div className='flex items-center justify-between'>
                                <span className='text-sm font-bold text-translatewp-neutral'>Slug</span>

                                <TextControl
                                    __next40pxDefaultSize
                                    __nextHasNoMarginBottom
                                    className='[&_input]:border-translatewp-border! [&_input]:rounded-sm!'
                                    value={draft.slug}
                                    autoFocus={focusSlug}
                                    onChange={(slug) => handleLanguageChange({ ...draft, slug })}
                                />
                            </div>

                            <ToggleControl label={
                                <div className='flex flex-col gap-1'>
                                    <p className='font-bold text-sm! text-translatewp-neutral!'>Is active</p>
                                    <span className='text-sm text-translatewp-muted!'>
                                        {draft.default ? 'This is the default language; choose another default to switch it off.' : 'Enable or disable this language'}
                                    </span>
                                </div>
                            } checked={draft.active} disabled={draft.default} onChange={() => handleLanguageChange({ ...draft, active: !draft.active })} className='[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!' />

{/* The default language is chosen with the Default language control, not here. */}

                            <div className='flex flex-col gap-6'>
                                <span className='text-sm font-bold text-translatewp-neutral'>Switcher label</span>

                                <div>
                                    <RadioControl
                                        onChange={(value) =>
                                        {
                                            const selectedLabel = value as 'native' | 'custom';
                                            if (selectedLabel === 'native') {
                                                handleLanguageChange({ ...draft, switcher_label: undefined });
                                            } else if (!draft.switcher_label) {
                                                handleLanguageChange({ ...draft, switcher_label: getLanguageNameFromLocale(draft.locale, true) || '' });
                                            }
                                        }}
                                        options={[{ label: 'Native', value: 'native' }, { label: 'Custom', value: 'custom' }]}
                                        selected={draft.switcher_label ? 'custom' : 'native'}
                                        className='[&_legend]:hidden! [&_div[data-wp-component=VStack]]:gap-6! [&_label]:text-sm! [&_label]:text-translatewp-neutral! [&_label]:font-bold!'
                                    />

                                    {draft.switcher_label && (
                                        <>
                                            <span className='text-translatewp-muted text-sm pl-6 mb-2 block'>Add a custom label</span>
                                            <TextControl
                                                __next40pxDefaultSize
                                                __nextHasNoMarginBottom
                                                className='[&_input]:border-translatewp-border! [&_input]:rounded-sm! pl-6'
                                                placeholder={'My label'}
                                                value={draft.switcher_label || ''}
                                                onChange={(switcherLabel) => handleLanguageChange({ ...draft, switcher_label: switcherLabel })}
                                            />
                                        </>
                                    )}
                                </div>
                            </div>
                        </>
                    ) : null}
                </div>

                <div className='flex items-center gap-1'>
                    <Button variant='secondary' className='rounded-sm! text-base! px-9! py-3!' onClick={onCancel}>Cancel</Button>
                    <Button variant='primary' className='rounded-sm! text-base! py-3! flex-1 justify-center!' disabled={!canSave} onClick={multiAdd ? handleAddSelected : onDone}>
                        {!isNewLanguage
                            ? 'Update language'
                            : selectedLocales.length > 1
                                ? sprintf(
                                    /* translators: %d: number of selected languages. */
                                    _n('Add %d language', 'Add %d languages', selectedLocales.length, 'translate'),
                                    selectedLocales.length
                                )
                                : __('Add language', 'translate')}
                    </Button>
                </div>
            </div>
        </Drawer>
    )
}