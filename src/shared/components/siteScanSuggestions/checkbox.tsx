export const SuggestionCheckbox = ({ id, labelId, reasonId, checked, disabled, onChange }: {
    id: string; labelId: string; reasonId: string; checked: boolean; disabled: boolean;
    onChange: (checked: boolean) => void;
}) => (
    <input id={id} type="checkbox" aria-labelledby={labelId} aria-describedby={reasonId}
        className="m-0! mt-0.5! shrink-0 cursor-pointer" checked={checked} disabled={disabled}
        onChange={event => onChange(event.target.checked)} />
);
