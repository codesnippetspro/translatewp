import { Info } from 'lucide-react';

let counter = 0;

// An (i) that explains a figure or a card at once on hover or focus.
export const InfoTip = ({ text }: { text: string }) =>
{
    const id = `twp-info-tip-${++counter}`;

    return (
        <span className="group relative inline-flex cursor-help text-translatewp-icons" tabIndex={0} aria-describedby={id} data-info-tip>
            <Info size={14} aria-hidden="true" />
            <span
                id={id}
                role="tooltip"
                className="pointer-events-none invisible absolute bottom-full left-1/2 z-20 mb-2 w-72 -translate-x-1/2 rounded-sm bg-translatewp-neutral px-3 py-2 text-left text-xs font-normal normal-case tracking-normal text-white opacity-0 shadow-lg"
            >
                {text}
            </span>
        </span>
    );
};
