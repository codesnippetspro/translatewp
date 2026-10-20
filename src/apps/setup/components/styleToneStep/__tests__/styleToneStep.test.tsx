import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { StyleToneStep } from '@/apps/setup/components/styleToneStep';
import { useSetupStore } from '@/apps/setup/stores/setupStore';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const roots: Root[] = [];

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    roots.push(root);
    await act(async () => { root.render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><StyleToneStep /></QueryClientProvider>); });
    return container;
};

const checked = (container: HTMLElement, group: string): string | undefined =>
    (container.querySelector(`[role="radiogroup"][aria-label="${group}"] input:checked`) as HTMLInputElement | null)?.value;

const values = (container: HTMLElement, group: string): string[] =>
    [...container.querySelectorAll(`[role="radiogroup"][aria-label="${group}"] input`)].map(input => (input as HTMLInputElement).value);

afterEach(async () =>
{
    await act(async () => { for (const root of roots.splice(0)) root.unmount(); });
    document.body.innerHTML = '';
});

describe('the style and tone step', () =>
{
    it('shows the stored slider values and lets the owner adjust them', async () =>
    {
        useSetupStore.setState({ formality: 8, creativity: 2 });
        const container = await render();
        const formality = container.querySelector('input[aria-label="Formality"]') as HTMLInputElement;
        const creativity = container.querySelector('input[aria-label="Precision vs fluency"]') as HTMLInputElement;

        expect(formality).not.toBeNull();
        expect(creativity).not.toBeNull();
        expect(formality.value).toBe('8');
        expect(creativity.value).toBe('2');
        await act(async () =>
        {
            Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set?.call(formality, '6');
            formality.dispatchEvent(new Event('input', { bubbles: true }));
        });
        expect(useSetupStore.getState().formality).toBe(6);
    });

    it('maps a new tone to its level while preserving a later manual adjustment', async () =>
    {
        useSetupStore.setState({ tone: 'friendly', formality: 8 });
        const container = await render();
        const neutral = container.querySelector('[role="radiogroup"][aria-label="Preset tone"] input[value="neutral"]') as HTMLInputElement;
        await act(async () => { neutral.click(); });
        expect(useSetupStore.getState().formality).toBe(5);
        await act(async () => { useSetupStore.getState().setFormality(3); });
        expect(useSetupStore.getState().formality).toBe(3);
    });

    it('keeps a saved Formal tone chosen, last, so a restarted setup does not change it', async () =>
    {
        await act(async () => { useSetupStore.setState({ tone: 'formal', formality: 9 }); });
        const container = await render();

        expect(values(container, 'Preset tone')).toEqual(['neutral', 'friendly', 'professional', 'casual', 'formal']);
        expect(checked(container, 'Preset tone')).toBe('formal');
        expect(useSetupStore.getState().formality).toBe(9);
    });

    it('preselects the style and tone the wizard holds', async () =>
    {
        await act(async () => { useSetupStore.setState({ style: 'technical', tone: 'formal' }); });
        const container = await render();

        expect(checked(container, 'Preset style')).toBe('technical');
        expect(checked(container, 'Preset tone')).toBe('formal');
    });

    it('offers every preset as a working choice', async () =>
    {
        await act(async () => { useSetupStore.setState({ style: 'neutral', tone: 'friendly' }); });
        const container = await render();

        expect(values(container, 'Preset style')).toEqual(['neutral', 'marketing', 'technical']);
        expect(values(container, 'Preset tone')).toEqual(['neutral', 'friendly', 'professional', 'casual']);
        expect([...container.querySelectorAll('[data-preset-locked]')].map(chip => chip.getAttribute('data-preset-locked'))).toEqual(['legal', 'medical', 'playful', 'luxury']);
        for (const row of container.querySelectorAll('[data-preset-pro-row]')) {
            expect((row.textContent?.match(/Pro/g) ?? []).length).toBe(1);
        }
        expect(container.querySelectorAll('[role="radiogroup"] input:disabled')).toHaveLength(0);

        const technical = container.querySelector('[role="radiogroup"][aria-label="Preset style"] input[value="technical"]') as HTMLInputElement;
        await act(async () => { technical.click(); });
        expect(useSetupStore.getState().style).toBe('technical');

        const casual = container.querySelector('[role="radiogroup"][aria-label="Preset tone"] input[value="casual"]') as HTMLInputElement;
        await act(async () => { casual.click(); });
        expect(useSetupStore.getState().tone).toBe('casual');
    });

    it('shows Custom locked with the Pro tag, once for style and once for tone', async () =>
    {
        const container = await render();

        const locked = [...container.querySelectorAll('[data-custom-locked]')];
        expect(locked).toHaveLength(2);
        for (const row of locked) {
            expect(row.textContent).toContain('Custom');
            expect(row.textContent).toContain('Pro');
            expect(row.querySelector('input')).toBeNull();
        }
    });

    it.each([
        [{ installed: false, active: false }, 'Install Pro'],
        [{ installed: true, active: false }, 'Activate Pro'],
    ])('on an account that includes Pro but not on this site (%o) offers %s instead of the Pro tag', async (addon, action) =>
    {
        (window as unknown as { translatewpPro?: unknown }).translatewpPro = { connected: true, entitled: true, version: '', upgradeUrl: '', ...addon };
        try {
            const container = await render();
            const rows = [...container.querySelectorAll('[data-preset-pro-row]')];
            expect(rows).toHaveLength(2);
            for (const row of rows) {
                const labelled = [...row.querySelectorAll('button')].map(button => button.textContent?.trim()).filter(Boolean);
                expect(labelled).toEqual([action]);
                expect((row.textContent?.match(/Pro/g) ?? []).length).toBe(1);
            }
        } finally {
            delete (window as unknown as { translatewpPro?: unknown }).translatewpPro;
        }
    });

    it('after Pro is installed from a locked preset, chooses that preset; without Pro it chooses nothing', async () =>
    {
        const { rememberPendingPreset } = await import('@/shared/lib/pro/pendingPreset');
        await act(async () => { useSetupStore.setState({ style: 'marketing', tone: 'friendly' }); });

        rememberPendingPreset({ kind: 'style', value: 'legal' });
        await render();
        expect(useSetupStore.getState().style).toBe('marketing');
        await act(async () => { for (const root of roots.splice(0)) root.unmount(); });

        (window as unknown as { translatewpPro?: unknown }).translatewpPro = { connected: true, entitled: true, installed: true, active: true, version: '1.0.0', upgradeUrl: '' };
        try {
            rememberPendingPreset({ kind: 'tone', value: 'luxury' });
            const container = await render();
            expect(useSetupStore.getState().tone).toBe('luxury');
            expect(checked(container, 'Preset tone')).toBe('luxury');
            expect(window.sessionStorage.getItem('translatewp-pending-pro-preset')).toBeNull();
        } finally {
            delete (window as unknown as { translatewpPro?: unknown }).translatewpPro;
        }
    });

    it('with Pro active offers the Orbital presets as working choices and never asks to update the plugin', async () =>
    {
        (window as unknown as { translatewpPro?: unknown }).translatewpPro = { connected: true, entitled: true, installed: true, active: true, version: '1.0.0', upgradeUrl: '' };
        const { addFilter } = await import('@wordpress/hooks');
        const Stale = () => <div>Pro panel</div>;
        (Stale as unknown as { hostVersion?: number }).hostVersion = 99;
        addFilter('translatewp.slot.translationStyle', 'test/stale', () => Stale);
        try {
            await act(async () => { useSetupStore.setState({ style: 'neutral', tone: 'friendly' }); });
            const container = await render();
            expect(container.textContent).not.toContain('Update TranslateWP');
            expect(container.textContent).not.toContain('Pro panel');
            expect(values(container, 'Preset style')).toEqual(['neutral', 'marketing', 'technical', 'legal', 'medical']);
            expect(container.querySelectorAll('[data-preset-locked]')).toHaveLength(0);
            const legal = container.querySelector('[role="radiogroup"][aria-label="Preset style"] input[value="legal"]') as HTMLInputElement;
            await act(async () => { legal.click(); });
            expect(useSetupStore.getState().style).toBe('legal');
        } finally {
            const { removeFilter } = await import('@wordpress/hooks');
            removeFilter('translatewp.slot.translationStyle', 'test/stale');
            delete (window as unknown as { translatewpPro?: unknown }).translatewpPro;
        }
    });
});
