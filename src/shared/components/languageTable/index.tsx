import { type Dispatch, type SetStateAction, useRef } from 'react';
import { GripVertical, Pencil, Trash2 } from 'lucide-react';
import { Button } from '@wordpress/components';

import { formatLocale, getCountryFlagFromLocale, getLanguageNameFromLocale } from '@/shared/lib/languages';
import { Table, TableCell, TableHeaderRow, TableRow } from '@/shared/components/form/table';

export interface Language
{
    locale: string;
    slug: string;
    active: boolean;
    default: boolean;
    switcher_label?: string;
}

interface LanguageTableProps {
    languages: Language[];
    setLanguages: Dispatch<SetStateAction<Language[]>>;
    onEditLanguage: (locale: string) => void;
    onDeleteLanguage: (locale: string) => void;
    onAddLanguage: () => void;
}

export const LanguageTable = ({ languages, setLanguages, onEditLanguage, onDeleteLanguage, onAddLanguage }: LanguageTableProps) =>
{
    const draggingIndexRef = useRef<number | null>(null);

    const handleDragStart = (index: number) => () =>
    {
        draggingIndexRef.current = index;
    };

    const handleDragOver = (event: React.DragEvent<HTMLDivElement>) =>
    {
        event.preventDefault();
    };

    const handleDrop = (dropIndex: number) => () =>
    {
        const dragIndex = draggingIndexRef.current;
        if (dragIndex === null || dragIndex === dropIndex)
        {
            draggingIndexRef.current = null;
            return;
        }

        setLanguages(previous =>
        {
            const updated = [...previous];
            const [moved] = updated.splice(dragIndex, 1);
            updated.splice(dropIndex, 0, moved);
            return updated;
        });

        draggingIndexRef.current = null;
    };

    const handleDragEnd = () =>
    {
        draggingIndexRef.current = null;
    };

    return (
        <Table>
            <TableHeaderRow columnsClassName="grid-cols-5">
                <TableCell variant="header" className="col-span-2">Language</TableCell>
                <TableCell variant="header" align="center">Slug</TableCell>
                <TableCell variant="header" align="center">Locale</TableCell>
                <TableCell variant="header" align="end">Actions</TableCell>
            </TableHeaderRow>

            {languages.length === 0 ? (
                <TableRow columnsClassName="grid-cols-1">
                    <TableCell align="center" className="py-4 text-translatewp-muted">
                        <Button variant="primary" className="rounded-sm!" onClick={onAddLanguage}>Add a language</Button>
                    </TableCell>
                </TableRow>
            ) : languages.map((language, index) =>
            {
                const languageName = getLanguageNameFromLocale( language.locale ) || language.locale;

                return (
                    <TableRow
                        key={language.locale}
                        columnsClassName="grid-cols-5"
                        draggable
                        onDragStart={handleDragStart(index)}
                        onDragOver={handleDragOver}
                        onDrop={handleDrop(index)}
                        onDragEnd={handleDragEnd}
                    >
                        <TableCell as="div" className="flex items-center gap-2.5 col-span-2 text-translatewp-neutral">
                            <GripVertical className="w-5 h-5 text-translatewp-grip cursor-grab active:cursor-grabbing" />
                            {getCountryFlagFromLocale( language.locale )}
                            <span className="text-sm text-translatewp-neutral">{getLanguageNameFromLocale( language.locale )}</span>
                        </TableCell>

                        <TableCell align="center">{language.slug}</TableCell>
                        <TableCell align="center">{formatLocale( language.locale )}</TableCell>
                        <TableCell align="end" className="flex justify-end gap-2">
                            <Button
                                variant='primary'
                                label={`Edit ${languageName}`}
                                onClick={() => onEditLanguage(language.locale)}
                                className='p-2! min-w-0! rounded-sm!'
                            >
                                <Pencil className='w-4 h-4' />
                            </Button>
                            <Button
                                variant='secondary'
                                label={`Delete ${languageName}`}
                                onClick={() => onDeleteLanguage(language.locale)}
                                className='p-2! min-w-0! rounded-sm!'
                            >
                                <Trash2 className='w-4 h-4' />
                            </Button>
                        </TableCell>
                    </TableRow>
                );
            })}

        </Table>
    )
}
