import { InspectorControls } from '@wordpress/block-editor';
import { registerBlockCollection, registerBlockType } from '@wordpress/blocks';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { createElement, type ComponentType } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

type ButtonBlockEditProps = {
    attributes: Record<string, unknown>;
    name: string;
    setAttributes: (attributes: Record<string, unknown>) => void;
};

type BlockType = {
    name: string;
};

export function buttonSwitcherProps(
    props: Record<string, unknown>,
    blockType: BlockType,
    attributes: Record<string, unknown>,
): Record<string, unknown>
{
    if (blockType.name !== 'core/button' || attributes.twpLanguageSwitcher !== true)
    {
        return props;
    }

    return {
        ...props,
        'data-twp-switcher': true,
    };
}

export function buttonSwitcherAttributes(
    settings: Record<string, unknown>,
    name: string,
): Record<string, unknown>
{
    if (name !== 'core/button')
    {
        return settings;
    }

    return {
        ...settings,
        attributes: {
            ...(settings.attributes as Record<string, unknown> ?? {}),
            twpLanguageSwitcher: {
                type: 'boolean',
                default: false,
            },
        },
    };
}

const withButtonSwitcherToggle = createHigherOrderComponent(
    (BlockEdit: ComponentType<ButtonBlockEditProps>) =>
        ({ attributes, name, setAttributes, ...props }: ButtonBlockEditProps) =>
        {
            if (name !== 'core/button')
            {
                return <BlockEdit attributes={attributes} name={name} setAttributes={setAttributes} {...props} />;
            }

            return (
                <>
                    <BlockEdit attributes={attributes} name={name} setAttributes={setAttributes} {...props} />
                    <InspectorControls>
                        <PanelBody title={__('TranslateWP', 'translate')}>
                            <ToggleControl
                                label={__('Convert to language switcher', 'translate')}
                                checked={attributes.twpLanguageSwitcher === true}
                                onChange={(twpLanguageSwitcher) => setAttributes({ twpLanguageSwitcher })}
                            />
                        </PanelBody>
                    </InspectorControls>
                </>
            );
        },
    'withButtonSwitcherToggle',
);

export function registerGutenbergSwitcher(): void
{
    addFilter('blocks.registerBlockType', 'translatewp/button-switcher-attribute', buttonSwitcherAttributes);
    addFilter('blocks.getSaveContent.extraProps', 'translatewp/button-switcher-placeholder', buttonSwitcherProps);

    registerBlockCollection('translatewp', {
        title: __('TranslateWP', 'translate'),
        icon: 'translation',
    });
    registerBlockType('translatewp/language-switcher', {
        title: __('Language Switcher', 'translate'),
        category: 'translatewp',
        icon: 'translation',
        attributes: {},
        edit: () => <div>{__('Language Switcher', 'translate')}</div>,
        save: () => null,
    });
    addFilter('editor.BlockEdit', 'translatewp/button-switcher-toggle', withButtonSwitcherToggle);
}
