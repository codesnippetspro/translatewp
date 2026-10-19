import { useEffect, useId, useRef, useState } from "@wordpress/element";
import { TextControl, ColorPicker as WPColorPicker } from "@wordpress/components";
import { __ } from "@wordpress/i18n";

const HEX_COLOR = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/;

export const isHexColor = (value: string): boolean => HEX_COLOR.test(value.trim());

export interface ColorPickerProps {
    label: string;
    value: string;
    onChange: (value: string) => void;
}

export const ColorPicker = ({ label, value, onChange }: ColorPickerProps) =>
{
    const [isColorPickerOpen, setIsColorPickerOpen] = useState(false);
    const colorPickerRef = useRef<HTMLDivElement | null>(null);
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

    useEffect(() => {
        if (!isColorPickerOpen) {
            return;
        }

        const handlePointerDown = (event: MouseEvent) => {
            if (!colorPickerRef.current) {
                return;
            }

            if (!colorPickerRef.current.contains(event.target as Node)) {
                setIsColorPickerOpen(false);
            }
        };

        document.addEventListener("mousedown", handlePointerDown);
        return () => {
            document.removeEventListener("mousedown", handlePointerDown);
        };
    }, [isColorPickerOpen]);

    return (
        <div className="flex flex-col gap-2">
            <span className='text-sm font-bold text-translatewp-neutral'>{label}</span>
            <div className="flex items-center gap-2">
            <div
                ref={colorPickerRef}
                className="w-10 h-10 rounded-sm cursor-pointer relative border border-translatewp-border!"
                style={{ backgroundColor: value }}
                onClick={() => setIsColorPickerOpen(true)}
            >
                {isColorPickerOpen && (
                    <WPColorPicker color={value} onChange={(value) => onChange(value)} className="absolute bottom-12 right-0 border border-translatewp-border! rounded-sm! bg-white" style={{ positionArea: 'y-end' }} />
                )}
            </div>
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
                        {__('Use a hex color such as #0E3ECD.', 'translate')}
                    </span>
                )}
            </div>
            </div>
        </div>
    )
}