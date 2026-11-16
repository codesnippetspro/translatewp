import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { Pencil, Trash2 } from 'lucide-react';
import { getCountryFlagFromLocale, getLanguageNameFromLocale } from '@/shared/lib/languages';
import { Table, TableCell, TableHeaderRow, TableRow } from '@/shared/components/form/table';
import './style.css';

export interface LexiconRow
{
    id: number | string;
    term: string;
    languages: string[];
    categories: string[];
}

export interface LexiconTableProps
{
    rows: LexiconRow[];
    onEdit: (id: number | string) => void;
    onDelete: (id: number | string) => void;
    onAdd: () => void;
}

export const LexiconTable = ({ rows, onEdit, onDelete, onAdd }: LexiconTableProps) =>
{
    return (
        <Table className="twp-lexicon-table" header={<TableHeaderRow className="max-sm:sr-only sm:[&>*]:border-b!">
                <TableCell variant="header">{__('Term', 'translate')}</TableCell>
                <TableCell variant="header">{__('Languages', 'translate')}</TableCell>
                <TableCell variant="header">{__('Categories', 'translate')}</TableCell>
                <TableCell variant="header" align="end">{__('Actions', 'translate')}</TableCell>
            </TableHeaderRow>}>

            {rows.length === 0 && (
                <TableRow>
                    <TableCell colSpan={4} align="center" className="py-4 text-translatewp-muted">
                        <div className="flex flex-col items-center gap-3">
                            <span>{__('No terms found. Add your first term to get started.', 'translate')}</span>
                            <Button variant="primary" className="rounded-sm!" onClick={onAdd}>{__('Add term', 'translate')}</Button>
                        </div>
                    </TableCell>
                </TableRow>
            )}

            {rows.map((row) => (
                <TableRow key={row.id}>
                    <TableCell className="text-translatewp-neutral max-w-md sm:[overflow-wrap:anywhere]!">
                        {row.term}
                    </TableCell>

                    <TableCell as="div" className="flex flex-wrap items-center gap-1.5">
                        {row.languages.map((locale) => (
                            <span key={`${row.id}-${locale}`} className="inline-flex items-center gap-1.5 max-sm:whitespace-nowrap">
                                <span aria-hidden="true">{getCountryFlagFromLocale(locale)}</span>
                                {getLanguageNameFromLocale(locale) || locale}
                            </span>
                        ))}
                    </TableCell>

                    <TableCell as="div" className="flex flex-wrap items-center gap-1.5">
                        {row.categories.map((category) => (
                            <span
                                key={`${row.id}-${category}`}
                                className="inline-flex items-center rounded-full border border-translatewp-border bg-translatewp-selection px-2 py-0.5 text-xs text-translatewp-muted"
                            >
                                {category}
                            </span>
                        ))}
                    </TableCell>

                    <TableCell as="div" align="end" className="flex justify-end gap-2">
                        <Button
                            variant='primary'
                            /* translators: %s: lexicon term. */
                            label={sprintf(__('Edit %s', 'translate'), row.term)}
                            className='p-2! min-w-0! rounded-sm!'
                            onClick={() => onEdit(row.id)}
                        >
                            <Pencil className='w-4 h-4' />
                        </Button>
                        <Button
                            variant='secondary'
                            /* translators: %s: lexicon term. */
                            label={sprintf(__('Delete %s', 'translate'), row.term)}
                            className='p-2! min-w-0! rounded-sm!'
                            onClick={() => onDelete(row.id)}
                        >
                            <Trash2 className='w-4 h-4' />
                        </Button>
                    </TableCell>
                </TableRow>
            ))}
        </Table>
    );
}
