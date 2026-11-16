import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { act, useState } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { NumberField, numberFieldBounds, type NumberFieldProps } from '@/shared/components/form/numberField';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
let root: Root;
let container: HTMLDivElement;
let writes: jest.Mock;

const mount = async (props: Partial<NumberFieldProps> = {}, direction: 'ltr' | 'rtl' = 'ltr') =>
{
    document.documentElement.dir = direction;
    const Fixture = () =>
    {
        const [value, setValue] = useState(props.value ?? 14);
        return <NumberField label="Font size" min={10} max={32} unit="px" {...props} value={value}
            onChange={next => { writes(next); setValue(next); }} />;
    };
    await act(async () => { root.render(<Fixture />); });
    return container.querySelector('input')!;
};
const button = (verb: string) => container.querySelector<HTMLButtonElement>(`button[aria-label^="${verb} "]`)!;
const click = async (node: HTMLElement) => { await act(async () => { node.click(); }); };
const type = async (input: HTMLInputElement, value: string) =>
{
    await act(async () =>
    {
        Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
};
const blur = async (input: HTMLInputElement) => { await act(async () => { input.dispatchEvent(new FocusEvent('focusout', { bubbles: true })); }); };
const key = async (input: HTMLInputElement, name: string, shiftKey = false) =>
{
    const event = new KeyboardEvent('keydown', { bubbles: true, cancelable: true, key: name, shiftKey });
    await act(async () => { input.dispatchEvent(event); });
    expect(event.defaultPrevented).toBe(true);
};
const pointer = async (input: HTMLInputElement, name: string, y: number, pointerType = 'mouse') =>
{
    const event = new MouseEvent(name, { bubbles: true, cancelable: true, clientY: y, button: 0 });
    Object.defineProperties(event, { pointerId: { value: 7 }, pointerType: { value: pointerType } });
    await act(async () => { input.dispatchEvent(event); });
};
const capture = (input: HTMLInputElement) =>
{
    let captured = false;
    const set = jest.fn(() => { captured = true; });
    const release = jest.fn(() => { captured = false; });
    Object.assign(input, { setPointerCapture: set, hasPointerCapture: () => captured, releasePointerCapture: release });
    return { set, release };
};

beforeEach(() =>
{
    container = document.createElement('div');
    document.body.append(container);
    root = createRoot(container);
    writes = jest.fn();
});
afterEach(async () =>
{
    await act(async () => { root.unmount(); });
    container.remove();
    document.documentElement.removeAttribute('dir');
});

it('allows typing 18 over 14 and commits only on blur', async () =>
{
    const input = await mount();
    await type(input, '1');
    expect(input.value).toBe('1');
    await type(input, '18');
    expect(writes).not.toHaveBeenCalled();
    await blur(input);
    expect(writes.mock.calls).toEqual([[18]]);
    expect(input.value).toBe('18');
});

it.each([['5', 10], ['99', 32], ['', 14]] as const)('commits draft %s as %s on blur', async (draft, expected) =>
{
    const input = await mount();
    await type(input, draft);
    expect(writes).not.toHaveBeenCalled();
    await blur(input);
    expect(input.value).toBe(String(expected));
    expect(writes.mock.calls).toEqual(expected === 14 ? [] : [[expected]]);
});

it.each([[31, 'Increase', 32, 'Decrease', 30], [11, 'Decrease', 10, 'Increase', 12]] as const)(
    'clamps a step from %s and disables only the exhausted bound', async (value, action, bounded, reverse, next) =>
    {
        await mount({ value, step: 2 });
        await click(button(action));
        expect(writes.mock.calls).toEqual([[bounded]]);
        expect(button(action).disabled).toBe(true);
        expect(button(reverse).disabled).toBe(false);
        await click(button(action));
        expect(writes).toHaveBeenCalledTimes(1);
        await click(button(reverse));
        expect(writes.mock.calls).toEqual([[bounded], [next]]);
    },
);

it('commits arrow steps immediately and Shift changes ten steps in either direction', async () =>
{
    const input = await mount();
    await key(input, 'ArrowUp');
    await key(input, 'ArrowDown');
    await key(input, 'ArrowUp', true);
    await key(input, 'ArrowUp', true);
    expect(button('Increase').disabled).toBe(true);
    await key(input, 'ArrowDown', true);
    await key(input, 'ArrowDown', true);
    await key(input, 'ArrowDown', true);
    expect(button('Decrease').disabled).toBe(true);
    expect(writes.mock.calls).toEqual([[15], [14], [24], [32], [22], [12], [10]]);
    expect(input.value).toBe('10');
});

it.each(['Font size', 'Switcher icon size', 'Switcher padding', 'Switcher offset', 'Keep the choice for (days)'])(
    'names both actions and the native spinbutton for %s', async label =>
    {
        const input = await mount({ label, help: 'Field help' });
        expect(input.type).toBe('number');
        expect([input.min, input.max, input.step]).toEqual(['10', '32', '1']);
        expect(container.querySelector('label')!.htmlFor).toBe(input.id);
        expect(container.querySelector('label')!.textContent).toBe(label);
        expect(button('Decrease').getAttribute('aria-label')).toBe(`Decrease ${label}`);
        expect(button('Increase').getAttribute('aria-label')).toBe(`Increase ${label}`);
        expect(document.getElementById(input.getAttribute('aria-describedby')!)!.textContent).toBe('Field help');
        expect(input.getAttribute('aria-valuetext')).toBe('14 px');
        expect(writes).not.toHaveBeenCalled();
    },
);

it('keeps the approved disabled field inert', async () =>
{
    const input = await mount({ disabled: true });
    expect(input.disabled).toBe(true);
    expect(button('Decrease').disabled).toBe(true);
    expect(button('Increase').disabled).toBe(true);
    await click(button('Increase'));
    await pointer(input, 'pointerdown', 100);
    await pointer(input, 'pointermove', 60);
    expect(writes).not.toHaveBeenCalled();
});

it.each(['ltr', 'rtl'] as const)('inherits %s and keeps decrease at logical start and increase at logical end', async direction =>
{
    const input = await mount({}, direction);
    const control = button('Decrease').parentElement!;
    expect(control.closest('[dir]')!.getAttribute('dir')).toBe(direction);
    expect(control.firstElementChild).toBe(button('Decrease'));
    expect(control.lastElementChild).toBe(button('Increase'));
    expect(button('Decrease').compareDocumentPosition(input) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(input.compareDocumentPosition(button('Increase')) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
});

it('captures a mouse drag, keeps click-sized motion unchanged and releases on pointerup', async () =>
{
    const input = await mount();
    const { set, release } = capture(input);
    await pointer(input, 'pointerdown', 100);
    expect(set).toHaveBeenCalledWith(7);
    await pointer(input, 'pointermove', 97);
    expect(writes).not.toHaveBeenCalled();
    await pointer(input, 'pointermove', 84);
    expect(writes.mock.calls).toEqual([[18]]);
    await pointer(input, 'pointerup', 84);
    expect(release).toHaveBeenCalledWith(7);
    await pointer(input, 'pointermove', 60);
    expect(writes.mock.calls).toEqual([[18]]);
});

it.each(['pointercancel', 'lostpointercapture'])('stops the pen gesture after %s', async end =>
{
    const input = await mount();
    const { release } = capture(input);
    await pointer(input, 'pointerdown', 100, 'pen');
    await pointer(input, 'pointermove', 108, 'pen');
    expect(writes.mock.calls).toEqual([[12]]);
    await pointer(input, end, 108, 'pen');
    if (end === 'pointercancel') expect(release).toHaveBeenCalledWith(7);
    await pointer(input, 'pointermove', 60, 'pen');
    expect(writes.mock.calls).toEqual([[12]]);
});

it('leaves touch scrolling alone while the step buttons still work', async () =>
{
    const input = await mount();
    const { set } = capture(input);
    await pointer(input, 'pointerdown', 100, 'touch');
    await pointer(input, 'pointermove', 60, 'touch');
    await pointer(input, 'pointerup', 60, 'touch');
    expect(set).not.toHaveBeenCalled();
    expect(writes).not.toHaveBeenCalled();
    await click(button('Increase'));
    expect(writes.mock.calls).toEqual([[15]]);
});

afterAll(() =>
{
    const { renderToStaticMarkup } = require('react-dom/server.node');
    const markup = renderToStaticMarkup(<>
        <NumberField label="Font size" value={14} {...numberFieldBounds.fontSize} unit="px" onChange={() => {}} />
        <NumberField label="Keep the choice for (days)" value={30} {...numberFieldBounds.cookieDays} unit="days" onChange={() => {}} />
    </>);
    const sourceHash = createHash('sha256').update(readFileSync(resolve(__dirname, '../index.tsx')))
        .update(readFileSync(resolve(__dirname, '../values.ts'))).digest('hex');
    const output = resolve(process.cwd(), 'e2e/.fixtures');
    mkdirSync(output, { recursive: true });
    writeFileSync(resolve(output, 'number-field-markup.json'), JSON.stringify({ sourceHash, markup }));
});
