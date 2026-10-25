import { useEffect, useRef, useState } from '@wordpress/element';
import { Search, X } from 'lucide-react';

export interface MultiselectOption {
    label: string;
    value: string;
}

interface MultiselectProps {
    options: MultiselectOption[];
    value: string[];
    onChange: (value: string[]) => void;
    placeholder?: string;
    noOptionsText?: string;
    allowCreate?: boolean;
    createOptionLabel?: (value: string) => string;
    onCreateOption?: (value: string) => string | null;
}

export const Multiselect = ({ options, value, onChange, placeholder, noOptionsText, allowCreate = false, createOptionLabel, onCreateOption }: MultiselectProps) =>
{
    const [isOpen, setIsOpen] = useState(false);
    const [filterValue, setFilterValue] = useState('');
    const [debouncedFilter, setDebouncedFilter] = useState('');
    const containerRef = useRef<HTMLDivElement | null>(null);
    const inputRef = useRef<HTMLInputElement | null>(null);

    useEffect(() =>
    {
        const timeout = window.setTimeout(() =>
        {
            setDebouncedFilter(filterValue.trim().toLowerCase());
        }, 200);

        return () => window.clearTimeout(timeout);
    }, [filterValue]);

    useEffect(() =>
    {
        const handlePointerDown = (event: MouseEvent | TouchEvent) =>
        {
            if (!isOpen || !containerRef.current)
            {
                return;
            }

            if (!containerRef.current.contains(event.target as Node))
            {
                setIsOpen(false);
            }
        };

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('touchstart', handlePointerDown);

        return () =>
        {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('touchstart', handlePointerDown);
        };
    }, [isOpen]);

    const selectedSet = new Set(value);
    const normalizedOptions = new Set(options.map(option => option.label.trim().toLowerCase()));
    const trimmedFilterValue = filterValue.trim();
    const canCreateOption = allowCreate && !!trimmedFilterValue && !normalizedOptions.has(trimmedFilterValue.toLowerCase());
    const selectedOptions = options.filter((option) => selectedSet.has(option.value));
    const filteredOptions = options.filter((option) =>
    {
        if (selectedSet.has(option.value))
        {
            return false;
        }

        if (!debouncedFilter)
        {
            return true;
        }

        return option.label.toLowerCase().includes(debouncedFilter);
    });

    const handleSelect = (optionValue: string) =>
    {
        if (selectedSet.has(optionValue))
        {
            return;
        }

        const nextValue = [...value, optionValue];
        onChange(nextValue);
        setFilterValue('');
        setDebouncedFilter('');
        inputRef.current?.focus();
    };

    const handleRemove = (optionValue: string) =>
    {
        onChange(value.filter((selectedValue) => selectedValue !== optionValue));
    };

    const handleCreate = () =>
    {
        if (!canCreateOption || !onCreateOption) {
            return;
        }

        const createdOptionValue = onCreateOption(trimmedFilterValue);
        if (!createdOptionValue || selectedSet.has(createdOptionValue)) {
            return;
        }

        onChange([...value, createdOptionValue]);
        setFilterValue('');
        setDebouncedFilter('');
        inputRef.current?.focus();
    };

    return (
        <div className="relative w-full min-w-0" ref={containerRef}>
            <div
                className="box-border px-3 py-2.75 w-full min-w-0 flex flex-wrap items-center gap-2 border border-translatewp-border rounded-sm hover:border-translatewp-primary"
                onClick={() => inputRef.current?.focus()}
            >
                {selectedOptions.map((option) => (
                    <span key={option.value} className="flex min-w-0 max-w-full items-center gap-2 rounded-sm bg-translatewp-selection px-2.5 py-1">
                        <button
                            type="button"
                            className="inline-flex items-center justify-center m-0! p-0! h-auto! min-h-0! min-w-0! bg-transparent! border-none! shadow-none! cursor-pointer text-translatewp-icons hover:text-translatewp-primary shrink-0"
                            onClick={() => handleRemove(option.value)}
                            aria-label={`Remove ${option.label}`}
                        >
                            <X size={16} />
                        </button>
                        <span className="min-w-0 text-translatewp-neutral text-sm truncate">{option.label}</span>
                    </span>
                ))}

                <input
                    ref={inputRef}
                    type="text"
                    value={filterValue}
                    placeholder={placeholder ?? 'Filter options'}
                    className="min-w-0 flex-1 bg-transparent text-translatewp-neutral! text-sm! border-none! focus:ring-0! focus:outline-none!"
                    onChange={(event) => setFilterValue(event.target.value)}
                    onFocus={() => setIsOpen(true)}
                    onKeyDown={(event) =>
                    {
                        if (event.key !== 'Enter') {
                            return;
                        }

                        const exactOption = options.find((option) => option.label.trim().toLowerCase() === trimmedFilterValue.toLowerCase());

                        event.preventDefault();

                        if (exactOption) {
                            handleSelect(exactOption.value);
                            return;
                        }

                        handleCreate();
                    }}
                />
                <Search size={20} className="text-translatewp-icons shrink-0" />
            </div>

            {isOpen && (
                <div className="box-border absolute start-0 top-full mt-2 z-10 w-full bg-white border border-translatewp-border rounded-sm max-h-40 overflow-y-auto">
                    {canCreateOption && (
                        <button
                            type="button"
                            className="px-3 py-2.75 w-full text-start cursor-pointer bg-white border-0 hover:bg-translatewp-border text-translatewp-primary"
                            onClick={handleCreate}
                        >
                            <span className="text-sm">
                                {createOptionLabel ? createOptionLabel(trimmedFilterValue) : `Create "${trimmedFilterValue}"`}
                            </span>
                        </button>
                    )}

                    {filteredOptions.length === 0 && (
                        <div className="px-3 py-2.75 text-sm text-translatewp-muted">
                            {noOptionsText ?? 'No options available'}
                        </div>
                    )}

                    {filteredOptions.map((option) => (
                        <button
                            type="button"
                            key={option.value}
                            className="px-3 py-2.75 w-full text-start cursor-pointer hover:bg-translatewp-border"
                            onClick={() => handleSelect(option.value)}
                        >
                            <span className="text-translatewp-neutral text-sm">{option.label}</span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
};
