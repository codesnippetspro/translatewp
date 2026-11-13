import { createHash } from 'node:crypto';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { Table, TableCell, TableHeaderRow, TableRow } from '@/shared/components/form/table';
import { LexiconTable } from '@/apps/admin/components/Lexicon/LexiconTable';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

describe('LexiconTable empty state', () =>
{
    it('offers an add action when no terms exist', async () =>
    {
        const container = document.createElement('div');
        document.body.appendChild(container);
        const onAdd = jest.fn();

        await act(async () =>
        {
            createRoot(container).render(<LexiconTable rows={[]} onEdit={() => {}} onDelete={() => {}} onAdd={onAdd} />);
        });

        expect(container.querySelector('table')).not.toBeNull();
        expect(container.querySelector('td')?.colSpan).toBe(4);
        expect(Array.from(container.querySelectorAll('th')).map(header => header.textContent))
            .toEqual(['Term', 'Languages', 'Categories', 'Actions']);
        const button = Array.from(container.querySelectorAll('button')).find(candidate => candidate.textContent === 'Add term');
        expect(button).toBeDefined();
        await act(async () =>
        {
            (button as HTMLButtonElement).click();
        });
        expect(onAdd).toHaveBeenCalledTimes(1);
    });
});

describe('LexiconTable term cell', () =>
{
    it('a term is rendered as text', async () =>
    {
        const container = document.createElement('div');
        document.body.appendChild(container);
        const rows = [
            { id: 1, term: 'Planck’s constant', languages: [], categories: [] },
            { id: 2, term: 'AT&T <img src=x onerror="window.__lexiconXss=1">', languages: [], categories: [] },
        ];

        await act(async () =>
        {
            createRoot(container).render(<LexiconTable rows={rows} onEdit={() => {}} onDelete={() => {}} onAdd={() => {}} />);
        });

        expect(container.textContent).toContain('Planck’s constant');
        expect(container.textContent).toContain('AT&T <img src=x onerror="window.__lexiconXss=1">');
        expect(container.innerHTML).not.toContain('&amp;amp;');
        expect(container.innerHTML).not.toContain('&#8217;');
        expect(container.querySelector('img')).toBeNull();
        expect((window as { __lexiconXss?: number }).__lexiconXss).toBeUndefined();
        container.remove();
    });
});

it('names the languages in each term row and preserves an unknown locale', async () =>
{
    const container = document.createElement('div');
    const root = createRoot(container);
    await act(async () => {
        root.render(<LexiconTable
            rows={[{ id: 1, term: 'Brand', languages: ['en-us', 'he', 'unknown'], categories: [] }]}
            onEdit={jest.fn()} onDelete={jest.fn()} onAdd={jest.fn()}
        />);
    });
    const languages = container.querySelectorAll('tr')[1].cells[1];
    expect(languages.textContent).toContain('American English');
    expect(languages.textContent).toContain('Hebrew');
    expect(languages.textContent).toContain('unknown');
    expect(languages.querySelectorAll('[aria-hidden="true"]')).toHaveLength(3);
    await act(async () => { root.unmount(); });
});

it('names row actions and applies each action to its own term', async () =>
{
    const container = document.createElement('div');
    const root = createRoot(container);
    const onEdit = jest.fn();
    const onDelete = jest.fn();
    await act(async () => { root.render(<LexiconTable rows={[{ id: 'large-id', term: 'CODE-SNIPPETS', languages: [], categories: ['Brand'] }]} onEdit={onEdit} onDelete={onDelete} onAdd={jest.fn()} />); });
    await act(async () => { (container.querySelector('button[aria-label="Edit CODE-SNIPPETS"]') as HTMLButtonElement).click(); });
    await act(async () => { (container.querySelector('button[aria-label="Delete CODE-SNIPPETS"]') as HTMLButtonElement).click(); });
    expect(onEdit).toHaveBeenCalledWith('large-id');
    expect(onDelete).toHaveBeenCalledWith('large-id');
    await act(async () => { root.unmount(); });
});

afterAll(() =>
{
    const { renderToStaticMarkup } = require('react-dom/server.node');
    const rows = ['CODE-SNIPPETS', 'WooCommerce', 'Code Snippets'].map((term, id) => ({ id, term, categories: ['Brand'], languages: ['en', 'de', 'el', 'fr', 'it', 'es'] }));
    const markup = renderToStaticMarkup(<LexiconTable rows={rows} onEdit={() => {}} onDelete={() => {}} onAdd={() => {}} />);
    const scrollMarkup = renderToStaticMarkup(<Table style={{ minWidth: 524 }} header={<TableHeaderRow>
        <TableCell variant="header">Connection name</TableCell><TableCell variant="header">Languages and status</TableCell><TableCell variant="header"><span className="sr-only">Actions</span></TableCell>
    </TableHeaderRow>}><TableRow><TableCell>Shared table</TableCell><TableCell>English, German</TableCell><TableCell>Ready</TableCell></TableRow></Table>);
    const paths = ['src/apps/admin/components/Lexicon/LexiconTable/index.tsx', 'src/apps/admin/components/Lexicon/LexiconTable/style.css', 'src/shared/components/form/table/index.tsx'];
    const sourceHash = paths.reduce((hash, path) => hash.update(readFileSync(resolve(process.cwd(), path))), createHash('sha256')).digest('hex');
    const output = resolve(process.cwd(), 'e2e/.fixtures');
    mkdirSync(output, { recursive: true });
    writeFileSync(resolve(output, 'lexicon-table-markup.json'), JSON.stringify({ sourceHash, markup, scrollMarkup }));
});
