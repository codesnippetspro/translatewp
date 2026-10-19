import type { ComponentType, ReactNode } from '@wordpress/element';
import { act } from 'react';
import { createRoot } from 'react-dom/client';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@wordpress/blocks', () => ({ registerBlockType: jest.fn(), registerBlockCollection: jest.fn() }));
jest.mock('@wordpress/hooks', () => ({ addFilter: jest.fn() }));
jest.mock('@wordpress/components', () => ({
    PanelBody: ({ children }: { children: ReactNode }) => <div>{children}</div>,
    ToggleControl: ({ checked, onChange }: { checked: boolean, onChange: (value: boolean) => void }) => (
        <button type="button" aria-pressed={checked} onClick={() => onChange(!checked)}>toggle</button>
    ),
}));
jest.mock('@wordpress/block-editor', () => ({ InspectorControls: ({ children }: { children: ReactNode }) => <div>{children}</div> }));
jest.mock('@wordpress/compose', () => ({ createHigherOrderComponent: (callback: unknown) => callback }));
jest.mock('@wordpress/i18n', () => ({ __: (value: string) => value }));

import {
    buttonSwitcherAttributes,
    buttonSwitcherProps,
    registerGutenbergSwitcher,
} from '@/apps/editor/gutenbergSwitcher';

const { registerBlockType: mockRegisterBlockType, registerBlockCollection: mockRegisterBlockCollection } = jest.requireMock('@wordpress/blocks');
const { addFilter: mockAddFilter } = jest.requireMock('@wordpress/hooks');

type EditorWindow = Window & { TranslateWPEditor?: { inlinePlacements?: boolean } };

describe('Gutenberg switcher', () =>
{
    beforeEach(() =>
    {
        jest.clearAllMocks();
    });

    afterEach(() =>
    {
        delete (window as EditorWindow).TranslateWPEditor;
    });

    it.each([
        ['no editor data', undefined],
        ['an addon answering false', { inlinePlacements: false }],
        ['an addon answering true', { inlinePlacements: true }],
    ])('registers the TranslateWP collection, the block and the button toggle with %s', (_label, editorData) =>
    {
        (window as EditorWindow).TranslateWPEditor = editorData;
        registerGutenbergSwitcher();

        expect(mockRegisterBlockCollection).toHaveBeenCalledWith('translatewp', expect.objectContaining({ title: 'TranslateWP' }));
        expect(mockRegisterBlockType).toHaveBeenCalledWith('translatewp/language-switcher', expect.objectContaining({ title: 'Language Switcher' }));
        expect(mockAddFilter).toHaveBeenCalledWith('blocks.registerBlockType', 'translatewp/button-switcher-attribute', expect.any(Function));
        expect(mockAddFilter).toHaveBeenCalledWith('editor.BlockEdit', 'translatewp/button-switcher-toggle', expect.any(Function));
        expect(mockAddFilter).toHaveBeenCalledWith('blocks.getSaveContent.extraProps', 'translatewp/button-switcher-placeholder', expect.any(Function));
    });

    it('preserves button props while marking a toggled button as a switcher placeholder', () =>
    {
        expect(buttonSwitcherProps({ className: 'wp-block-button__link has-brand-color' }, { name: 'core/button' }, { twpLanguageSwitcher: true }))
            .toEqual(expect.objectContaining({ className: 'wp-block-button__link has-brand-color', 'data-twp-switcher': true }));
        expect(buttonSwitcherProps({ className: 'wp-block-button__link' }, { name: 'core/button' }, {}))
            .toEqual({ className: 'wp-block-button__link' });
    });

    it('persists the button marker through the saved block attributes', () =>
    {
        const savedAttributes = { twpLanguageSwitcher: true };
        const registeredAttributes = buttonSwitcherAttributes({ attributes: {} }, 'core/button').attributes;
        const savedProps = buttonSwitcherProps({}, { name: 'core/button' }, savedAttributes);

        expect(registeredAttributes).toEqual(expect.objectContaining({ twpLanguageSwitcher: { type: 'boolean', default: false } }));
        expect(savedProps).toEqual({ 'data-twp-switcher': true });
    });

    it('writes the switcher attribute when the button inspector toggle is clicked', () =>
    {
        registerGutenbergSwitcher();
        const blockEditFilter = mockAddFilter.mock.calls.find(([hook]: [string]) => hook === 'editor.BlockEdit')?.[2] as (blockEdit: ComponentType<unknown>) => ComponentType<{
            attributes: Record<string, unknown>;
            name: string;
            setAttributes: (attributes: Record<string, unknown>) => void;
        }>;
        const setAttributes = jest.fn();
        const ButtonEdit = blockEditFilter(() => <div>Button editor</div>);
        const container = document.createElement('div');
        const root = createRoot(container);

        act(() =>
        {
            root.render(<ButtonEdit attributes={{ twpLanguageSwitcher: false }} name="core/button" setAttributes={setAttributes} />);
        });
        act(() =>
        {
            container.querySelector('button')?.click();
        });

        expect(setAttributes).toHaveBeenCalledWith({ twpLanguageSwitcher: true });
        act(() => root.unmount());
    });
});
