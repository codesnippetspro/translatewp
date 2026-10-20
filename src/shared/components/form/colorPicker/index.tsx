import type { ComponentProps } from "react";
import { useId, useState } from "@wordpress/element";
import { TextControl, ColorPicker as WPColorPicker, Dropdown } from "@wordpress/components";
import { __ } from "@wordpress/i18n";

const HEX_COLOR = /^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/;

export const isHexColor = (value: string): boolean => HEX_COLOR.test(value.trim());

// The popup anchors to its swatch and floats above every overflow ancestor,
// flipping and sliding so it stays inside the viewport near the admin sidebar.
export const COLOR_POPOVER_PROPS: ComponentProps<typeof Dropdown>["popoverProps"] = {
    placement: "bottom-start",
    flip: true,
    shift: true,
    resize: true,
};

export interface ColorPickerProps {
    label: string;
    value: string;
    onChange: (value: string) => void;
    onOpenChange?: (open: boolean) => void;
}

export const ColorPicker = ({ label, value, onChange, onOpenChange }: ColorPickerProps) =>
{
    // The text field holds whatever is typed; only a hex colour reaches the settings.
    const [text, setText] = useState(value);
    const [lastValue, setLastValue] = useState(value);

    if (value !== lastValue) {
        setLastValue(value);
        setText(value);
    }

    const invalid = !isHexColor(text);
    const hintId = useId();

    const handleTextChange = (next: string) => {
        setText(next);
        if (isHexColor(next)) {
            onChange(next.trim());
        }
    };

    return (
        <div className="flex flex-col gap-2">
            <span className='text-sm font-bold text-translatewp-neutral'>{label}</span>
            <div className="flex items-center gap-2">
            <Dropdown
                className="shrink-0"
                popoverProps={COLOR_POPOVER_PROPS}
                onToggle={onOpenChange}
                renderToggle={({ isOpen, onToggle }) => (
                    <button
                        type="button"
                        aria-label={label}
                        aria-haspopup="dialog"
                        aria-expanded={isOpen}
                        className="w-10 h-10 rounded-sm cursor-pointer border border-translatewp-border!"
                        style={{ backgroundColor: value }}
                        onClick={onToggle}
                    />
                )}
                renderContent={() => (
                    <WPColorPicker
                        enableAlpha
                        color={value}
                        onChange={(next) => onChange(next)}
                    />
                )}
            />
            <div className="flex flex-col gap-1">
                <TextControl
                    __next40pxDefaultSize
                    __nextHasNoMarginBottom
                    className='[&_input]:border-translatewp-border! [&_input]:rounded-sm!'
                    value={text}
                    onChange={handleTextChange}
                    aria-invalid={invalid}
                    aria-describedby={invalid ? hintId : undefined}
                />
                {invalid && (
                    <span id={hintId} className="text-xs text-translatewp-error" role="alert">
                        {__('Use a hex color such as #0E3ECD or #0E3ECD80.', 'translate')}
                    </span>
                )}
            </div>
            </div>
        </div>
    )
}
