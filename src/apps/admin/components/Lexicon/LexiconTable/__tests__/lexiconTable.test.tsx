import { act } from 'react';
import { createRoot } from 'react-dom/client';

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
