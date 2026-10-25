import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { Search, X } from 'lucide-react';
import type { ReactNode } from 'react';

import { getLanguageNameFromLocale, sortedLocales } from '@/shared/lib/languages';
import { LanguageOption } from '@/shared/components/languageOption';

interface LanguageSelectionProps {
    selected: string[];
    onChange: (selected: string[]) => void;
}

interface LanguageChecklistProps extends LanguageSelectionProps {
    excludeLocales?: string[];
    placeholder?: string;
    compact?: boolean;
    selectionSummary?: ReactNode;
}

interface SelectedLanguageChipsProps extends LanguageSelectionProps {
    compact?: boolean;
    slugs?: Record<string, string>;
}

const nameOf = (locale: string) => getLanguageNameFromLocale(locale) || locale;

export const SelectedLanguageChips = ({ selected, onChange, compact = false, slugs }: SelectedLanguageChipsProps) =>
{
    if (!selected.length) return null;

    return (
        <div className={`flex flex-wrap gap-2.5 shrink-0${compact ? ' max-h-22 overflow-y-auto' : ''}`} aria-live='polite'>
            {selected.map(locale => (
                <span key={locale} className='flex items-center gap-2 rounded-sm bg-translatewp-selection px-2.5 py-1'>
                    <LanguageOption countryCode={locale} />
                    {slugs?.[locale] && (
                        <span className='text-xs text-translatewp-muted!' data-language-slug={locale}>{`/${slugs[locale]}/`}</span>
                    )}
                    <button
                        type='button'
                        className='p-0 m-0 border-0 bg-transparent cursor-pointer text-translatewp-icons flex'
                        aria-label={sprintf(
                            /* translators: %s: language name */
                            __('Remove %s', 'translate'),
                            nameOf(locale)
                        )}
                        onClick={() => onChange(selected.filter(item => item !== locale))}
                    >
                        <X size={14} />
                    </button>
                </span>
            ))}
        </div>
    );
};

/**
 * The one control for picking a set of languages. The full list is
 * always open and fills the height it is given; a filter field sits above it
 * (its search icon turns into a clear button while it holds text); the
 * selected languages collect as removable chips between the filter and the
 * list; every row carries a checkbox. Languages already on the site are not
 * offered. Used by the Add language drawer and the setup wizard.
 *
 * In compact form the list keeps one fixed height and the chips scroll past
 * three rows, so a card holding the control stays the same size whatever is
 * picked or filtered.
 */
export const LanguageChecklist = ({ selected, onChange, excludeLocales = [], placeholder, compact = false, selectionSummary }: LanguageChecklistProps) =>
{
    const [filterValue, setFilterValue] = useState('');
    const filter = filterValue.trim().toLowerCase();
    const excluded = useMemo(() => new Set(excludeLocales), [excludeLocales]);

    const offered = useMemo(
        () => sortedLocales.filter(locale => !excluded.has(locale)),
        [excluded]
    );
    const visible = useMemo(
        () => offered.filter(locale => '' === filter || (getLanguageNameFromLocale(locale) ?? '').toLowerCase().includes(filter) || locale.toLowerCase().includes(filter)),
        [offered, filter]
    );

    const toggle = (locale: string) => onChange(
        selected.includes(locale)
            ? selected.filter(item => item !== locale)
            : [...selected, locale]
    );

    return (
        <>
            <div className='box-border px-3 py-2.75 w-full min-w-0 flex items-center gap-2 border border-translatewp-border rounded-sm hover:border-translatewp-primary focus-within:outline-2! focus-within:outline-solid! focus-within:outline-offset-2! focus-within:outline-translatewp-primary! shrink-0'>
                <input
                    type='text'
                    value={filterValue}
                    placeholder={placeholder ?? __('Filter languages', 'translate')}
                    aria-label={__('Filter languages', 'translate')}
                    className='min-w-0 flex-1 m-0! p-0! h-auto! min-h-0! bg-transparent text-translatewp-neutral! text-sm! leading-normal! border-none! shadow-none! focus:ring-0! focus:outline-none! focus:shadow-none!'
                    onChange={(event) => setFilterValue(event.target.value)}
                />
                {filterValue === '' ? (
                    <Search size={20} className='text-translatewp-icons shrink-0' aria-hidden='true' />
                ) : (
                    <button
                        type='button'
                        aria-label={__('Clear filter', 'translate')}
                        className='p-0 m-0 border-0 bg-transparent cursor-pointer text-translatewp-icons shrink-0 flex'
                        onClick={() => setFilterValue('')}
                    >
                        <X size={20} />
                    </button>
                )}
            </div>

            {selectionSummary}

            <div
                role='group'
                aria-label={__('Languages', 'translate')}
                className={`${compact ? 'h-56 shrink-0' : 'flex-1 min-h-0 max-h-[60vh]'} overflow-y-auto overflow-x-hidden border border-translatewp-border rounded-sm bg-white`}
            >
                {sortedLocales.length === 0 && (
                    <p className='px-3 py-2.75 m-0! text-sm! text-translatewp-muted!'>
                        {__('Could not load the language list. Reload the page.', 'translate')}
                    </p>
                )}
                {sortedLocales.length > 0 && visible.length === 0 && (
                    <p className='px-3 py-2.75 m-0! text-sm! text-translatewp-muted!'>
                        {__('No languages match your search.', 'translate')}
                    </p>
                )}
                {visible.map(locale => (
                    <label
                        key={locale}
                        className='box-border flex items-center gap-3 px-3 py-2.75 w-full cursor-pointer hover:bg-translatewp-border/40 m-0!'
                    >
                        <input
                            type='checkbox'
                            checked={selected.includes(locale)}
                            onChange={() => toggle(locale)}
                            aria-label={nameOf(locale)}
                            className='m-0!'
                        />
                        <LanguageOption countryCode={locale} />
                    </label>
                ))}
            </div>
        </>
    );
};

export const LanguageMultiSelect = ({ selected, onChange, excludeLocales = [], placeholder, compact = false }: LanguageChecklistProps) => (
    <div className='flex flex-col gap-3 min-h-0 flex-1'>
        <LanguageChecklist
            selected={selected}
            onChange={onChange}
            excludeLocales={excludeLocales}
            placeholder={placeholder}
            compact={compact}
            selectionSummary={<SelectedLanguageChips selected={selected} onChange={onChange} compact={compact} />}
        />
    </div>
);

interface LanguageMultiselectProps {
    defaultValue?: string[];
    value?: string[];
    onChange: (value: string[]) => void;
    placeholder?: string;
    excludeLocales?: string[];
    compact?: boolean;
}

/**
 * Controlled or uncontrolled wrapper for callers that own the selection as
 * `value` (the setup wizard's language step).
 */
export const LanguageMultiselect = ({ defaultValue, value, onChange, placeholder, excludeLocales, compact }: LanguageMultiselectProps) =>
{
    const isControlled = value !== undefined;
    const [internalSelected, setInternalSelected] = useState<string[]>(defaultValue ?? []);
    const selected = isControlled ? value : internalSelected;

    const handleChange = (next: string[]) =>
    {
        if (!isControlled) setInternalSelected(next);
        onChange(next);
    };

    return <LanguageMultiSelect selected={selected} onChange={handleChange} placeholder={placeholder} excludeLocales={excludeLocales} compact={compact} />;
};
