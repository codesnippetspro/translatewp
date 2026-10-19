import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

export const LEXICON_TERM_MAX_LENGTH = 200;
import { Button, TextControl, TextareaControl } from '@wordpress/components';
import { Info } from 'lucide-react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { Drawer } from '@/shared/components/drawer';
import { CheckboxGrid } from '@/shared/components/form/checkboxGrid';
import { Multiselect } from '@/shared/components/form/multiselect';
import { createWordPressLexiconTerm, updateWordPressLexiconTerm, type LexiconTerm } from '@/shared/api/wordpress';
import { getLanguageNameFromLocale } from '@/shared/lib/languages';
import type { MultiselectOption } from '@/shared/components/form/multiselect';
import { getWordPressLexiconCategoriesQueryOptions } from '@/shared/query-options/wordpress';

export type LexiconDrawerPrefill = { term: string; description: string; languages: string[] };

interface AddLexiconDrawerProps {
    isDrawerOpen: boolean;
    /** Values a proposal brings into a new term; the owner edits them before saving. */
    prefill?: LexiconDrawerPrefill | null;
    /** Called once a new term is saved. */
    onSaved?: () => void;
    setIsDrawerOpen: (isOpen: boolean) => void;
    drawerContext: 'add' | 'edit';
    editingTerm: LexiconTerm | null;
}

export const AddLexiconDrawer = ({ isDrawerOpen, setIsDrawerOpen, drawerContext, editingTerm, prefill = null, onSaved }: AddLexiconDrawerProps) =>
{
    const languages = useAdminSettingsStore(state => state.settings['languages.list']);
    const queryClient = useQueryClient();
    const [term, setTerm] = useState('');
    const [description, setDescription] = useState('');
    const [selectedLanguages, setSelectedLanguages] = useState<string[]>([]);
    const [selectedCategories, setSelectedCategories] = useState<string[]>([]);
    const [createdCategories, setCreatedCategories] = useState<MultiselectOption[]>([]);
    const [isCategoryHelpOpen, setIsCategoryHelpOpen] = useState(false);
    const { data: categories } = useQuery(getWordPressLexiconCategoriesQueryOptions);

    const handleMutationSuccess = async () =>
    {
        await queryClient.invalidateQueries({ queryKey: ['getWordPressLexiconTerms'] });
        await queryClient.invalidateQueries({ queryKey: ['getWordPressLexiconCategories'] });
        resetForm();
        setIsDrawerOpen(false);
    };

    const { mutate: createLexiconTerm, isPending: isCreating } = useMutation({
        mutationFn: (variables: { term: string; description: string; languages: string[]; categories: string[]; newCategories: string[] }) =>
            createWordPressLexiconTerm(variables.term, variables.description, variables.languages, variables.categories, variables.newCategories),
        onSuccess: () => { handleMutationSuccess(); onSaved?.(); }
    });

    const { mutate: updateLexiconTerm, isPending: isUpdating } = useMutation({
        mutationFn: (variables: { id: number; term: string; description: string; languages: string[]; categories: string[]; newCategories: string[] }) =>
            updateWordPressLexiconTerm(variables.id, variables.term, variables.description, variables.languages, variables.categories, variables.newCategories),
        onSuccess: handleMutationSuccess
    });

    const isPending = isCreating || isUpdating;

    const languageOptions = useMemo(() => {
        const activeLanguages = languages
            .filter(language => language.active)
            .map(language => ({
                label: getLanguageNameFromLocale(language.locale) ?? language.locale,
                value: language.locale
            }));

        return [
            { label: __('All translations', 'translate'), value: 'all' },
            ...activeLanguages
        ];
    }, [languages]);

    const activeLanguageValues = useMemo(
        () => languageOptions.filter((option) => option.value !== 'all').map((option) => option.value),
        [languageOptions]
    );

    const allLanguagesSelected = activeLanguageValues.length > 0 && activeLanguageValues.every((value) => selectedLanguages.includes(value));
    const languageSelectionForGrid = allLanguagesSelected ? ['all', ...selectedLanguages] : selectedLanguages;

    const categoryOptions = useMemo(() => {
        const base = categories ?? [];
        const merged = [...base];

        for (const category of createdCategories) {
            if (merged.some((option) => option.value === category.value)) {
                continue;
            }
            merged.push(category);
        }

        return merged;
    }, [categories, createdCategories]);

    const resetForm = () =>
    {
        setTerm('');
        setDescription('');
        setSelectedLanguages([]);
        setSelectedCategories([]);
        setCreatedCategories([]);
    };

    useEffect(() =>
    {
        if (!isDrawerOpen) {
            return;
        }

        if (drawerContext === 'add' || !editingTerm) {
            setTerm(prefill?.term ?? '');
            setDescription(prefill?.description ?? '');
            setSelectedLanguages(prefill && prefill.languages.length > 0 ? prefill.languages.filter(value => activeLanguageValues.includes(value)) : activeLanguageValues);
            setSelectedCategories([]);
            setCreatedCategories([]);
            return;
        }

        setTerm(editingTerm.term);
        setDescription(editingTerm.description);
        setSelectedLanguages(editingTerm.languages);
        setSelectedCategories(editingTerm.categories.map((category) => String(category.id)));
        setCreatedCategories([]);
    }, [isDrawerOpen, drawerContext, editingTerm, activeLanguageValues, prefill]);

    const handleCancel = () =>
    {
        resetForm();
        setIsDrawerOpen(false);
    };

    const handleDone = () =>
    {
        if (!term.trim()) {
            return;
        }

        const normalizedCategories = selectedCategories.filter((value) => !value.startsWith('new:'));
        const normalizedNewCategories = selectedCategories
            .filter((value) => value.startsWith('new:'))
            .map((value) => createdCategories.find((category) => category.value === value)?.label ?? '')
            .filter(Boolean);

        if (drawerContext === 'edit' && editingTerm) {
            updateLexiconTerm({
                id: editingTerm.id,
                term: term.trim(),
                description,
                languages: selectedLanguages,
                categories: normalizedCategories,
                newCategories: normalizedNewCategories
            });
            return;
        }

        createLexiconTerm({
            term: term.trim(),
            description,
            languages: selectedLanguages,
            categories: normalizedCategories,
            newCategories: normalizedNewCategories
        });
    };

    const handleLanguagesChange = (nextValue: string[]) =>
    {
        const hasAllOption = nextValue.includes('all');
        const currentlyAllSelected = activeLanguageValues.length > 0 && activeLanguageValues.every((value) => selectedLanguages.includes(value));

        if (hasAllOption && !currentlyAllSelected) {
            setSelectedLanguages(activeLanguageValues);
            return;
        }

        if (!hasAllOption && currentlyAllSelected && nextValue.length === activeLanguageValues.length) {
            setSelectedLanguages([]);
            return;
        }

        setSelectedLanguages(nextValue.filter((value) => value !== 'all'));
    };

    const handleCreateCategory = (name: string) =>
    {
        const normalized = name.trim();
        if (!normalized) {
            return null;
        }

        const existing = categoryOptions.find((option) => option.label.toLowerCase() === normalized.toLowerCase());
        if (existing) {
            return existing.value;
        }

        const slug = normalized.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        const value = `new:${slug || Date.now().toString()}`;
        const createdOption = { label: normalized, value };

        setCreatedCategories((previous) =>
            previous.some((option) => option.value === createdOption.value)
                ? previous
                : [...previous, createdOption]
        );
        return value;
    };

    return (
        <>
        <Drawer
            isOpen={isDrawerOpen}
            onClose={handleCancel}
            title={drawerContext === 'add' ? __('Add Term', 'translate') : __('Edit Term', 'translate')}
        >
            <div className="flex flex-col justify-between h-[calc(100%-8rem)]">
                <div className="flex flex-col gap-8">
                    <div className='flex flex-col gap-2'>
                        <TextControl
                            __next40pxDefaultSize
                            __nextHasNoMarginBottom
                            value={term}
                            onChange={(value) => setTerm(value.slice(0, LEXICON_TERM_MAX_LENGTH))}
                            placeholder={__('Your term', 'translate')}
                            maxLength={LEXICON_TERM_MAX_LENGTH}
                            help={term.trim() === ''
                                ? __('Enter the term first; Save stays off until it has one.', 'translate')
                                : sprintf(
                                    /* translators: 1: characters used, 2: maximum characters. */
                                    __('%1$d of %2$d characters', 'translate'),
                                    term.length,
                                    LEXICON_TERM_MAX_LENGTH
                                )}
                            className="[&_input]:border-translatewp-border! [&_input]:rounded-sm!"
                        />

                        <TextareaControl
                            __nextHasNoMarginBottom
                            value={description}
                            rows={5}
                            onChange={(value) => setDescription(value as string)}
                            placeholder={__('Describe when and how this term should be interpreted...', 'translate')}
                            className="m-0! [&_textarea]:resize-none! [&_textarea]:border-translatewp-border! [&_textarea]:rounded-sm!"
                        />
                    </div>

                    <CheckboxGrid
                        label={__('Apply context to', 'translate')}
                        options={languageOptions}
                        selected={languageSelectionForGrid}
                        columns={2}
                        onChange={handleLanguagesChange}
                    />

                    <div className="flex flex-col gap-2">
                        <div className="flex items-center gap-1.5">
                            <span className="text-sm font-bold text-translatewp-neutral">{__('Categories', 'translate')}</span>
                            <button
                                type="button"
                                onClick={() => setIsCategoryHelpOpen(true)}
                                aria-label={__('What are categories?', 'translate')}
                                className="inline-flex items-center justify-center bg-transparent border-0 p-0 cursor-pointer text-translatewp-icons hover:text-translatewp-primary"
                            >
                                <Info className="w-4 h-4" />
                            </button>
                        </div>
                        <Multiselect
                            value={selectedCategories}
                            options={categoryOptions}
                            onChange={setSelectedCategories}
                            placeholder={__('Filter categories', 'translate')}
                            noOptionsText={__('No categories available', 'translate')}
                            allowCreate
                            onCreateOption={handleCreateCategory}
                            createOptionLabel={(value) => sprintf(
                                /* translators: %s: the new category name. */
                                __('Create "%s"', 'translate'),
                                value
                            )}
                        />
                    </div>
                </div>

                <div className="flex items-center gap-1">
                    <Button variant="secondary" className="rounded-sm! px-9! py-3! text-base!" onClick={handleCancel} disabled={isPending}>{__('Cancel', 'translate')}</Button>
                    <Button variant="primary" className="flex-1 justify-center rounded-sm! py-3! text-base!" onClick={handleDone} isBusy={isPending} disabled={!term.trim() || isPending}>{__('Save', 'translate')}</Button>
                </div>
            </div>
        </Drawer>

        <Drawer
            isOpen={isCategoryHelpOpen}
            onClose={() => setIsCategoryHelpOpen(false)}
            title="About categories"
        >
            <div className="flex flex-col gap-6 text-sm text-translatewp-muted! leading-relaxed">
                <p>
                    Categories are optional labels that group related lexicon terms. For
                    example <strong className="text-translatewp-neutral!">Brand</strong>,{' '}
                    <strong className="text-translatewp-neutral!">Products</strong> or{' '}
                    <strong className="text-translatewp-neutral!">Legal</strong>. They don&rsquo;t
                    change how a term is translated; they simply help you organize and filter your
                    lexicon as it grows.
                </p>

                <div className="flex flex-col gap-2">
                    <span className="text-sm font-bold text-translatewp-neutral!">When to use them</span>
                    <p>
                        Reach for categories once you have more than a handful of terms and want to
                        keep them tidy: grouping brand names, product names, or industry
                        jargon so they&rsquo;re easy to find later. A term can belong to several
                        categories, or none at all.
                    </p>
                </div>

                <div className="flex flex-col gap-2">
                    <span className="text-sm font-bold text-translatewp-neutral!">How to create one</span>
                    <p>
                        In the <strong className="text-translatewp-neutral!">Categories</strong>{' '}
                        field, type a new name and choose{' '}
                        <span className="text-translatewp-neutral!">&ldquo;Create&rdquo;</span> to
                        add it. To assign existing categories, just pick them from the list. Remove
                        one from a term by clearing it from the field.
                    </p>
                </div>
            </div>
        </Drawer>
        </>
    );
};
