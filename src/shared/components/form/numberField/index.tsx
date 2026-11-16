import { useEffect, useId, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { PointerEvent as ReactPointerEvent } from 'react';
import { clampNumberField, numberFieldDragValue } from './values';

export { numberFieldBounds } from './values';

export type NumberFieldProps = {
    label: string;
    value: number;
    min: number;
    max: number;
    step?: number;
    unit?: string;
    help?: string;
    disabled?: boolean;
    onChange: (value: number) => void;
    className?: string;
};

type Drag = { id: number; startY: number; value: number; moved: boolean };

export const NumberField = ({ label, value, min, max, step = 1, unit, help, disabled = false, onChange, className = '' }: NumberFieldProps) =>
{
    const id = useId();
    const input = useRef<HTMLInputElement>(null);
    const drag = useRef<Drag | null>(null);
    const [draft, setDraft] = useState<string | undefined>();
    const parsedDraft = draft === undefined || draft.trim() === '' ? value : Number(draft);
    const current = clampNumberField(Number.isFinite(parsedDraft) ? parsedDraft : value, min, max);
    const commit = (next: number) =>
    {
        const bounded = clampNumberField(next, min, max);
        setDraft(undefined);
        if (bounded !== value) onChange(bounded);
    };
    const finishPointer = (event: ReactPointerEvent<HTMLInputElement>) =>
    {
        if (drag.current?.id !== event.pointerId) return;
        drag.current = null;
        if (event.currentTarget.hasPointerCapture?.(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
    };
    useEffect(() => () =>
    {
        if (drag.current && input.current?.hasPointerCapture?.(drag.current.id))
            input.current.releasePointerCapture(drag.current.id);
        drag.current = null;
    }, []);

    return (
        <div className={`flex min-w-0 max-w-full flex-col gap-2 ${className}`} data-number-field>
            <label htmlFor={id} className="text-sm font-medium text-translatewp-neutral!">{label}</label>
            <div className="flex h-10 w-40 max-w-full items-stretch overflow-hidden rounded-sm border border-translatewp-border bg-white focus-within:border-translatewp-primary focus-within:ring-1 focus-within:ring-translatewp-primary">
                <button type="button" disabled={disabled || current <= min}
                    aria-label={sprintf(
                        /* translators: %s: number field label. */
                        __('Decrease %s', 'translate'), label
                    )}
                    className="flex w-9 shrink-0 items-center justify-center border-0 bg-transparent p-0 text-lg text-translatewp-neutral! enabled:cursor-pointer disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-2 focus-visible:outline-offset-[-3px]"
                    onClick={() => commit(current - step)}>
                    <span aria-hidden="true">−</span>
                </button>
                <div className="flex min-w-0 flex-1 items-center justify-center gap-1">
                    <input ref={input} id={id} type="number" min={min} max={max} step={step} disabled={disabled}
                        value={draft ?? value}
                        aria-describedby={help ? `${id}-help` : undefined}
                        aria-valuetext={unit ? `${draft ?? value} ${unit}` : undefined}
                        className="m-0! h-full! min-h-0! min-w-0! w-[4ch]! cursor-ns-resize! rounded-none! border-0! bg-transparent! p-0! text-center! text-sm! text-translatewp-neutral! shadow-none! outline-none! [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                        onChange={event => setDraft(event.target.value)}
                        onBlur={() => commit(current)}
                        onKeyDown={event =>
                        {
                            if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') return;
                            event.preventDefault();
                            commit(current + (event.key === 'ArrowUp' ? 1 : -1) * step * (event.shiftKey ? 10 : 1));
                        }}
                        onPointerDown={event =>
                        {
                            if (disabled || event.pointerType === 'touch' || event.button !== 0) return;
                            drag.current = { id: event.pointerId, startY: event.clientY, value: current, moved: false };
                            event.currentTarget.setPointerCapture?.(event.pointerId);
                        }}
                        onPointerMove={event =>
                        {
                            const active = drag.current;
                            if (!active || active.id !== event.pointerId) return;
                            const delta = active.startY - event.clientY;
                            if (!active.moved && Math.abs(delta) < 4) return;
                            active.moved = true;
                            event.preventDefault();
                            commit(numberFieldDragValue(active.value, delta, step, min, max));
                        }}
                        onPointerUp={finishPointer}
                        onPointerCancel={finishPointer}
                        onLostPointerCapture={() => { drag.current = null; }}
                    />
                    {unit && <span className="pointer-events-none text-sm text-translatewp-muted!" aria-hidden="true">{unit}</span>}
                </div>
                <button type="button" disabled={disabled || current >= max}
                    aria-label={sprintf(
                        /* translators: %s: number field label. */
                        __('Increase %s', 'translate'), label
                    )}
                    className="flex w-9 shrink-0 items-center justify-center border-0 bg-transparent p-0 text-lg text-translatewp-neutral! enabled:cursor-pointer disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-2 focus-visible:outline-offset-[-3px]"
                    onClick={() => commit(current + step)}>
                    <span aria-hidden="true">+</span>
                </button>
            </div>
            {help && <p id={`${id}-help`} className="m-0 text-sm text-translatewp-muted!">{help}</p>}
        </div>
    );
};
