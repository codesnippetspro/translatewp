import { act, useState } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { OptionCards } from '@/apps/admin/components/Languages/Switcher/OptionCards';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const OPTIONS = [
	{ value: 'first', label: 'First', description: 'The first choice.' },
	{ value: 'second', label: 'Second', description: 'The second choice.' },
];
let root: Root;
let container: HTMLDivElement;

beforeEach(() => {
	container = document.createElement('div');
	document.body.appendChild(container);
	root = createRoot(container);
});

afterEach(async () => {
	await act(async () => { root.unmount(); });
	container.remove();
});

it('names the group, exposes native selection and associates each option with its description', async () => {
	const onChange = jest.fn();
	await act(async () => {
		root.render(<OptionCards options={OPTIONS} label="Background" value="first" onChange={onChange} />);
	});
	expect(container.querySelector('[role="radiogroup"]')?.getAttribute('aria-label'))
		.toBe('Background');
	const inputs = Array.from(container.querySelectorAll<HTMLInputElement>('input[type="radio"]'));
	expect(inputs.map(input => input.checked)).toEqual([true, false]);
	for (const [index, input] of inputs.entries()) {
		expect(input.getAttribute('aria-label')).toBe(OPTIONS[index].label);
		expect(document.getElementById(input.getAttribute('aria-describedby')!)?.textContent)
			.toBe(OPTIONS[index].description);
	}
	await act(async () => { inputs[1].click(); });
	expect(onChange).toHaveBeenCalledWith('second');
});

it('keeps selections independent when two controls share the same diagnostic name', async () => {
	const Controlled = ({ label }: { label: string }) => {
		const [value, setValue] = useState('first');
		return <OptionCards options={OPTIONS} label={label} name="same" value={value} onChange={setValue} />;
	};
	await act(async () => {
		root.render(<><Controlled label="One" /><Controlled label="Two" /></>);
	});
	const inputs = Array.from(container.querySelectorAll<HTMLInputElement>('input[type="radio"]'));
	expect(inputs[0].name).not.toBe(inputs[2].name);
	await act(async () => { inputs[1].click(); });
	expect(inputs.map(input => input.checked)).toEqual([false, true, true, false]);
});

it('keeps a locked option disabled and does not select it', async () => {
	const onChange = jest.fn();
	await act(async () => {
		root.render(<OptionCards
			options={[OPTIONS[0], { ...OPTIONS[1], locked: { feature: 'Second' } }]}
			label="Background" value="first" onChange={onChange}
		/>);
	});
	const locked = container.querySelector<HTMLInputElement>('input[value="second"]')!;
	expect(locked.disabled).toBe(true);
	await act(async () => { locked.click(); });
	expect(onChange).not.toHaveBeenCalled();
});
