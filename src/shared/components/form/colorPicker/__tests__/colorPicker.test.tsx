import { act, useState } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { ColorPicker, COLOR_POPOVER_PROPS, isHexColor } from '@/shared/components/form/colorPicker';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mountedRoots: Root[] = [];

const render = async (element: React.ReactElement): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    mountedRoots.push(root);
    await act(async () =>
    {
        root.render(element);
    });
    return container;
};

const typeInto = async (input: HTMLInputElement, value: string): Promise<void> =>
{
    await act(async () =>
    {
        const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
        setter?.call(input, value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });
};

describe('ColorPicker text field', () =>
{
    afterEach(async () =>
    {
        await act(async () =>
        {
            for (const root of mountedRoots.splice(0)) {
                root.unmount();
            }
        });
        document.body.innerHTML = '';
    });

    it('gives each editable color its own associated label', async () =>
    {
        const container = await render(<>
            <ColorPicker label="Text color" value="#343739" onChange={jest.fn()} />
            <ColorPicker label="Background color" value="#FFFFFF" onChange={jest.fn()} />
        </>);
        const inputs = Array.from(container.querySelectorAll('input'));
        expect(inputs).toHaveLength(2);
        expect(inputs.map(input => input.labels?.[0]?.textContent))
            .toEqual(['Text color', 'Background color']);
        expect(inputs[0].id).not.toBe(inputs[1].id);
    });

    it('accepts three, four, six and eight digit hex colours, including an alpha channel', () =>
    {
        expect(isHexColor('#0E3ECD')).toBe(true);
        expect(isHexColor('#fff')).toBe(true);
        expect(isHexColor(' #abcdef ')).toBe(true);
        expect(isHexColor('#0E3ECD80')).toBe(true);
        expect(isHexColor('#f0a8')).toBe(true);
        expect(isHexColor('ZZZZZZ')).toBe(false);
        expect(isHexColor('#12345')).toBe(false);
        expect(isHexColor('#1234567')).toBe(false);
        expect(isHexColor('red')).toBe(false);
    });

    it('anchors the popup to its swatch and flips and shifts to stay on screen', () =>
    {
        expect(COLOR_POPOVER_PROPS).toMatchObject({ placement: 'bottom-start', flip: true, shift: true, resize: true });
    });

    it('round-trips a semi-transparent hex value through the text field', async () =>
    {
        const onChange = jest.fn();
        const container = await render(<ColorPicker label="Background Color" value="#FFFFFF" onChange={onChange} />);
        const input = container.querySelector('input') as HTMLInputElement;

        await typeInto(input, '#0E3ECD80');

        expect(onChange).toHaveBeenLastCalledWith('#0E3ECD80');
        expect(container.querySelector('[role="alert"]')).toBeNull();
    });

    it.each([['#0E3ECD', '#0E3ECDff'], ['#abc', '#abcf']])(
        'stores an explicitly opaque alpha when entering %s', async (value, expected) =>
        {
            const onChange = jest.fn();
            const container = await render(<ColorPicker label="Background Color" value="#FFFFFF" onChange={onChange} />);

            await typeInto(container.querySelector('input') as HTMLInputElement, value);

            expect(onChange).toHaveBeenLastCalledWith(expected);
        },
    );

    it('keeps typed characters intact while the parent saves an explicit alpha', async () =>
    {
        const Controlled = () => {
            const [value, setValue] = useState('#FFFFFF');
            return <><ColorPicker label="Background" value={value} onChange={setValue} /><output>{value}</output></>;
        };
        const container = await render(<Controlled />);
        const input = container.querySelector('input') as HTMLInputElement;

        for (const value of ['#a', '#ab', '#abc', '#abcd', '#abcde', '#abcdef']) {
            await typeInto(input, value);
            expect(input.value).toBe(value);
        }
        expect(container.querySelector('output')?.textContent).toBe('#abcdefff');
    });

    it('opens an alpha-capable colour picker from the swatch', async () =>
    {
        const container = await render(<ColorPicker label="Background Color" value="#FFFFFF" onChange={jest.fn()} />);
        const swatch = container.querySelector('button[aria-haspopup="dialog"]') as HTMLButtonElement;

        await act(async () =>
        {
            swatch.click();
        });

        expect(document.querySelector('.components-color-picker')).not.toBeNull();
    });

    it('reports the open state to onOpenChange when the swatch toggles', async () =>
    {
        const onOpenChange = jest.fn();
        const container = await render(<ColorPicker label="Loading border color" value="#0E3ECD" onChange={jest.fn()} onOpenChange={onOpenChange} />);
        const swatch = container.querySelector('button[aria-haspopup="dialog"]') as HTMLButtonElement;

        await act(async () =>
        {
            swatch.click();
        });
        expect(onOpenChange).toHaveBeenLastCalledWith(true);

        await act(async () =>
        {
            swatch.click();
        });
        expect(onOpenChange).toHaveBeenLastCalledWith(false);
    });

    it('keeps a value that is not a hex colour out of the settings and shows the hint', async () =>
    {
        const onChange = jest.fn();
        const container = await render(<ColorPicker label="Background Color" value="#FFFFFF" onChange={onChange} />);
        const input = container.querySelector('input') as HTMLInputElement;

        await typeInto(input, 'ZZZZZZ');

        expect(onChange).not.toHaveBeenCalled();
        expect(container.querySelector('[role="alert"]')?.textContent).toBe('Use a hex color such as #0E3ECD or #0E3ECD80.');
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(input.getAttribute('aria-describedby')).toBe(container.querySelector('[role="alert"]')?.id);

        await typeInto(input, '#0E3ECD');

        expect(onChange).toHaveBeenLastCalledWith('#0E3ECDff');
        expect(container.querySelector('[role="alert"]')).toBeNull();
    });
});
