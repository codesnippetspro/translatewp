import { useId, useMemo, useRef, useState } from '@wordpress/element';
import type { KeyboardEvent, ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

export type ScopeOption = {
    value: string;
    label: string;
    flag?: ReactNode;
};

type LanguageScopeSwitcherProps = {
    options: ScopeOption[];
    selected: string;
    onSelect: (value: string) => void;
    label: string;
    tabThreshold?: number;
};

/**
 * One switcher that serves a site with three languages and a site with
 * thirty alike. Up to `tabThreshold` options render as a tab strip; beyond that
 * the same control becomes a searchable select with type-ahead and keyboard
 * navigation that shows the selected language's name and flag. The caller owns
 * the selected value and its persistence, so this component is controlled.
 */
export const SCOPE_TAB_THRESHOLD = 6;

export const LanguageScopeSwitcher = ({
    options,
    selected,
    onSelect,
    label,
    tabThreshold = SCOPE_TAB_THRESHOLD,
}: LanguageScopeSwitcherProps) =>
{
    if (0 === options.length) {
        return null;
    }

    if (options.length <= tabThreshold) {
        return <ScopeTabs options={options} selected={selected} onSelect={onSelect} label={label} />;
    }

    return <ScopeSelect options={options} selected={selected} onSelect={onSelect} label={label} />;
};

const ScopeTabs = ({ options, selected, onSelect, label }: Omit<LanguageScopeSwitcherProps, 'tabThreshold'>) =>
{
    const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>, index: number) =>
    {
        if ('ArrowRight' !== event.key && 'ArrowLeft' !== event.key) {
            return;
        }

        event.preventDefault();
        const tabList = event.currentTarget.parentElement;
        const isRtl = tabList && getComputedStyle(tabList).direction === 'rtl';
        const direction = 'ArrowRight' === event.key ? 1 : -1;
        const next = (index + direction * (isRtl ? -1 : 1) + options.length) % options.length;
        onSelect(options[next].value);
        const tabs = tabList?.querySelectorAll<HTMLElement>('[role="tab"]');
        tabs?.[next]?.focus();
    };

    return (
        <div
            role="tablist"
            aria-label={label}
            className="flex flex-wrap gap-0 border-b border-translatewp-border"
        >
            {options.map((option, index) =>
            {
                const isSelected = option.value === selected;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="tab"
                        aria-selected={isSelected}
                        tabIndex={isSelected ? 0 : -1}
                        onClick={() => onSelect(option.value)}
                        onKeyDown={(event) => onKeyDown(event, index)}
                        className={`flex items-center gap-2 -mb-px cursor-pointer rounded-none border-0 border-b-2 border-solid bg-transparent px-4 py-3 text-sm font-bold shadow-none ${
                            isSelected
                                ? 'border-translatewp-primary text-translatewp-primary!'
                                : 'border-transparent text-translatewp-neutral! hover:text-translatewp-primary!'
                        }`}
                    >
                        {option.flag}
                        <span>{option.label}</span>
                    </button>
                );
            })}
        </div>
    );
};

const ScopeSelect = ({ options, selected, onSelect, label }: Omit<LanguageScopeSwitcherProps, 'tabThreshold'>) =>
{
    const listId = useId();
    const inputRef = useRef<HTMLInputElement>(null);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);

    const selectedOption = options.find((option) => option.value === selected) ?? options[0];

    const filtered = useMemo(() =>
    {
        const needle = query.trim().toLowerCase();
        return needle
            ? options.filter((option) => option.label.toLowerCase().includes(needle))
            : options;
    }, [options, query]);

    const pick = (option: ScopeOption) =>
    {
        onSelect(option.value);
        setOpen(false);
        setQuery('');
        inputRef.current?.blur();
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) =>
    {
        if ('ArrowDown' === event.key) {
            event.preventDefault();
            setOpen(true);
            setActiveIndex((index) => Math.min(index + 1, filtered.length - 1));
        } else if ('ArrowUp' === event.key) {
            event.preventDefault();
            setActiveIndex((index) => Math.max(index - 1, 0));
        } else if ('Enter' === event.key) {
            if (open && filtered[activeIndex]) {
                event.preventDefault();
                pick(filtered[activeIndex]);
            }
        } else if ('Escape' === event.key) {
            setOpen(false);
            setQuery('');
        }
    };

    return (
        <div className="relative max-w-86">
            <div className="flex items-center gap-2 rounded-sm border border-translatewp-border bg-transparent px-2 py-1">
                {!open && selectedOption?.flag}
                <input
                    ref={inputRef}
                    type="text"
                    role="combobox"
                    aria-expanded={open}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-label={label}
                    aria-activedescendant={open && filtered[activeIndex] ? `${listId}-option-${activeIndex}` : undefined}
                    className="w-full bg-transparent text-sm text-translatewp-neutral outline-none"
                    placeholder={__('Search languages', 'translate')}
                    value={open ? query : selectedOption?.label ?? ''}
                    onChange={(event) =>
                    {
                        setOpen(true);
                        setQuery(event.target.value);
                        setActiveIndex(0);
                    }}
                    onFocus={() =>
                    {
                        setOpen(true);
                        setQuery('');
                    }}
                    onBlur={() => setOpen(false)}
                    onKeyDown={onKeyDown}
                />
            </div>

            {open && (
                <ul
                    id={listId}
                    role="listbox"
                    aria-label={label}
                    className="absolute z-10 mt-1 max-h-64 w-full overflow-y-auto rounded-sm border border-translatewp-border bg-white shadow-sm"
                >
                    {0 === filtered.length ? (
                        <li className="px-2 py-1 text-sm text-translatewp-muted!">
                            {__('No languages match your search.', 'translate')}
                        </li>
                    ) : (
                        filtered.map((option, index) => (
                            <li
                                key={option.value}
                                id={`${listId}-option-${index}`}
                                role="option"
                                aria-selected={option.value === selected}
                                className={`flex cursor-pointer items-center gap-2 px-2 py-1 text-sm ${
                                    index === activeIndex ? 'bg-translatewp-border/40' : ''
                                }`}
                                onMouseDown={(event) =>
                                {
                                    event.preventDefault();
                                    pick(option);
                                }}
                                onMouseEnter={() => setActiveIndex(index)}
                            >
                                {option.flag}
                                <span>{option.label}</span>
                            </li>
                        ))
                    )}
                </ul>
            )}
        </div>
    );
};
