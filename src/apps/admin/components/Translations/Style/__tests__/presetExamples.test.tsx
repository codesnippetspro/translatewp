import { TextEncoder } from 'node:util';

if (typeof globalThis.TextEncoder === 'undefined')
{
    (globalThis as Record<string, unknown>).TextEncoder = TextEncoder;
}

import { act, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { PresetChoice } from '@/apps/admin/components/Translations/Style/PresetChoice';
import { PRESET_EXAMPLES } from '@/apps/admin/components/Translations/Style/presetExamples';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

const Harness = ({ kind, initial }: { kind: 'style' | 'tone'; initial: string }) =>
{
    const [selected, setSelected] = useState(initial);
    return <QueryClientProvider client={client}><PresetChoice kind={kind} selected={selected} onChange={setSelected} /></QueryClientProvider>;
};

const mount = (kind: 'style' | 'tone', initial: string) =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    act(() => root.render(<Harness kind={kind} initial={initial} />));
    return { container, unmount: () => act(() => root.unmount()) };
};

const choose = (container: HTMLElement, value: string) =>
{
    const input = container.querySelector<HTMLInputElement>(`input[type="radio"][value="${value}"]`);
    expect(input).not.toBeNull();
    act(() => input!.click());
};

describe('preset examples', () =>
{
    test('every preset style and tone has its own sample paragraph', () =>
    {
        const style = Object.values(PRESET_EXAMPLES.style);
        const tone = Object.values(PRESET_EXAMPLES.tone);
        expect(Object.keys(PRESET_EXAMPLES.style).sort()).toEqual(['legal', 'marketing', 'medical', 'neutral', 'technical']);
        expect(Object.keys(PRESET_EXAMPLES.tone).sort()).toEqual(['casual', 'formal', 'friendly', 'luxury', 'neutral', 'playful', 'professional']);
        for (const sample of [...style, ...tone])
        {
            expect(sample.length).toBeGreaterThan(20);
            expect(sample).not.toContain('\u2014');
        }
        expect(new Set(style).size).toBe(style.length);
        expect(new Set(tone).size).toBe(tone.length);
    });

    test('choosing Technical shows the Technical sample paragraph, and Legal stays locked', () =>
    {
        const { container, unmount } = mount('style', 'neutral');

        expect(container.textContent).toContain(PRESET_EXAMPLES.style.neutral);
        expect(container.textContent).not.toContain(PRESET_EXAMPLES.style.technical);

        choose(container, 'technical');

        expect(container.textContent).toContain(PRESET_EXAMPLES.style.technical);
        expect(container.textContent).not.toContain(PRESET_EXAMPLES.style.neutral);
        expect(container.querySelector('input[value="legal"]')).toBeNull();
        unmount();
    });

    test('Neutral is the first tone and Formal is no longer offered', () =>
    {
        const { container, unmount } = mount('tone', 'friendly');
        const values = Array.from(container.querySelectorAll<HTMLInputElement>('input[type="radio"]')).map(input => input.value);

        expect(values).toEqual(['neutral', 'friendly', 'professional', 'casual']);
        choose(container, 'neutral');
        expect(container.textContent).toContain(PRESET_EXAMPLES.tone.neutral);
        expect(container.textContent).not.toContain(PRESET_EXAMPLES.tone.friendly);
        unmount();
    });

    test('a site that saved Formal still sees it chosen, last, until it picks another tone', () =>
    {
        const { container, unmount } = mount('tone', 'formal');
        const values = () => Array.from(container.querySelectorAll<HTMLInputElement>('input[type="radio"]')).map(input => input.value);

        expect(values()).toEqual(['neutral', 'friendly', 'professional', 'casual', 'formal']);
        expect(container.querySelector<HTMLInputElement>('input[value="formal"]')?.checked).toBe(true);
        expect(container.textContent).toContain(PRESET_EXAMPLES.tone.formal);
        choose(container, 'neutral');
        expect(values()).toEqual(['neutral', 'friendly', 'professional', 'casual']);
        unmount();
    });

    test('an extra option without a sample (the Pro Custom row) shows no sample paragraph', () =>
    {
        const container = document.createElement('div');
        const root = createRoot(container);
        act(() => root.render(
            <QueryClientProvider client={client}><PresetChoice kind="style" selected="custom" onChange={() => {}} extraOptions={[{ label: 'Custom', value: 'custom' }]} /></QueryClientProvider>
        ));

        expect(container.querySelector('input[value="custom"]')).not.toBeNull();
        expect(container.querySelector('[data-preset-example]')).toBeNull();
        act(() => root.unmount());
    });
});
