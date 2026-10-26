import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { Table, TableCell, TableHeaderRow, TableRow } from '@/shared/components/form/table';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root;
let container: HTMLDivElement;

beforeEach(() => {
	container = document.createElement('div');
	root = createRoot(container);
});

afterEach(async () => { await act(async () => { root.unmount(); }); });

it('exposes native column headers and cells while keeping rich content inside its cell', async () => {
	await act(async () => {
		root.render(<Table>
			<TableHeaderRow>
				<TableCell variant="header">Language</TableCell>
				<TableCell variant="header">Actions</TableCell>
			</TableHeaderRow>
			<TableRow>
				<TableCell>Hebrew</TableCell>
				<TableCell as="div" className="flex justify-end">
					<button type="button">Edit Hebrew</button>
				</TableCell>
			</TableRow>
		</Table>);
	});
	const table = container.querySelector('table')!;
	expect(table.rows).toHaveLength(2);
	expect(Array.from(table.rows[0].cells).map(cell => [cell.tagName, cell.scope]))
		.toEqual([['TH', 'col'], ['TH', 'col']]);
	expect(Array.from(table.rows[1].cells).map(cell => cell.tagName)).toEqual(['TD', 'TD']);
	expect(table.rows[1].cells[1].querySelector('div > button')?.textContent).toBe('Edit Hebrew');
});

it('keeps an empty-state message in one cell spanning the data columns', async () => {
	await act(async () => {
		root.render(<Table>
			<TableRow><TableCell colSpan={4}>No languages</TableCell></TableRow>
		</Table>);
	});
	const cell = container.querySelector('td')!;
	expect(cell.colSpan).toBe(4);
	expect(cell.textContent).toBe('No languages');
});
