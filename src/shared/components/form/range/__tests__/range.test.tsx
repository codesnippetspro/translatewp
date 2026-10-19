import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { Range } from '@/shared/components/form/range';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let container: HTMLDivElement;
let root: Root;

beforeEach(() =>
{
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
});

afterEach(() =>
{
    act(() => root.unmount());
    container.remove();
});

const render = async (value: number, onChange: (value: number) => void = () => undefined) =>
{
    await act(async () =>
    {
        root.render(<Range label="Formality" beginningLabel="Informal" endingLabel="Formal" min={0} max={10} step={1} value={value} onChange={onChange} />);
    });
    return container.querySelector('input[type="range"]') as HTMLInputElement;
};

const setNativeValue = (input: HTMLInputElement, value: string) =>
{
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(input, value);
    input.dispatchEvent(new Event('input', { bubbles: true }));
};

describe('Range', () =>
{
    it('is a real slider with a thumb that can be dragged or moved with the keyboard', async () =>
    {
        const input = await render(4);

        expect(input).not.toBeNull();
        expect(input.min).toBe('0');
        expect(input.max).toBe('10');
        expect(input.step).toBe('1');
        expect(input.value).toBe('4');
        expect(input.getAttribute('aria-label')).toBe('Formality');
        expect(input.getAttribute('aria-valuetext')).toBe('4 of 10');
    });

    it('reports the value it is moved to as a number', async () =>
    {
        const onChange = jest.fn();
        const input = await render(4, onChange);

        await act(async () => setNativeValue(input, '7'));

        expect(onChange).toHaveBeenCalledWith(7);
    });

    it('fills the track up to the thumb', async () =>
    {
        const input = await render(5);

        expect(input.style.getPropertyValue('--twp-range-fill')).toBe('50%');
    });

    it('keeps its end labels', async () =>
    {
        await render(4);

        expect(container.textContent).toContain('Informal');
        expect(container.textContent).toContain('Formal');
    });
});
