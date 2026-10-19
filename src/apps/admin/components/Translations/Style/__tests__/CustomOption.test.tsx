import { TextEncoder } from 'node:util';
import { renderToStaticMarkup } from 'react-dom/server.node';
import { jest } from '@jest/globals';
import { addFilter, removeFilter } from '@wordpress/hooks';

// polyfill: jsdom environment lacks TextEncoder which react-dom/server needs
if (typeof globalThis.TextEncoder === 'undefined')
{
    (globalThis as Record<string, unknown>).TextEncoder = TextEncoder;
}

let mockConnectStatus: { pro?: { entitled: boolean } } | undefined;

jest.mock('@tanstack/react-query', () => ({
    useQuery: () => ({ data: mockConnectStatus, isLoading: false }),
}));

jest.mock('@/shared/query-options/connect', () => ({
    getConnectStatusQueryOptions: () => ({ queryKey: ['connectStatus'] }),
}));

import { CustomOption } from '@/apps/admin/components/Translations/Style/CustomOption';

const FILTER_NAMESPACE = 'translatewp-tests/custom-option';
const RegisteredStylePanel = () => <div>Registered style panel</div>;
const RegisteredTonePanel = () => <div>Registered tone panel</div>;

describe.each([
    ['translationStyle', 'Custom style', 'Preset style', RegisteredStylePanel, 'Registered style panel'] as const,
    ['translationTone', 'Custom tone', 'Preset tone', RegisteredTonePanel, 'Registered tone panel'] as const,
])('CustomOption slot (%s)', (slot, feature, _label, RegisteredPanel, registeredMarkup) =>
{
    const filterName = `translatewp.slot.${slot}`;
    const presetLabel = slot === 'translationStyle' ? 'Marketing' : 'Professional';
    const renderOption = () => renderToStaticMarkup(
        <CustomOption
            feature={feature}
            slot={slot}
            kind={slot === 'translationStyle' ? 'style' : 'tone'}
            selected={slot === 'translationStyle' ? 'neutral' : 'friendly'}
            onChange={() => {}}
        />
    );

    afterEach(() =>
    {
        removeFilter(filterName, FILTER_NAMESPACE);
    });

    test('renders the preset radio and the ProLock stub when nothing is registered', () =>
    {
        mockConnectStatus = { pro: { entitled: false } };

        const html = renderOption();

        expect(html).toContain(presetLabel);
        expect(html).toContain(`Unlock ${feature} with PRO`);
        expect(html).not.toContain(registeredMarkup);
    });

    test('renders the preset radio and the ProLock stub when registered but the account is not entitled', () =>
    {
        addFilter(filterName, FILTER_NAMESPACE, () => RegisteredPanel);
        mockConnectStatus = { pro: { entitled: false } };

        const html = renderOption();

        expect(html).toContain(presetLabel);
        expect(html).toContain(`Unlock ${feature} with PRO`);
        expect(html).not.toContain(registeredMarkup);
    });

    test('renders only the registered panel (no duplicate preset radio) once entitled', () =>
    {
        addFilter(filterName, FILTER_NAMESPACE, () => RegisteredPanel);
        mockConnectStatus = { pro: { entitled: true } };

        const html = renderOption();

        expect(html).toContain(registeredMarkup);
        expect(html).not.toContain(`Unlock ${feature} with PRO`);
    });
});

describe.each([
    ['translationStyle', 'style', 'Custom style'] as const,
    ['translationTone', 'tone', 'Custom tone'] as const,
])('CustomOption layout (%s)', (slot, kind, feature) =>
{
    test('the presets render as one start-aligned segmented row', () =>
    {
        mockConnectStatus = { pro: { entitled: false } };

        const container = document.createElement('div');
        container.innerHTML = renderToStaticMarkup(
            <CustomOption feature={feature} slot={slot} kind={kind} selected="" onChange={() => {}} />
        );

        const groups = container.querySelectorAll('[role="radiogroup"]');
        expect(groups).toHaveLength(1);

        const group = groups[0] as HTMLElement;
        expect(group.className).toContain('flex-row');
        expect(group.className).toContain('flex-wrap');
        expect(group.className).toContain('justify-start');
        expect(group.className).not.toMatch(/grid-cols|justify-center|items-center|text-center|mx-auto/);
        expect(container.innerHTML).not.toContain('data-wp-component="VStack"');
        expect(group.querySelectorAll('input[type="radio"]').length).toBeGreaterThan(1);
    });
});
