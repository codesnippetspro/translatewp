import { act } from 'react';
import { createRoot, Root } from 'react-dom/client';

import { Icon } from '../index';
import { Lockup } from '../Lockup';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root;
let container: HTMLDivElement;

beforeEach(() => { container = document.createElement('div'); document.body.appendChild(container); root = createRoot(container); });
afterEach(() => { act(() => root.unmount()); container.remove(); });

const render = async (node: React.ReactNode) => { await act(async () => { root.render(node); }); };

describe('brand marks', () =>
{
    test('the mark draws the globe in cobalt and the star in amber', async () =>
    {
        await render(<Icon />);
        const svg = container.querySelector('svg[data-twp-mark="brand"]') as SVGElement;
        expect(svg.getAttribute('width')).toBe('36');
        const strokes = new Set(Array.from(svg.querySelectorAll('path[stroke]')).map(path => path.getAttribute('stroke')));
        expect([...strokes]).toEqual(['#0e3ecd']);
        expect(svg.querySelector('path[fill]')?.getAttribute('fill')).toBe('#EAAB5F');
        expect(svg.getAttribute('aria-hidden')).toBe('true');
    });

    test('in the switcher the mark takes the text colour', async () =>
    {
        await render(<Icon tone="current" size={24} />);
        const svg = container.querySelector('svg[data-twp-mark="current"]') as SVGElement;
        expect(svg.getAttribute('width')).toBe('24');
        for (const path of Array.from(svg.querySelectorAll('path[stroke]'))) expect(path.getAttribute('stroke')).toBe('currentColor');
        expect(svg.querySelector('path[fill]')?.getAttribute('fill')).toBe('currentColor');
    });

    test('the lockup keeps its proportions at the height asked and carries no ids', async () =>
    {
        await render(<Lockup height={32} />);
        const svg = container.querySelector('svg[data-twp-lockup]') as SVGElement;
        expect(svg.getAttribute('height')).toBe('32');
        expect(svg.getAttribute('viewBox')).toBe('0 0 830.8 165');
        expect(svg.querySelectorAll('[id]')).toHaveLength(0);
        expect(svg.getAttribute('aria-hidden')).toBe('true');
    });
});
