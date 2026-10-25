import { Check } from "lucide-react";

export interface CheckboxGridProps {
    label: string;
    options: { label: string, value: string }[];
    selected: string[];
    columns?: number;
    help?: string;
    onChange: (value: string[]) => void;
}

export const CheckboxGrid = ({ label, options, selected, columns = 2, help, onChange }: CheckboxGridProps) =>
{
    const handleChange = (value: string) => {
        if (selected.includes(value)) {
            onChange(selected.filter((v) => v !== value));
        } else {
            onChange([...selected, value]);
        }
    }

    return (
        <div className="flex flex-col gap-4">
            {label && <span className="text-sm font-bold text-translatewp-neutral">{label}</span>}
            {help && <p className="m-0! text-sm! text-translatewp-muted!">{help}</p>}

            <div className="grid gap-6" style={{ gridTemplateColumns: `repeat(${columns}, 1fr)` }}>
                {options.map((option) => (
                    <label key={option.value} className="flex gap-2 items-center cursor-pointer">
                        <input
                            className="sr-only peer"
                            type="checkbox"
                            checked={selected.includes(option.value)}
                            onChange={() => handleChange(option.value)}
                        />
                        <div className="h-4 w-4 rounded-sm border border-translatewp-border flex items-center justify-center text-transparent peer-checked:border-translatewp-primary peer-checked:bg-translatewp-primary peer-checked:text-white peer-focus-visible:outline-2! peer-focus-visible:outline-solid! focus-visible:outline-solid! peer-focus-visible:outline-offset-2! peer-focus-visible:outline-translatewp-primary!">
                            <Check className="h-3 w-3" aria-hidden="true" />
                        </div>
                        <span className="text-sm text-translatewp-neutral">{option.label}</span>
                    </label>
                ))}
            </div>
        </div>
    );
}